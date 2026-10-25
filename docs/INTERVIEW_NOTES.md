# Sync Guard — Technical Interview Notes & System Design Reference

This document prepares the engineer to present and defend the architecture, trade-offs, and implementation decisions behind Sync Guard during engineering interviews.

---

## 1. The 30-Second Elevator Pitch

> "Sync Guard is an asynchronous multi-channel hotel inventory distribution engine built with modern plain PHP 8.2 and MySQL. It solves the classic hospitality problem of room overselling and rate drift across Online Travel Agencies (OTAs). When hotel front-desk staff update room availability, Sync Guard commits the update and child distribution jobs in a single database transaction, executes retries using exponential backoff with full jitter across heterogeneous channel adapters, detects 'at-risk' rooms dynamically, and provides complete operational observability without adding external broker dependencies."

---

## 2. The 2-Minute Architectural Deep Dive

> "When designing Sync Guard, our top priority was zero-loss reliability and complete operational simplicity. 
> 
> Most distribution engines fail because of the 'dual-write' problem: saving an inventory change to a database and publishing an event to a queue broker like RabbitMQ or SQS can fail halfway through. In Sync Guard, we eliminate that entirely by using MySQL as both our storage engine and our queue. The `inventory_updates` table and all child `sync_jobs` are created inside a single ACID transaction (`src/Services/InventoryService.php:88-120`). If the database commits, the jobs are guaranteed to exist.
> 
> Our worker process is decoupled from the web application. It runs via CLI (`bin/worker.php`), continuously polls due jobs via `findDue()`, and claims each job using an atomic conditional `UPDATE` query (`src/Repositories/JobRepository.php:186-200`) verifying `rowCount() === 1`. For each job, it invokes a channel-specific adapter implementing `ChannelAdapter` (`src/Adapters/ChannelAdapter.php`). Site A uses standard REST JSON with Bearer tokens; Site B uses XML with HMAC-SHA256 signatures; Site C simulates strict rate limits and unpredictable network drops.
> 
> When a channel fails, our backoff engine (`src/Retry/Backoff.php:33-109`) applies a 4-tier exponential schedule—60 seconds, 5 minutes, 15 minutes, 1 hour—plus randomized jitter (+/- 10%) to prevent thundering herd spikes. If 5 attempts fail, the job enters terminal dead-letter status. Crucially, while a channel is struggling, our system flags those specific room types as 'At Risk' on the dashboard dynamically (`src/Services/StatusService.php:274-300`) so hotel staff know availability is desynchronized. Operators can inspect attempts, simulate chaos in staging, and trigger one-click manual retries once partner services recover."

---

## 3. The 3-Minute Live Demo Script

1. **Clean State (0:00 - 0:30):** Open `/index.php`. Show that all 3 partner channels report 100% success rate, 0 consecutive failures, and 0 rooms at risk. Point out the live worker heartbeat pill.
2. **Standard Update (0:30 - 1:00):** Navigate to `/update.php`. Pick 'Deluxe Ocean View / 富士の間', use the 'Next 7 days' date preset, set units to 4, rate to ¥18,000, and save. Show the success notification with correlation ID and explain that 3 channel jobs were spawned atomically.
3. **Simulate Channel Outage (1:00 - 1:45):** Open the Chaos Simulation Drawer on the dashboard. Switch 'Site C' to `always_fail`. Submit an inventory update. Trigger a worker pass. Watch Site C's health drop to DEGRADED, and watch the room immediately flag as **At Risk** because Sites A and B succeeded while Site C failed.
4. **Dead-Letter & Backoff (1:45 - 2:20):** Fast-forward through retry windows. Show the channel degrade to DOWN (RED), and the job transition from `failed` to `dead` after 5 attempts. Open `/log.php?status=dead`, click 'Details', and walk through the stored attempt telemetry (HTTP status codes, latencies, timestamps).
5. **Recovery & Re-sync (2:20 - 3:00):** In the simulation drawer, restore Site C to `always_succeed`. Click the 'Retry' button in the sync log table. Run a worker pass. Watch the dead job transition to `success`, the channel health recover to GREEN, and the 'At Risk' room counter clear back to zero.

---

## 4. 25 Technical Interview Questions & Answers

### System Design & Concurrency

