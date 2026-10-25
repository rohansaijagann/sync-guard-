# ADR 0006: At-Risk Inventory State Calculated Dynamically

## Context
When an external booking channel encounters downtime or delay, room availability across booking sites begins to drift out of sync, creating a severe risk of double-bookings (overselling). Storing a persistent `is_at_risk` boolean flag on the `rooms` table requires cache invalidation triggers and complex distributed synchronization logic across all update and worker pathways.

## Decision
We compute "At Risk" rooms dynamically at query time in `StatusService::calculateAtRiskRooms()`:
A room is identified as **At Risk** when there are unsynchronized jobs (`j.status != 'success'`, i.e. `pending`, `in_progress`, `failed`, or `dead`) on active channels for an update to that room. The calculation runs as a single SQL query joining `rooms`, `inventory_updates`, `sync_jobs`, and `channels` grouped by room:
```sql
SELECT r.id AS room_id, r.name AS room_name, COUNT(DISTINCT u.id) AS unsynced_updates, ...
FROM rooms r
JOIN inventory_updates u ON u.room_id = r.id
JOIN sync_jobs j ON j.update_id = u.id
JOIN channels c ON c.id = j.channel_id
WHERE c.is_active = 1 AND j.status != 'success'
GROUP BY r.id, r.name
```
No static `is_at_risk` flag is stored in the database.

## Alternatives Considered
- **Persistent Flag in `rooms` Table (`is_at_risk TINYINT(1)`):** Vulnerable to synchronization drift if worker passes terminate unexpectedly, during database rollbacks, or during manual operator retry interventions.
- **Event-Driven Cache Tags (Redis / Memcached):** Adds external caching dependencies, cache TTL expiration hazards, and invalidation race conditions during concurrent channel updates.

## Consequences
- **Positive:** Guaranteed 100% accurate reflection of active delivery truth with zero cache staleness. The instant a failed or dead job succeeds, the at-risk state clears immediately on the next dashboard poll.
- **Negative:** Telemetry polls (`/api/status.php`) execute an indexed aggregation query joining `sync_jobs` and `inventory_updates`. Bounded to a single lightweight query by indexing foreign keys.
