# ADR 0004: Exponential Backoff with Jitter and a Dead State

## Context
Third-party hotel booking channels inevitably experience network blips, intermittent rate limits, and service outages. Retrying failed HTTP deliveries at short, fixed intervals creates a "thundering herd" storm that hammers a struggling partner, preventing recovery. Conversely, retrying invalid payloads indefinitely wastes background worker capacity and clutters database tables.

## Decision
We implemented a canonical 4-step exponential backoff schedule with randomized jitter and a 5th-attempt terminal dead state in `src/Retry/Backoff.php`:
- **Retry Delays:**
  - Attempt 1: 60 seconds (1 minute)
  - Attempt 2: 300 seconds (5 minutes)
  - Attempt 3: 900 seconds (15 minutes)
  - Attempt 4: 3600 seconds (1 hour)
- **Jitter:** Randomized `+/- RETRY_JITTER_PERCENT` (default 10%, configurable via `config/config.php`), clamping random delays so they never drop below 1 second.
- **Dead State:** Upon the 5th failed attempt (`attempt_count >= 5`), the job permanently transitions to `status = 'dead'`. Automated retries stop, and the job requires human operator inspection and manual retry via `/api/retry.php`.

## Alternatives Considered
- **Fixed Linear Intervals (e.g. retry every 30s):** Fails to give remote services adequate time to recover from extended outages and synchronizes retry attempts across all failed jobs into traffic bursts.
- **Pure Exponential Backoff without Jitter:** Retries back off exponentially, but simultaneous failures remain synchronized in time, repeatedly striking partner servers at identical offsets.
- **Infinite Retries:** Causes permanent failures (e.g. decommissioned API endpoint or corrupted room mappings) to circulate endlessly, starving worker throughput.

## Consequences
- **Positive:** Protects external booking sites from traffic spikes; disperses retry dispatches evenly across a time window; bounds system resource consumption on non-recoverable partner failures.
- **Negative:** If a partner channel experiences an outage that resolves at minute 16, a job at Attempt 4 must wait up to an hour for its next automatic retry, delaying synchronization unless an operator triggers a manual retry via the dashboard.