#### Q1: Why use MySQL as a queue instead of Redis or RabbitMQ?
**A:** MySQL provides atomic single-transaction commits between domain models (`inventory_updates`) and queue records (`sync_jobs`). With an external broker, you face the dual-write problem (needing transactional outbox patterns, Debezium, or distributed two-phase commits). For a hotel property handling hundreds of updates an hour, MySQL queue tables easily handle the throughput while reducing operational infrastructure to zero.  
**Code Reference:** [`src/Services/InventoryService.php:88-120`](../src/Services/InventoryService.php#L88-L120) (`InventoryService::createUpdate`).

#### Q2: How do you prevent two worker processes from picking up the same job simultaneously?
**A:** Job claiming is performed via a single atomic conditional `UPDATE` query:
```sql
UPDATE sync_jobs
SET status = 'in_progress', updated_at = UTC_TIMESTAMP()
WHERE id = :id
  AND status IN ('pending', 'failed')
  AND next_attempt_at <= UTC_TIMESTAMP();
```
The claiming worker checks `$stmt->rowCount() === 1`. The winning worker proceeds; any losing worker gets `rowCount() === 0` and skips the job immediately. It does **not** use OS `flock` and does **not** use `SKIP LOCKED`.  
**Code Reference:** [`src/Repositories/JobRepository.php:186-200`](../src/Repositories/JobRepository.php#L186-L200) (`JobRepository::claim`).

#### Q3: What is the "dual-write" problem, and how does your architecture avoid it?
**A:** The dual-write problem occurs when an application must update a database and publish an event to an external messaging system (e.g. RabbitMQ). If either operation fails or crashes halfway, the system enters an inconsistent state (phantom updates or lost queue messages). Sync Guard eliminates this by recording both the update in `inventory_updates` and the child channel tasks in `sync_jobs` inside a single ACID database transaction.  
**Code Reference:** [`src/Services/InventoryService.php:88-120`](../src/Services/InventoryService.php#L88-L120).

#### Q4: Why did you implement exponential backoff with full jitter instead of fixed intervals?
**A:** Fixed intervals cause synchronized repeat traffic spikes when an external partner recovers, known as the thundering herd problem. Our backoff engine uses a 4-tier canonical schedule (60s, 300s, 900s, 3600s) decorrelated with `+/- RETRY_JITTER_PERCENT` (default 10%) randomized jitter. The 5th failed attempt permanently marks the job `dead`.  
**Code Reference:** [`src/Retry/Backoff.php:33-109`](../src/Retry/Backoff.php#L33-L109) (`Backoff::decide`).

#### Q5: How is room "At-Risk" state calculated?
**A:** It is calculated dynamically at query time: a room is defined as **At Risk** if there are unsynchronized jobs (`status != 'success'`, i.e. `pending`, `in_progress`, `failed`, or `dead`) on active channels for that room. We deliberately avoid storing an `is_at_risk` boolean flag on the `rooms` table to prevent stale cache bugs and distributed state synchronization drift.  
**Code Reference:** [`src/Services/StatusService.php:274-300`](../src/Services/StatusService.php#L274-L300) (`StatusService::calculateAtRiskRooms`).

### Database & Performance

#### Q6: How does the schema handle queue table bloat over time?
**A:** In high-volume production, historical jobs and attempts should be partitioned by month (`PARTITION BY RANGE (created_at)`) or periodically archived into an archival table (e.g. `sync_jobs_archive`) after 30 to 90 days.

#### Q7: What indexes are critical for the queue's performance?
**A:**
- `sync_jobs`: `(status, next_attempt_at)` index `idx_due` for fast polling of ready jobs.
- `sync_jobs`: `UNIQUE KEY uq_update_channel (update_id, channel_id)` preventing duplicate tasks for the same update.
- `inventory_updates`: `(idempotency_key)` UNIQUE constraint for deduplication.
- `sync_attempts`: `(job_id)` foreign key index for fast attempt history modal lookups.

#### Q8: What is the throughput capacity of this MySQL-based queue?
**A:** Throughput is bounded by MySQL InnoDB row-level locking latency. Because `JobRepository::findDue` uses the composite index `(status, next_attempt_at)` and `JobRepository::claim` targets rows directly by primary key `id`, polling and claims execute via fast index lookups with minimal row lock duration, easily handling the operational volume of independent hotel properties.

#### Q9: What happens if the database connection drops during worker execution?
**A:** The worker wraps individual job dispatches in discrete passes and catches `PDOException` in `bin/worker.php:156-159`. Upon a connection error, it resets the database connection singleton (`Connection::reset()`) and sleeps before retrying. Any in-progress job left stranded is recovered on subsequent passes via `JobRepository::recoverStuck(int $stuckThresholdSeconds = 300)`.

#### Q10: How do you prevent deadlocks in MySQL during high concurrent updates?
**A:** We ensure a strictly deterministic operation and locking order:
1. Intake updates always insert into `inventory_updates` first, followed by child rows in `sync_jobs` ordered by `channel_id ASC`.
2. Telemetry and attempt finalization exclusively locks the channel health row using `SELECT channel_id FROM channel_health WHERE channel_id = ? FOR UPDATE`.  
**Code Reference:** [`src/Repositories/HealthRepository.php:48-64`](../src/Repositories/HealthRepository.php#L48-L64) (`HealthRepository::lockChannel`).

### Networking & External Channels

#### Q11: How do you handle third-party partner rate limits?
**A:** Each channel adapter encapsulates its own protocol constraints. When an adapter receives an HTTP 429 Too Many Requests, it extracts the `Retry-After` header if present, adjusts the job's `next_attempt_at` timestamp accordingly, and returns a retryable failure.

#### Q12: How do your channel adapters guarantee idempotency?
**A:** Idempotency is driven by the client-generated UUID `idempotency_key` stored on `inventory_updates`. Adapters forward this key in the outbound HTTP request payload/headers so partner OTAs deduplicate duplicate transmissions. Delivery is **at-least-once**, made completely safe by idempotency. HMAC-SHA256 signatures (`X-Signature: sha256=...`) authenticate request payload integrity against tampering and are a completely separate mechanism from idempotency.

#### Q13: What happens if a partner channel responds with a slow 30-second timeout?
**A:** Our cURL client enforces strict connection timeouts (5 seconds) and read timeouts (10 seconds). Slow responses are terminated, recorded as a `timeout` failure in `sync_attempts`, and scheduled for exponential backoff without hanging worker threads indefinitely.

#### Q14: How does the system handle schema differences between OTAs?
**A:** We use the Adapter Pattern via `ChannelAdapter`. The background worker passes the generic inventory update and channel record to the adapter's `push()` method. The adapter formats it into REST JSON, XML, or custom partner structures.  
**Code Reference:** [`src/Adapters/ChannelAdapter.php:14-24`](../src/Adapters/ChannelAdapter.php#L14-L24) (`interface ChannelAdapter`).

#### Q15: Why are mock booking sites built directly into the repository?
**A:** They allow complete, zero-dependency local simulation of real-world failure modes: network latency, 500 errors, rate limits, and authentication failures, enabling fully verifiable test suites and demonstrations without third-party sandboxes.

### Resilience & Failure Modes

#### Q16: What is a "dead-letter" job and how is it resolved?
**A:** A job that has failed 5 consecutive attempts across all exponential backoff windows (60s, 300s, 900s, 3600s) is marked `dead`. It ceases automatic retries to avoid wasting worker resources. Operators can inspect the full error history in `/log.php?status=dead` and click 'Retry' once the partner confirms recovery.  
**Code Reference:** [`src/Retry/Backoff.php:70-72`](../src/Retry/Backoff.php#L70-L72).

#### Q17: How is channel health calculated?
**A:** Channel health is evaluated dynamically across a rolling window of the last 50 sync attempts (`DEFAULT_WINDOW_ATTEMPTS = 50`) using `HealthService::labelFor`:
1. `no_data`: Zero attempts recorded.
2. `down`: `consecutive_failures >= 5`.
3. `degraded`: `consecutive_failures >= 2`, OR (`consecutive_failures >= 1 AND success_rate < 90.0%`).
4. `recovering`: `consecutive_failures == 0`, `success_rate < 90.0%`, and `recentSuccessStreak < 5`.
5. `healthy`: `success_rate >= 90.0%` with 0 consecutive failures, OR `recentSuccessStreak >= 5`.  
*Note: The `circuit_state` and `circuit_opened_at` columns exist in `channel_health` as legacy schema but are completely unused (no circuit breaker is implemented).*  
**Code Reference:** [`src/Services/HealthService.php:30-99`](../src/Services/HealthService.php#L30-L99).

#### Q18: What happens if the worker process crashes mid-batch?
**A:** Jobs currently `in_progress` have an `updated_at` timestamp. If a worker terminates abnormally, subsequent worker passes detect stale locked jobs (`JobRepository::recoverStuck(int $stuckThresholdSeconds = 300)`) where `updated_at < UTC_TIMESTAMP() - INTERVAL :seconds SECOND` and reset them to `pending` with `next_attempt_at = UTC_TIMESTAMP()`.  
**Code Reference:** [`src/Repositories/JobRepository.php:127-147`](../src/Repositories/JobRepository.php#L127-L147) (`JobRepository::recoverStuck`).

#### Q19: What stops an operator from accidentally retrying a job that is already running?
**A:** The manual retry endpoint (`/api/retry.php`) executes an atomic conditional update:
```sql
UPDATE sync_jobs
SET status = 'pending', next_attempt_at = UTC_TIMESTAMP(), last_error = NULL
WHERE id = :id AND status IN ('dead', 'failed');
```
If a job is currently `in_progress` or already `success`, zero rows are affected and the request is rejected safely.  
**Code Reference:** [`src/Repositories/JobRepository.php:269-284`](../src/Repositories/JobRepository.php#L269-L284) (`JobRepository::requeue`).

#### Q20: How does the system ensure hotel staff are alerted to desynchronization?
**A:** The operational dashboard features an immediate visual warning banner, updates channel status pills, and marks individual room cards with an amber **At Risk** badge when availability differs across sites.

### Security, Scalability & Engineering Trade-offs

#### Q21: What are the intentional architectural trade-offs in this project?
**A:**
1. Zero-framework plain PHP 8.2 instead of Laravel/Symfony (prioritizes architectural transparency and runtime performance).
2. Omission of user authentication, RBAC, sessions, and audit logging (documented educational scope).
3. CSRF tokens replaced with origin verification (`X-Requested-With` and `Host == Origin`) for local simulation.
4. No circuit breaker implemented (`circuit_*` columns exist in schema but are unused).
5. MySQL-backed queue instead of AMQP (trades extreme throughput for transactional atomicity).
6. Single-threaded worker pass (processes jobs sequentially per batch).

#### Q22: How would you scale Sync Guard to 1,000 hotel properties?
**A:**
1. Multi-tenant database partitioning (`tenant_id` on all core tables).
2. Migrate from single-worker passes to stateless containerized worker pools competing for queued jobs via atomic conditional `UPDATE` claims.
3. Introduce Redis for caching channel health telemetry and rate-limit token buckets.
4. Partition `sync_jobs` and `sync_attempts` by month with automated archival.

#### Q23: How do you enforce code quality and prevent regressions?
**A:**
1. Automated CI pipeline on GitHub Actions running on PHP 8.2 and Docker.
2. PHPStan Level 5 static analysis for type safety (`phpstan.neon`).
3. PHP_CodeSniffer enforcing PSR-12 coding style (`phpcs.xml`).
4. Isolated custom CLI test runner (`bin/run_tests.php`).

#### Q24: What security defenses are present despite omitting a full login system?
**A:**
- Prepared SQL statements with PDO throughout (zero raw concatenation).
- Strict input validation with `InventoryUpdateValidator` (range checks, date formatting, type assertions).
- Anti-tampering origin and AJAX header checks on sensitive simulation endpoints.
- HTML entity encoding (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`) on all template outputs.

#### Q25: What is the single most valuable lesson learned while engineering this system?
**A:**
> "In distributed systems integrating with third-party APIs, network failures and partner downtime are not edge cases—they are everyday normal behavior. Designing for idempotency, backoff decorrelation, and clear operator observability from day one is far more critical than raw throughput."

---

## 5. Memory Checklist for Interview Day

- [ ] **Core Tech Stack:** PHP 8.2 (Strict Types), MySQL 8.0 (InnoDB UTC), Vanilla ES6+ JS, CSS Variables, Docker Compose.
- [ ] **Key Tables:** `rooms`, `channels`, `inventory_updates`, `sync_jobs`, `sync_attempts`, `channel_health`.
- [ ] **Job States:** `pending` $\rightarrow$ `in_progress` $\rightarrow$ `success` / `failed` (re-tryable) $\rightarrow$ `dead` (operator action).
- [ ] **Claim Mechanism:** Atomic conditional `UPDATE sync_jobs SET status = 'in_progress', updated_at = UTC_TIMESTAMP() WHERE id = :id AND status IN ('pending', 'failed') AND next_attempt_at <= UTC_TIMESTAMP()` checking `rowCount() === 1`. (NO `flock`, NO `SKIP LOCKED`).
- [ ] **Backoff Schedule:** 60s, 300s, 900s, 3600s with `+/- 10%` jitter. 5th failure = `dead`.
- [ ] **Health Window:** Rolling 50-attempt window; thresholds: `no_data` (0), `down` ($\ge 5$ failures), `degraded` ($\ge 2$ failures or $\ge 1$ and $<90\%$), `recovering` ($<90\%$ with streak $<5$), `healthy` ($\ge 90\%$ or streak $\ge 5$). (Circuit breaker is NOT implemented).
- [ ] **Idempotency vs HMAC:** Idempotency is client-generated UUID on update + UNIQUE constraint, forwarded to partner sites. HMAC-SHA256 signatures authenticate request payload integrity as a separate mechanism.
- [ ] **Delivery Guarantee:** At-least-once delivery, made safe by idempotency.

---

## 6. Honest AI Collaboration Disclosure

This codebase was developed through human-directed AI pair programming. Architectural framing, domain modeling, requirements boundaries, test invariants, and review standards were driven by the engineer, while repetitive boilerplate, PSR-12 formatting, and multi-file refactoring were accelerated through agentic pairing. Static analysis (PHPStan L5) and integration test suites verify 100% of runtime behavior.
