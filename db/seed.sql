-- ============================================================================
-- Sync Guard - Phase 1 Seed Data
-- Character Set: utf8mb4 (Japanese text support)
-- Safe for repeated execution (idempotent via ON DUPLICATE KEY UPDATE)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Initial Room Inventory (4 rooms with Japanese and English naming)
-- ----------------------------------------------------------------------------
INSERT INTO rooms (id, name, total_units, base_price) VALUES
  (1, 'シングルルーム (Single Room)', 15, 8500.00),
  (2, 'ツインルーム (Twin Room)', 10, 14000.00),
  (3, '和室 (Japanese-style Room)', 6, 18000.00),
  (4, 'スイート (Suite)', 2, 45000.00)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  total_units = VALUES(total_units),
  base_price = VALUES(base_price);

-- ----------------------------------------------------------------------------
-- 2. Initial Booking Channels (3 mock channel adapters)
-- ----------------------------------------------------------------------------
INSERT INTO channels (id, name, adapter_class, endpoint_url, is_active) VALUES
  (1, 'Site A', 'SiteAAdapter', 'http://web/mock/site_a.php', 1),
  (2, 'Site B', 'SiteBAdapter', 'http://web/mock/site_b.php', 1),
  (3, 'Site C', 'SiteCAdapter', 'http://web/mock/site_c.php', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  adapter_class = VALUES(adapter_class),
  endpoint_url = VALUES(endpoint_url),
  is_active = VALUES(is_active);

-- ----------------------------------------------------------------------------
-- 3. Initial Channel Health Records (Default circuit state: closed, 100% success)
-- ----------------------------------------------------------------------------
INSERT INTO channel_health (channel_id, success_rate, avg_response_ms, last_success_at, last_failure_at, circuit_state, consecutive_failures, circuit_opened_at) VALUES
  (1, 100.00, 0, NULL, NULL, 'closed', 0, NULL),
  (2, 100.00, 0, NULL, NULL, 'closed', 0, NULL),
  (3, 100.00, 0, NULL, NULL, 'closed', 0, NULL)
ON DUPLICATE KEY UPDATE
  success_rate = VALUES(success_rate),
  avg_response_ms = VALUES(avg_response_ms),
  circuit_state = VALUES(circuit_state),
  consecutive_failures = VALUES(consecutive_failures);
