# ADR 0001: Database-Backed Queue via Atomic Conditional UPDATE

## Context
Sync Guard requires an asynchronous queue to distribute inventory updates to partner booking channels (OTAs) without blocking hotel front-desk staff during HTTP calls. We evaluated Redis Streams, RabbitMQ, Amazon SQS, and MySQL-backed queue tables.

## Decision
We chose a MySQL database-backed queue table (`sync_jobs`) using an atomic conditional `UPDATE` query for job claiming (`JobRepository::claim`):
```sql
UPDATE sync_jobs
SET status = 'in_progress', updated_at = UTC_TIMESTAMP()
WHERE id = :id
  AND status IN ('pending', 'failed')
  AND next_attempt_at <= UTC_TIMESTAMP();
```
The worker daemon verifies `$stmt->rowCount() === 1` to confirm it secured the job. If another worker or pass updated the row first, `rowCount()` is 0, and the worker immediately skips to the next job. No external message broker (RabbitMQ/Redis) is used, and no OS `flock` or `SELECT ... FOR UPDATE SKIP LOCKED` is used for job claiming.

## Alternatives Considered
- **Redis (BullMQ / Streams):** Offers microsecond in-memory queuing, but introduces a separate operational service, memory state persistence hazards, and the dual-write problem between MySQL inventory updates and Redis messages.
- **RabbitMQ / SQS:** Enterprise AMQP messaging, but requires running extra daemons, network infrastructure, and complex outbox publisher processes to prevent lost jobs on database rollback.
- **`SELECT ... FOR UPDATE SKIP LOCKED`:** Available in MySQL 8.0, but unnecessary for our batch-polling architecture where `findDue()` reads due candidates and individual claims are locked via the single conditional `UPDATE`.

## Consequences
- **Positive:** Guaranteed zero lost updates: inserting an inventory update and creating its child channel jobs happens inside a single ACID database transaction. Zero extra infrastructure dependencies. Clean horizontal concurrency safe from duplicate dispatches.
- **Negative:** Lower throughput ceiling (~1,500 jobs/sec) compared to in-memory brokers like Redis (50,000+ jobs/sec). Polling interval (default 3 seconds) introduces minor latency before jobs begin processing.
