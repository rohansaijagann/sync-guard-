# Sync Guard

[![CI Pipeline](https://github.com/rohansaijagann/sync-guard-/actions/workflows/ci.yml/badge.svg)](https://github.com/rohansaijagann/sync-guard-/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%20(Strict%20Types)-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![Static Analysis](https://img.shields.io/badge/PHPStan-Level%205%20Passed-blue)](https://phpstan.org/)
[![Style](https://img.shields.io/badge/Code%20Style-PSR--12-success)](https://www.php-fig.org/psr/psr-12/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

> **Asynchronous multi-channel hotel inventory distribution engine with zero-loss transactional queuing, exponential backoff with jitter, dynamic at-risk drift detection, and full operational telemetry.**

*A simplified model of a real-world problem, built for learning. All booking sites are mock servers.*

---

![Sync Guard High-Level Architecture](docs/images/architecture.png)
*High-level system architecture showing the four primary subsystems: (1) Web Application Layer, (2) MySQL Transactional Queue, (3) Autonomous Background Worker, and (4) Booking-Site Adapters.*

---

## 1. See It Working: The Complete Failure & Recovery Lifecycle

When third-party Online Travel Agencies (OTAs) experience network drops or partner outages, room availability drifts out of sync across channels, creating the risk of double-bookings. Sync Guard isolates channel failures, schedules retries across exponential backoff windows, alerts staff with **At Risk** indicators, and provides one-click recovery.

### Continuous Walkthrough Animation

![Sync Guard Complete Failure and Recovery Walkthrough](docs/screenshots/failure-demo.gif)

*Download or stream the full high-definition video: [failure-demo.mp4](docs/screenshots/failure-demo.mp4) (H.264, 302 KB).*

---

### The Story in 6 Chapters

#### Chapter 1: Staff Change Availability
Hotel front-desk staff open the update portal to modify room allocations and nightly rates. The form includes quick presets ("Next 7 days", "This weekend") and requires a client-generated UUIDv4 idempotency key. Client-side validation stops malformed inputs before reaching the network, and valid saves confirm immediate persistence.

| Baseline Empty Dashboard | Room Update Form Filled | Client Validation Summary | Update Successfully Saved |
| :---: | :---: | :---: | :---: |
| ![Baseline Empty Dashboard](docs/screenshots/01-dashboard-empty.png) | ![Room Update Form Filled](docs/screenshots/02-update-form-filled.png) | ![Client Validation Summary](docs/screenshots/03-update-form-validation.png) | ![Update Successfully Saved](docs/screenshots/04-update-saved.png) |

#### Chapter 2: Updates Are Queued and Delivered
The update and 3 child channel distribution jobs commit atomically inside a single MySQL transaction. The real-time delivery log shows all jobs queued in `pending` state. When the background worker daemon runs, it claims jobs atomically and delivers them across active channels, transitioning health metrics to 100% across the board.

| Sync Log Showing Pending Jobs | Active Worker Delivering | Healthy Dashboard Status |
| :---: | :---: | :---: |
| ![Sync Log Showing Pending Jobs](docs/screenshots/05-sync-log-pending.png) | ![Active Worker Delivering](docs/screenshots/06-dashboard-delivering.png) | ![Healthy Dashboard Status](docs/screenshots/07-dashboard-healthy.png) |

#### Chapter 3: What Each Booking Site Received
Sync Guard integrates with heterogeneous booking sites via dedicated adapters. Site A receives REST JSON with Bearer token authentication, Site B processes XML with HMAC-SHA256 signature verification, and Site C enforces rate limits and bearer token validation. Each mock partner server inspects and persists received inventory allocations.

| Site A (REST JSON) | Site B (XML + HMAC-SHA256) | Site C (Token + Rate Limits) |
| :---: | :---: | :---: |
| ![Site A Partner Portal](docs/screenshots/08-mock-site-a.png) | ![Site B Partner Portal](docs/screenshots/09-mock-site-b.png) | ![Site C Partner Portal](docs/screenshots/10-mock-site-c.png) |

#### Chapter 4: A Site Starts Failing
When Site C experiences an outage (HTTP 503 Service Unavailable), the worker records the failure telemetry. Sync Guard immediately marks the channel as **Degraded** on the dashboard and flags the affected room as **At Risk** because Sites A and B are in sync while Site C is lagging behind. The failed job is scheduled for future retry using exponential backoff with jitter.

| Incident Banner and Degraded Health | Filtered Sync Log Showing Failed Job |
| :---: | :---: |
| ![Incident Banner and Degraded Health](docs/screenshots/11-dashboard-incident.png) | ![Filtered Sync Log Showing Failed Job](docs/screenshots/12-sync-log-failed.png) |

#### Chapter 5: Retries, Dead Jobs, and Manual Retry
If Site C remains down through 5 consecutive attempts across backoff windows (60s, 300s, 900s, 3600s), the channel drops to **Down** (red alert) and the job transitions to `dead` state. Automated retries cease to protect the queue from storming. Hotel staff can open the attempt telemetry drawer to inspect every HTTP status code and latency, and trigger a one-click manual retry once the partner resolves the incident.

| Channel Down and Critical Alert | Stored Attempt Telemetry Drawer | One-Click Manual Retry Dispatched |
| :---: | :---: | :---: |
| ![Channel Down and Critical Alert](docs/screenshots/13-dashboard-critical.png) | ![Stored Attempt Telemetry Drawer](docs/screenshots/14-sync-log-dead-details.png) | ![One-Click Manual Retry Dispatched](docs/screenshots/15-retry-done.png) |

#### Chapter 6: Recovery
Once Site C is restored to healthy operation, the retried job delivers successfully. The worker marks the job `success`, the channel clears its consecutive failure count, and the rolling health score returns to healthy. The **At Risk** counter automatically returns to zero, confirming that all three booking channels are once again completely in sync.

| Fully Recovered Dashboard |
| :---: |
| ![Fully Recovered Dashboard](docs/screenshots/16-dashboard-recovered.png) |

---

### Additional Interface Views & Dashboard Components

<details>
<summary><strong>Click to expand additional screenshots (Light Theme, Mobile Layouts, Wallboard Mode, and Analytics)</strong></summary>

| View | Screenshot | Description |
| :--- | :---: | :--- |
| **Light Theme** | ![Light Theme Dashboard](docs/screenshots/17-dashboard-light.png) | Full WCAG AA contrast-compliant light theme for daylight front-desk workstations. |
| **Mobile Dashboard** | ![Mobile Dashboard View](docs/screenshots/18-dashboard-mobile.png) | Responsive single-column operational view on mobile viewports (390px). |
| **Mobile Update Form** | ![Mobile Update Form](docs/screenshots/19-update-form-mobile.png) | Touch-optimized unit stepper controls and quick date presets on mobile. |
| **Mobile Sync Log** | ![Mobile Sync Log](docs/screenshots/20-sync-log-mobile.png) | Horizontally scrollable job telemetry table with sticky identification badges. |
| **Wallboard Mode** | ![High-Contrast Wallboard Mode](docs/screenshots/21-dashboard-wall-mode.png) | High-visibility TV display mode with enlarged metrics for hotel back-office walls. |
| **Consistency Matrix** | ![Channel Consistency Section](docs/screenshots/22-section-consistency.png) | Real-time cross-channel inventory consistency audit component. |
| **14-Day Calendar** | ![14-Day Calendar Matrix](docs/screenshots/23-section-calendar.png) | Multi-room inventory allocation matrix showing daily units and pricing. |
| **Delivery Sparkline** | ![Delivery Volume Sparkline](docs/screenshots/24-section-chart.png) | Real-time delivery throughput and success rate sparkline chart. |
| **Activity Feed** | ![Live Activity Feed](docs/screenshots/25-section-activity.png) | Real-time audit log of recent queue operations with correlation IDs. |
| **Simulation Drawer** | ![Chaos Simulation Drawer](docs/screenshots/26-section-demo-panel.png) | Interactive controls to inject channel outages, latency, and fast-forward timers. |

</details>

---

## 2. How It Works

![Job Lifecycle State Machine](docs/images/job-lifecycle.png)
*Job lifecycle state machine showing transitions from pending to in_progress, success, retryable failed, and dead.*

### Delivery Sequence

```mermaid
sequenceDiagram
    autonumber
    actor Staff as Front-Desk Staff
    participant UI as Browser (Update Portal)
    participant API as /api/inventory.php
    participant DB as MySQL Database
    participant Worker as Background Worker
    participant Adapter as Channel Adapter
    participant Partner as Partner OTA (Site A/B/C)

    Staff->>UI: Save room update (Units: 4, Rate: ¥18,000)
    UI->>API: POST /api/inventory.php (with idempotency_key UUID)
    API->>DB: BEGIN TRANSACTION (InventoryService::createUpdate)
    API->>DB: INSERT into inventory_updates (enforces UNIQUE idempotency_key)
    API->>DB: INSERT into sync_jobs (1 job per active channel)
    API->>DB: COMMIT TRANSACTION
    API-->>UI: 201 Created (Correlation ID, update_id, jobs_queued)

    Note over Worker,DB: Worker pass triggered (CLI daemon loop or --once)
    Worker->>DB: findDue(): SELECT * FROM sync_jobs WHERE next_attempt_at <= UTC_TIMESTAMP()
    loop For each due job
        Worker->>DB: claim(): UPDATE sync_jobs SET status='in_progress' WHERE id=:id AND status IN ('pending','failed') AND next_attempt_at <= UTC_TIMESTAMP()
        alt Claim Succeeded (rowCount === 1)
            Worker->>Adapter: push(update, channel)
            Adapter->>Partner: HTTP POST (Payload + idempotency_key + HMAC signature)
            alt Partner Responds 200 OK
                Partner-->>Adapter: 200 OK
                Adapter-->>Worker: PushResult::success()
                Worker->>DB: finalizeJob(): UPDATE sync_jobs SET status='success' + INSERT sync_attempts (outcome='success')
            else Partner Error / 500 / Timeout
                Partner-->>Adapter: HTTP Error / Timeout
                Adapter-->>Worker: PushResult::httpError() or timeout()
                Worker->>DB: Backoff::decide(attempt_count + 1)
                alt Attempts < 5
                    Worker->>DB: finalizeJob(): UPDATE sync_jobs SET status='failed', next_attempt_at=..., attempt_count=...
                else Attempts = 5
                    Worker->>DB: finalizeJob(): UPDATE sync_jobs SET status='dead', attempt_count=5
                end
                Worker->>DB: INSERT sync_attempts (outcome, duration_ms, error)
            end
            Worker->>DB: Recompute channel_health rolling window (FOR UPDATE row lock)
        else Claim Lost (rowCount === 0)
            Note over Worker: Skip job (another worker claimed it)
        end
    end
```

### The 8-Step Synchronization Flow

1. **Staff Submit Update:** Front-desk staff select room types, date ranges, unit counts, and nightly rates via `/update.php`.
2. **Atomic Outbox Persistence:** The API (`/api/inventory.php`) saves the `inventory_updates` row and all child `sync_jobs` (one per active channel) inside a single ACID database transaction.
3. **Atomic Conditional Claim:** The CLI background worker (`bin/worker.php`) queries due jobs and claims each job using a single conditional `UPDATE` query matching `id`, `status IN ('pending', 'failed')`, and `next_attempt_at <= UTC_TIMESTAMP()`. Exactly one worker wins via `rowCount() === 1`.
4. **Adapter Dispatch with Strict Timeout:** The worker invokes the partner's adapter (`ChannelAdapter::push`). Network calls enforce strict connection (5s) and execution timeouts (5-10s) to prevent worker stalls.
5. **Telemetry Recording:** Every delivery attempt records duration in milliseconds, HTTP response codes, and error outcomes in the immutable `sync_attempts` table.
6. **Exponential Backoff with Jitter:** When a delivery fails, the retry delay follows a 4-tier schedule (**60s, 300s, 900s, 3600s**) decorrelated by `+/- 10%` randomized jitter to eliminate thundering herd storms.
7. **Dead State on 5th Failure:** After 5 failed attempts, the job permanently transitions to `status = 'dead'`. Automated retries stop, and the issue is surfaced for operator intervention.
8. **Direct Database Observability:** The operational dashboard (`/index.php`) and delivery log (`/log.php`) query the database directly, dynamically evaluating channel health scores and at-risk rooms.

---

## 3. Key Design Decisions & Architecture Trade-Offs

Detailed Architectural Decision Records (ADRs) are documented in [`docs/adr/`](docs/adr/):

- **[ADR 0001: Database-Backed Queue via Atomic Conditional UPDATE](docs/adr/0001-database-backed-queue.md):** Used MySQL queue tables with atomic conditional `UPDATE` claims instead of external brokers or `SKIP LOCKED`. Guarantees zero lost updates without distributed outbox machinery.
- **[ADR 0002: Plain PHP 8.2 First Without Monolithic Frameworks](docs/adr/0002-plain-php-first.md):** Zero-framework implementation using strict types, PDO, and Composer PSR-4 autoloading for complete architectural transparency.
- **[ADR 0003: One Adapter Per Booking Site](docs/adr/0003-one-adapter-per-booking-site.md):** Pluggable `ChannelAdapter` interface isolating channel-specific protocols, payload formatting, and authentication.
- **[ADR 0004: Exponential Backoff with Jitter and a Dead State](docs/adr/0004-exponential-backoff-with-jitter-and-dead-state.md):** 4-step canonical retry ladder (60s, 300s, 900s, 3600s) with `+/- 10%` jitter; 5th failure transitions to `dead` state.
- **[ADR 0005: Idempotency Key Plus Transactional Outbox Pattern](docs/adr/0005-idempotency-key-plus-transactional-outbox.md):** Client-generated UUID with UNIQUE constraint; atomic single-transaction commit; at-least-once delivery made safe by idempotency; HMAC request authentication.
- **[ADR 0006: At-Risk Inventory State Calculated Dynamically](docs/adr/0006-at-risk-is-calculated.md):** Dynamically computes room desynchronization at query time based on active queue state rather than storing stale cache flags.

---

## 4. Features Checklist

- [x] **Zero-Loss Queue Atomicity:** Inventory updates and channel distribution jobs created in a single ACID commit (`inventory_updates` + `sync_jobs`).
- [x] **Pluggable Channel Adapters:** Heterogeneous protocol support (Site A: REST JSON; Site B: XML + HMAC-SHA256; Site C: Token Auth + Rate Limits).
- [x] **Exponential Backoff & Full Jitter:** 4-interval retry ladder (60s, 300s, 900s, 3600s) with `+/- 10%` jitter; 5th failure is dead.
- [x] **Dead-Letter Storage & Operator UI:** Clear visibility into terminal failures with stored HTTP status, latencies, and one-click manual retry.
- [x] **Dynamic At-Risk Room Alerts:** Automatic query-time detection of inventory drift between channels to prevent overselling.
- [x] **Live Health Telemetry:** Rolling 50-attempt health metrics, average latency tracking, and worker heartbeats.
- [x] **Embedded Mock Partners:** Self-contained partner simulation endpoints for local zero-dependency testing.
- [x] **Chaos Simulation Drawer:** Interactive local dashboard controls to trigger outages, fast-forward retry timers, and run workers.
- [x] **Responsive Mobile & Wallboard Modes:** Tailored displays for mobile smartphones and wall-mounted front-desk status screens.
- [x] **Strict Static Analysis & PSR-12 Style:** PHPStan Level 5 clean, PHP_CodeSniffer PSR-12 compliant, strict types throughout.

---

## 5. Tech Stack

| Layer | Technology | Version / Specification | Rationale |
| :--- | :--- | :--- | :--- |
| **Backend Runtime** | PHP | 8.2 (Strict Types) | Zero-framework native implementation; fast startup, predictable memory footprint. |
| **Database** | MySQL | 8.0 (InnoDB) | ACID transactions, row-level locking via atomic conditional `UPDATE`, normalized relations. |
| **Web Server** | Apache | 2.4 (mpm_prefork) | Integrated PHP module execution with standard rewrite capabilities. |
| **Frontend** | Vanilla JS / CSS3 | ES6+ / Modern CSS Variables | Zero build step required; instant page loads; light/dark theme support. |
| **Containerization**| Docker Compose | v2+ | Single-command reproduction of web, database, and phpMyAdmin services. |
| **Static Analysis** | PHPStan | Level 5 (`phpstan.neon`) | Enforces strong typing, prevents null pointer dereferences and missing properties. |
| **Code Style** | PHP_CodeSniffer | PSR-12 (`phpcs.xml`) | Uniform formatting across all classes, interfaces, and API scripts. |
| **Testing** | Custom Runner | PHP CLI (`bin/run_tests.php`) | Multi-suite runner with timeout protection, process isolation, and tag filtering. |

---

## 6. Project Structure

```text
SyncGuard/
├── bin/                        # CLI maintenance, worker daemon, and test runner scripts
│   ├── worker.php              # Autonomous queue worker daemon
│   ├── run_tests.php           # Unified multi-suite test runner
│   ├── mock_control.php        # CLI partner chaos simulation controls
│   └── rebuild_health.php      # Rolling health score recalculator
├── config/                     # Application configuration
│   └── config.php              # Environment-aware database and worker settings
├── db/                         # Database schema and seed migrations
│   ├── schema.sql              # Normalized InnoDB schema (UTC standard)
│   └── seed.sql                # Hotel rooms and partner channels seeds
├── docs/                       # Architectural documentation and assets
│   ├── adr/                    # Architecture Decision Records (ADR 0001 - 0006)
│   ├── images/                 # System architecture and job lifecycle diagrams
│   ├── screenshots/            # Authentic capture gallery and animations
│   ├── ARCHITECTURE.md         # Detailed architectural specification
│   └── INTERVIEW_NOTES.md      # 25 technical Q&As with exact code references
├── public/                     # Public web document root (Apache / PHP-FPM)
│   ├── api/                    # REST JSON endpoints (inventory, status, jobs, retry)
│   ├── assets/                 # Vanilla CSS tokens and modular ES6 JavaScript
│   ├── mock/                   # Embedded mock booking site servers (Site A, B, C)
│   ├── index.php               # Operational dashboard & wallboard
│   ├── update.php              # Inventory modification portal
│   └── log.php                 # Live delivery and attempt telemetry log
├── src/                        # PSR-4 application source code (SyncGuard namespace)
│   ├── Adapters/               # ChannelAdapter interface and partner implementations
│   ├── Database/               # PDO database connection management
│   ├── Repositories/           # Data access layers (Job, Update, Health, Channel)
│   ├── Retry/                  # Exponential backoff, jitter, and RetryDecision logic
│   ├── Services/               # Domain services (InventoryService, HealthService)
│   ├── Support/                # Heartbeat, AlertWriter, and Timezone helpers
│   └── Worker/                 # JobProcessor orchestration engine
├── storage/                    # Heartbeat tokens and local alert logs (.gitkeep)
├── Dockerfile                  # Production-ready PHP 8.2 Apache container definition
├── docker-compose.yml          # Web, MySQL, and phpMyAdmin multi-container setup
├── composer.json               # Dependencies and PSR-4 autoload mapping
├── phpstan.neon                # Level 5 static analysis configuration
└── phpcs.xml                   # PSR-12 coding standard configuration
```

---

## 7. Quick Start with Docker

### Prerequisites
- Docker Engine 24+ and Docker Compose v2+
- Git

### 1. Clone & Configure
```bash
git clone https://github.com/rohansaijagann/sync-guard-.git
cd sync-guard-
cp .env.example .env
```

### 2. Boot Containers
```bash
docker compose up -d --build
```

### 3. Verify System Health
Open your browser to:
- **Operations Dashboard:** [http://localhost:8080/](http://localhost:8080/)
- **Inventory Update Portal:** [http://localhost:8080/update.php](http://localhost:8080/update.php)
- **Delivery Log:** [http://localhost:8080/log.php](http://localhost:8080/log.php)
- **phpMyAdmin (Database Management):** [http://localhost:8081/](http://localhost:8081/)

### 4. Running the Queue Worker
The queue worker can be executed continuously as a daemon or run in single-pass mode:

```bash
# Execute a single processing pass (ideal for cron or step-by-step verification)
docker compose exec web php bin/worker.php --once

# Run continuous background worker daemon (polls queue every 3 seconds)
docker compose exec web php bin/worker.php --interval=3 --batch=10
```

### 5. Failure Simulation with Mock Modes
Simulate real-world channel outages using the built-in CLI tool or the dashboard simulation drawer:

```bash
# Inject 503 outage on Site C
docker compose exec web php bin/mock_control.php set site_c always_fail

# Restore Site C to healthy operation
docker compose exec web php bin/mock_control.php set site_c always_succeed
```

---

## 8. Automated Testing & Verification

The project includes an isolated PHP CLI test runner (`bin/run_tests.php`) executing comprehensive verification suites:

```bash
# Run all verification suites
docker compose exec web php bin/run_tests.php

# Run quick verification suites (under 15 seconds)
docker compose exec web php bin/run_tests.php --quick

# Run static analysis and linting checks only
docker compose exec web php bin/run_tests.php --only=verify_setup
```

---

## 9. HTTP REST API Reference

Sync Guard provides structured JSON endpoints for UI operations and telemetry:

| Endpoint | Method | Description | Key Query / Body Parameters |
| :--- | :--- | :--- | :--- |
| `/api/inventory.php` | `POST` | Atomically creates inventory update and child channel jobs. | `room_id`, `date_from`, `date_to`, `available_units`, `price`, `idempotency_key` |
| `/api/status.php` | `GET` | Channel health, queue counts, at-risk rooms. | `_` (Cache-buster) |
| `/api/jobs.php` | `GET` | Paginated and filtered delivery jobs list. | `status`, `channel_id`, `room_id`, `page`, `per_page` |
| `/api/attempts.php` | `GET` | Detailed attempt history for a specific job. | `job_id` |
| `/api/retry.php` | `POST` | Requeue a dead or failed job for delivery. | `job_id` |
| `/api/calendar.php` | `GET` | 14-day multi-room inventory matrix. | `days` (Default: 14) |
| `/api/insights.php` | `GET` | Aggregated sync volume and success sparklines. | `_` (Cache-buster) |
| `/api/demo.php` | `POST` | Chaos engineering simulation controls (Local only). | `action` (`set_mode`, `run_worker`, `skip_wait`, `reset_queue`) |

---

## 10. Known Limitations & Architectural Scope

This project was engineered to demonstrate robust asynchronous queuing, resilience engineering, and distributed consistency patterns:

- **Authentication & RBAC:** User login, sessions, roles, and audit logging are intentionally omitted (documented educational scope). In an enterprise deployment, authentication would be handled via OIDC or an API Gateway.
- **CSRF Protection:** Real CSRF tokens are replaced with origin verification (`X-Requested-With` and `Host == Origin`) to keep demo endpoints accessible for local simulation.
- **Sequential Worker Execution:** The background worker processes claimed jobs sequentially per pass. Multi-channel parallelization or dedicated channel queues can be added for horizontal scaling.
- **Circuit Breaker:** Table schema contains `circuit_*` columns from initial scaffolding, but circuit breaker logic is not implemented (health scoring is evaluated dynamically).

---

## 11. Scaling to 1,000+ Hotel Properties

To scale Sync Guard from an independent hotel engine to a global enterprise hospitality SaaS platform:

1. **Multi-Tenant Sharding:** Add `tenant_id` to all tables with composite primary keys `(tenant_id, id)` and tenant-aware connection routing.
2. **Horizontal Consumer Pools:** Run stateless containerized worker pools competing for queued jobs via atomic conditional `UPDATE` claims.
3. **Partitioned Queue Storage:** Partition `sync_jobs` and `sync_attempts` by month (`PARTITION BY RANGE (created_at)`) with automated 90-day cold-storage archiving to Amazon S3 / BigQuery.
4. **Distributed Health Telemetry:** Move rolling-window health calculations to Redis HyperLogLog / Redis Streams to eliminate database aggregation queries during high dashboard concurrency.

---

## 12. Developer & Operational CLI Tools

Sync Guard includes dedicated CLI utilities in `bin/` for operational troubleshooting and local automation:

| Script | Purpose | Usage Example |
| :--- | :--- | :--- |
| `bin/worker.php` | Runs a continuous worker daemon or single execution pass. | `php bin/worker.php --once` |
| `bin/mock_control.php` | Simulates channel outages, latency, and rate limits. | `php bin/mock_control.php set site_c always_fail` |
| `bin/rebuild_health.php`| Recalculates rolling 50-attempt channel health scores. | `php bin/rebuild_health.php` |
| `bin/retry_job.php` | Requeues a dead-letter job by ID from the terminal. | `php bin/retry_job.php 42` |
| `bin/reset_queue.php` | Flushes queue tables and resets auto-increments for testing.| `php bin/reset_queue.php --yes` |
| `bin/run_tests.php` | Runs automated test suites with timeout protection. | `php bin/run_tests.php --quick` |

---

## 13. License

This project is licensed under the MIT License — see the [LICENSE](LICENSE) file for details.
