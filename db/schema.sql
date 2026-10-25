-- ============================================================================
-- Sync Guard - Phase 1 Database Schema
-- Database Engine: InnoDB
-- Character Set: utf8mb4 / Collation: utf8mb4_unicode_ci
-- Timezone Standard: UTC
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Table: rooms
-- Purpose: Represents room inventory categories within the hotel property.
-- Contains baseline units available and standard pricing in yen.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  total_units INT NOT NULL,
  base_price DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: channels
-- Purpose: Configuration for external Online Travel Agencies (OTAs) / booking sites.
-- Stores the specific PHP adapter class name and mock API endpoint URL.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS channels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  adapter_class VARCHAR(100) NOT NULL,
  endpoint_url VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: inventory_updates
-- Purpose: Records staff-initiated room availability and rate modifications.
--
-- Key Constraints:
-- - idempotency_key UNIQUE: Stops a double-submitted staff action (e.g. quick
--   double-click on front desk UI or network timeout resend) from being saved twice.
-- - correlation_id: UUID shared across downstream jobs and logs for end-to-end tracing.
-- - FOREIGN KEY (room_id): Enforces referential integrity with the rooms table.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_updates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  available_units INT NULL,
  price DECIMAL(10,2) NULL,
  idempotency_key CHAR(36) NOT NULL UNIQUE,
  correlation_id CHAR(36) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (room_id) REFERENCES rooms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: sync_jobs
-- Purpose: Queue of synchronization jobs distributing inventory updates to channels.
--
-- Key Constraints & Design Choices:
-- - UNIQUE KEY uq_update_channel (update_id, channel_id): Ensures exactly one job
--   per update per booking site. Prevents duplicate queued tasks for the same event.
-- - KEY idx_due (status, next_attempt_at): Composite index making the background
--   worker's "what is due now?" polling query (WHERE status = 'pending' AND
--   next_attempt_at <= NOW()) run via fast index range scans rather than full table scans.
-- - status ENUM:
--     * 'pending'     : Waiting to be dispatched or scheduled for retry.
--     * 'in_progress' : Claimed by an active background worker process.
--     * 'success'     : Confirmed accepted by the channel endpoint.
--     * 'failed'      : Temporary failure; eligible for retry if attempt_count < max.
--     * 'dead'        : Exceeded max retries. Requires human review; never retried
--                       silently forever to protect queue throughput.
-- - NOTE ON "AT RISK": "At risk" rooms are dynamically CALCULATED in later phases
--   from job status and delay thresholds, NEVER statically stored in a table.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  update_id INT NOT NULL,
  channel_id INT NOT NULL,
  status ENUM('pending','in_progress','success','failed','dead') NOT NULL DEFAULT 'pending',
  attempt_count INT NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_update_channel (update_id, channel_id),
  KEY idx_due (status, next_attempt_at),
  FOREIGN KEY (update_id) REFERENCES inventory_updates(id),
  FOREIGN KEY (channel_id) REFERENCES channels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: sync_attempts
-- Purpose: Immutable log of individual HTTP delivery attempts made by workers.
-- Provides detailed telemetry (latency, HTTP status, error outcome) for diagnostics.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL,
  started_at DATETIME NOT NULL,
  duration_ms INT NOT NULL,
  http_status INT NULL,
  outcome ENUM('success','timeout','http_error','rejected') NOT NULL,
  message VARCHAR(255) NULL,
  FOREIGN KEY (job_id) REFERENCES sync_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: channel_health
-- Purpose: Maintains real-time circuit breaker status and reliability metrics per channel.
-- If consecutive failures spike, circuit flips to 'open' to protect the channel from storming.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS channel_health (
  channel_id INT PRIMARY KEY,
  success_rate DECIMAL(5,2) NOT NULL DEFAULT 100,
  avg_response_ms INT NOT NULL DEFAULT 0,
  last_success_at DATETIME NULL,
  last_failure_at DATETIME NULL,
  circuit_state ENUM('closed','open','half_open') NOT NULL DEFAULT 'closed',
  consecutive_failures INT NOT NULL DEFAULT 0,
  circuit_opened_at DATETIME NULL,
  FOREIGN KEY (channel_id) REFERENCES channels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
