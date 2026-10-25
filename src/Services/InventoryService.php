<?php

declare(strict_types=1);

namespace SyncGuard\Services;

use PDO;
use PDOException;
use SyncGuard\Repositories\ChannelRepository;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Repositories\RoomRepository;
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Support\Uuid;
use SyncGuard\Validation\InventoryUpdateValidator;
use Throwable;

/**
 * Class InventoryService
 *
 * Core business orchestration for hotel inventory rate and availability updates.
 *
 * KEY ARCHITECTURAL CONCEPTS:
 * 1. TRANSACTIONAL OUTBOX PATTERN:
 *    When staff modify room availability, the update row and one delivery job per active channel
 *    are committed together inside a single database transaction. This prevents orphaned records
 *    or missed deliveries if a process terminates prematurely.
 *
 * 2. DATABASE-LEVEL IDEMPOTENCY (UNIQUE constraint vs check-then-insert):
 *    A "check-then-insert" pattern (SELECT -> IF NOT EXISTS -> INSERT) fails under concurrent requests
 *    due to a race condition (TOCTOU). Two simultaneous requests can both pass the check.
 *    The UNIQUE constraint on `inventory_updates.idempotency_key` guarantees that the database engine
 *    atomically enforces uniqueness at the storage engine level.
 *
 * 3. ZERO-CHANNELS INVARIANT:
 *    If no active booking channels exist in the system, an update must be rejected with 409 Conflict.
 *    Saving an inventory update that cannot be dispatched to any channel creates silent data drift
 *    between the internal hotel system and external OTAs.
 */
class InventoryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RoomRepository $roomRepo,
        private readonly ChannelRepository $channelRepo,
        private readonly UpdateRepository $updateRepo,
        private readonly JobRepository $jobRepo,
        private readonly InventoryUpdateValidator $validator
    ) {
    }

    /**
     * Process and record a staff inventory update.
     *
     * @param array<string, mixed> $input Raw input payload
     * @return array{
     *     ok: bool,
     *     statusCode: int,
     *     data?: array<string, mixed>,
     *     error?: string,
     *     fields?: array<string, string>
     * }
     */
    public function createUpdate(array $input): array
    {
        // 1. Normalize input first so full-width digits (e.g. １２３) are converted to standard ASCII digits
        $normalizedInput = $this->validator->normalize($input);

        // 2. Look up the room to validate bounds against total physical capacity
        $roomId = isset($normalizedInput['room_id']) && is_numeric($normalizedInput['room_id'])
            ? (int) $normalizedInput['room_id']
            : 0;
        $room = $this->roomRepo->findById($roomId);

        // 3. Validate all business invariants
        $validation = $this->validator->validate($normalizedInput, $room);
        if (!$validation['valid']) {
            return [
                'ok'         => false,
                'statusCode' => 422,
                'error'      => 'Validation failed. Please correct the highlighted errors.',
                'fields'     => $validation['errors'],
            ];
        }

        $data = $validation['data'];
        $correlationId = Uuid::v4();

        // 3. Execute in ONE database transaction
        try {
            $this->pdo->beginTransaction();

            // Guard: Check active channels
            $activeChannels = $this->channelRepo->getActiveChannels();
            if (empty($activeChannels)) {
                $this->pdo->rollBack();
                return [
                    'ok'         => false,
                    'statusCode' => 409,
                    'error'      => 'No active booking channels are configured. Updates cannot be queued for delivery.',
                    'fields'     => [],
                ];
            }

            // Step A: Insert inventory_updates record
            $updateId = $this->updateRepo->insert([
                'room_id'         => $data['room_id'],
                'date_from'       => $data['date_from'],
                'date_to'         => $data['date_to'],
                'available_units' => $data['available_units'],
                'price'           => $data['price'],
                'idempotency_key' => $data['idempotency_key'],
                'correlation_id'  => $correlationId,
            ]);

            // Step B: Insert one queued sync job per active channel
            $channelIds = array_map(fn(array $c) => (int) $c['id'], $activeChannels);
            $jobsQueued = $this->jobRepo->createJobsForUpdate($updateId, $channelIds);

            // Commit atomic transaction
            $this->pdo->commit();

            $channelNames = array_map(fn(array $c) => (string) $c['name'], $activeChannels);

            return [
                'ok'         => true,
                'statusCode' => 201,
                'data'       => [
                    'update_id'      => $updateId,
                    'correlation_id' => $correlationId,
                    'jobs_queued'    => $jobsQueued,
                    'channels'       => $channelNames,
                    'duplicate'      => false,
                    'message'        => "Saved. {$jobsQueued} sites queued.",
                ],
            ];
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Check if this error was caused by a duplicate idempotency key (MySQL error 1062, SQLSTATE 23000)
            $isDuplicateKey = $e->getCode() === '23000'
                || ($e->errorInfo[1] ?? 0) === 1062
                || str_contains($e->getMessage(), 'idempotency_key');

            if ($isDuplicateKey) {
                return $this->handleDuplicateIdempotencyKey($data);
            }

            // Log unexpected database error with correlation ID for server diagnostics
            error_log(sprintf('[%s] Inventory update DB error: %s', $correlationId, $e->getMessage()));

            return [
                'ok'         => false,
                'statusCode' => 500,
                'error'      => 'An unexpected database error occurred. Please try again.',
                'fields'     => [],
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            error_log(sprintf('[%s] Inventory update unexpected error: %s', $correlationId, $e->getMessage()));

            return [
                'ok'         => false,
                'statusCode' => 500,
                'error'      => 'An unexpected server error occurred. Please try again.',
                'fields'     => [],
            ];
        }
    }

    /**
     * Handle the case where MySQL rejected the insert due to an identical idempotency_key.
     *
     * @param array<string, mixed> $submittedData The normalized incoming data
     * @return array{ok: bool, statusCode: int, data?: array<string, mixed>, error?: string, fields?: array<string, string>}
     */
    private function handleDuplicateIdempotencyKey(array $submittedData): array
    {
        $existing = $this->updateRepo->findByIdempotencyKey($submittedData['idempotency_key']);

        if ($existing === null) {
            // Highly improbable edge case, but handled defensively
            return [
                'ok'         => false,
                'statusCode' => 409,
                'error'      => 'This request ID was already used for an update.',
                'fields'     => [],
            ];
        }

        // Compare submitted payload with the stored record
        $isSameRoom = (int) $existing['room_id'] === (int) $submittedData['room_id'];
        $isSameFrom = (string) $existing['date_from'] === (string) $submittedData['date_from'];
        $isSameTo   = (string) $existing['date_to'] === (string) $submittedData['date_to'];

        $existingUnits = $existing['available_units'] !== null ? (int) $existing['available_units'] : null;
        $submittedUnits = $submittedData['available_units'] !== null ? (int) $submittedData['available_units'] : null;
        $isSameUnits = $existingUnits === $submittedUnits;

        $existingPrice = $existing['price'] !== null ? (float) $existing['price'] : null;
        $submittedPrice = $submittedData['price'] !== null ? (float) $submittedData['price'] : null;
        $isSamePrice = $existingPrice === $submittedPrice;

        if ($isSameRoom && $isSameFrom && $isSameTo && $isSameUnits && $isSamePrice) {
            // Submitted payload is identical: return 200 with original result (idempotent replay)
            $existingJobs = $this->jobRepo->getByUpdateId((int) $existing['id']);
            $channelNames = array_map(fn(array $j) => (string) ($j['channel_name'] ?? 'Channel'), $existingJobs);

            return [
                'ok'         => true,
                'statusCode' => 200,
                'data'       => [
                    'update_id'      => (int) $existing['id'],
                    'correlation_id' => (string) $existing['correlation_id'],
                    'jobs_queued'    => count($existingJobs),
                    'channels'       => $channelNames,
                    'duplicate'      => true,
                    'message'        => 'Already saved, nothing changed.',
                ],
            ];
        }

        // Payload differs with same idempotency key: 409 Conflict
        return [
            'ok'         => false,
            'statusCode' => 409,
            'error'      => 'This request ID was already used for a different update.',
            'fields'     => [],
        ];
    }

    /**
     * Retrieve the last N updates for display.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getRecentUpdates(int $limit = 10): array
    {
        return $this->updateRepo->getRecent($limit);
    }
}
