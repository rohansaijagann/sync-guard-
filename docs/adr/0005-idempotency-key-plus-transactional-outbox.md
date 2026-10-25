# ADR 0005: Idempotency Key Plus Transactional Outbox Pattern

## Context
In hotel inventory management, network retries and staff double-clicks can submit duplicate updates. If an update creates an inventory record but fails before queuing jobs (or vice-versa), data enters an inconsistent state. Furthermore, retransmitting deliveries across network timeouts can cause partner booking channels to process the same update multiple times.

## Decision
We implemented a two-fold guarantee:
1. **Transactional Outbox (`InventoryService::createUpdate`):** The inventory update row in `inventory_updates` and all child distribution jobs in `sync_jobs` are created in a single ACID database transaction. Either both commit or neither does.
2. **Client Idempotency Key + Unique Constraint:** The front-end generates a UUIDv4 `idempotency_key` sent with each submission. `inventory_updates` enforces a `UNIQUE KEY (idempotency_key)`. Duplicate submissions trigger MySQL error 1062 / SQLSTATE 23000, caught in `InventoryService`, which returns an HTTP 200 Duplicate response containing the original update and job IDs without re-inserting.
3. **End-to-End Key Forwarding:** Outbound adapters forward the client's `idempotency_key` to external booking channels so remote systems deduplicate retried deliveries.
4. **Separation of HMAC:** HMAC-SHA256 signatures (`X-Signature: sha256=...`) authenticate request payload integrity against tampering and are a completely separate mechanism from idempotency.
5. **Delivery Guarantee:** The system provides at-least-once delivery, made completely safe by idempotency at both the intake API and the partner endpoints.

## Alternatives Considered
- **Distributed Two-Phase Commit (2PC):** Coordinating MySQL and an external message broker via distributed transactions. Adds extreme operational complexity and latency.
- **In-Memory Deduplication (Redis TTL cache):** Deduplicating in Redis before database writes. Vulnerable to race conditions on Redis failover and requires cross-system consistency logic.

## Consequences
- **Positive:** Zero lost updates; zero duplicate inventory records from double-clicks or browser retries; safe at-least-once outbound replay over unstable networks.
- **Negative:** Clients must generate a UUID for every distinct modification; submitting a different payload with an already-used idempotency key returns the original update rather than overwriting it.
