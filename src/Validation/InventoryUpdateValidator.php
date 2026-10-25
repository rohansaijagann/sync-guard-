<?php

declare(strict_types=1);

namespace SyncGuard\Validation;

use DateTimeImmutable;
use DateTimeZone;
use SyncGuard\Support\Time;
use SyncGuard\Support\Uuid;

/**
 * Class InventoryUpdateValidator
 *
 * Validates and normalizes front-desk inventory update requests.
 * Enforces strict business invariants: calendar reality, hotel capacity limits,
 * Japanese Yen whole-number rules, and Tokyo timezone boundary checks.
 */
class InventoryUpdateValidator
{
    /**
     * Normalize incoming raw payload before validation.
     *
     * Japanese hotel staff often enter numbers using full-width IME characters (e.g. １２３).
     * We convert full-width digits to standard ASCII half-width digits using mb_convert_kana('n'),
     * trim whitespace, and convert empty strings to null.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $normalized = [];

        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                } else {
                    // Convert Zenkaku (full-width) numbers to Hankaku (half-width) ASCII numbers
                    $value = mb_convert_kana($value, 'n', 'UTF-8');
                }
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Validate an inventory update payload.
     *
     * @param array<string, mixed> $input Raw or pre-normalized input
     * @param array<string, mixed>|null $room Room record from database, or null if nonexistent
     * @return array{
     *     valid: bool,
     *     errors: array<string, string>,
     *     data: array<string, mixed>
     * }
     */
    public function validate(array $input, ?array $room): array
    {
        $data = $this->normalize($input);
        $errors = [];

        // 1. Room existence validation
        $roomId = $data['room_id'] ?? null;
        if ($roomId === null || !is_numeric($roomId) || (int) $roomId <= 0) {
            $errors['room_id'] = 'Please select a valid room.';
        } elseif ($room === null) {
            $errors['room_id'] = 'The selected room does not exist.';
        } else {
            $data['room_id'] = (int) $roomId;
        }

        // 2. Idempotency Key validation (Must be strict UUID v4)
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if (!Uuid::isValid($idempotencyKey)) {
            $errors['idempotency_key'] = 'A valid UUID v4 idempotency key is required.';
        } else {
            $data['idempotency_key'] = strtolower((string) $idempotencyKey);
        }

        // 3. Calendar dates validation (date_from and date_to)
        $dateFromStr = $data['date_from'] ?? null;
        $dateToStr = $data['date_to'] ?? null;

        $dateFromObj = null;
        $dateToObj = null;

        if ($dateFromStr === null) {
            $errors['date_from'] = 'Start date (date_from) is required.';
        } else {
            $dateFromObj = $this->parseStrictDate((string) $dateFromStr);
            if ($dateFromObj === null) {
                $errors['date_from'] = 'Start date must be a valid calendar date in Y-m-d format.';
            }
        }

        if ($dateToStr === null) {
            $errors['date_to'] = 'End date (date_to) is required.';
        } else {
            $dateToObj = $this->parseStrictDate((string) $dateToStr);
            if ($dateToObj === null) {
                $errors['date_to'] = 'End date must be a valid calendar date in Y-m-d format.';
            }
        }

        // If both dates are syntactically valid calendar dates, check logical constraints
        if ($dateFromObj !== null && $dateToObj !== null) {
            $todayTokyo = Time::todayInTokyo();

            // date_from cannot be in the past according to hotel operational timezone (Asia/Tokyo)
            if ($dateFromStr < $todayTokyo) {
                $errors['date_from'] = "Start date cannot be in the past (today in Tokyo is {$todayTokyo}).";
            }

            // date_from cannot be after date_to
            if ($dateFromStr > $dateToStr) {
                $errors['date_to'] = 'End date cannot be earlier than start date.';
            } else {
                // Check date range limit: at most 366 days (1 year including leap years)
                $diff = $dateFromObj->diff($dateToObj);
                if ($diff->days > 366) {
                    $errors['date_to'] = 'Date range cannot exceed 366 days.';
                }
            }
        }

        // 4. At least ONE of available_units or price must be specified
        $unitsProvided = isset($data['available_units']) && $data['available_units'] !== null;
        $priceProvided = isset($data['price']) && $data['price'] !== null;

        if (!$unitsProvided && !$priceProvided) {
            $msg = 'At least one of available units or price must be provided.';
            $errors['available_units'] = $msg;
            $errors['price'] = $msg;
        }

        // 5. Available units validation (whole number: 0 <= units <= room.total_units)
        if ($unitsProvided) {
            $rawUnits = $data['available_units'];
            if (!is_numeric($rawUnits) || (string) (int) $rawUnits !== (string) $rawUnits || (int) $rawUnits < 0) {
                $errors['available_units'] = 'Available units must be a non-negative whole number (0 or greater).';
            } else {
                $unitsInt = (int) $rawUnits;
                if ($room !== null && $unitsInt > (int) $room['total_units']) {
                    $errors['available_units'] = sprintf(
                        'Available units (%d) cannot exceed maximum capacity for "%s" (%d units).',
                        $unitsInt,
                        $room['name'],
                        (int) $room['total_units']
                    );
                } else {
                    $data['available_units'] = $unitsInt;
                }
            }
        } else {
            $data['available_units'] = null;
        }

        // 6. Price validation (whole yen only: 0 <= price <= 9,999,999)
        /**
         * WHY JPY WHOLE NUMBERS IN DECIMAL(10,2):
         * The Japanese Yen (JPY) does not use fractional currency subdivisions (sen and rin
         * were demonetized by the Small Currency Disposal and Fractional Rounding Act of 1953).
         * Hotel room rates in Japan are strictly whole yen integers.
         * Enforcing whole numbers in validation eliminates fractional rounding bugs, while
         * the database schema uses standard DECIMAL(10,2) to accommodate standard financial
         * column types (e.g. 12000.00).
         */
        if ($priceProvided) {
            $rawPrice = $data['price'];
            // Reject any decimal point with fractional values (e.g. 8500.50)
            if (is_numeric($rawPrice)) {
                $priceFloat = (float) $rawPrice;
                $priceInt = (int) $rawPrice;

                // Check if has fractional cents/sen
                if (floor($priceFloat) !== $priceFloat) {
                    $errors['price'] = 'Japanese Yen (JPY) does not have decimal fractions. Please enter a whole yen amount.';
                } elseif ($priceInt < 0 || $priceInt > 9999999) {
                    $errors['price'] = 'Price must be a whole yen amount between ¥0 and ¥9,999,999.';
                } else {
                    // Normalized to decimal string format: e.g. "12000.00"
                    $data['price'] = number_format($priceInt, 2, '.', '');
                }
            } else {
                $errors['price'] = 'Price must be a valid whole yen amount.';
            }
        } else {
            $data['price'] = null;
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
            'data'   => $data,
        ];
    }

    /**
     * Strictly parse a date string in Y-m-d format.
     * Prevents PHP DateTime rollover (e.g., rejecting 2026-02-31 which normally rolls into March).
     *
     * @param string $dateStr
     * @return DateTimeImmutable|null
     */
    private function parseStrictDate(string $dateStr): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return null;
        }

        $d = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        if ($d === false) {
            return null;
        }

        $lastErrors = DateTimeImmutable::getLastErrors();
        if ($lastErrors !== false && ($lastErrors['warning_count'] > 0 || $lastErrors['error_count'] > 0)) {
            return null;
        }

        // Guarantees calendar validity (e.g. 2026-02-31 != 2026-03-03)
        if ($d->format('Y-m-d') !== $dateStr) {
            return null;
        }

        return $d;
    }
}
