# ADR 0003: One Adapter Per Booking Site (Adapter Pattern)

## Context
Hotel inventory synchronization requires integrating with multiple external Online Travel Agencies (OTAs). Each external channel defines its own transmission protocol, payload format (REST JSON vs. XML), authentication scheme (Bearer token vs. HMAC signatures), and error response conventions.

## Decision
We implemented the Adapter Pattern using a shared interface:
- `src/Adapters/ChannelAdapter.php` defines `interface ChannelAdapter` with method `push(array $update, array $channel): PushResult`.
- Dedicated adapter classes (`SiteAAdapter`, `SiteBAdapter`, `SiteCAdapter`) encapsulate channel-specific transformations and HTTP transport.
- An `AdapterResolver` dynamically instantiates and maps channel database records to their corresponding adapter instance.

## Alternatives Considered
- **Monolithic Dispatcher:** A single giant `JobDispatcher` class with switch-case statements per channel. Rejected because adding new partner channels violates the Open/Closed Principle and creates cross-channel regression risks.
- **Generic Configurable HTTP Client:** A declarative JSON-configured mapping engine. Rejected because differences in authentication hashing (HMAC-SHA256 vs Bearer token), rate-limit header parsing, and custom error formats make declarative mappings too brittle and complex compared to simple PHP code.

## Consequences
- **Positive:** Adding a new booking site requires only writing a single new adapter class implementing `ChannelAdapter` and adding a row to the `channels` table. Worker loop remains completely decoupled from external OTA changes.
- **Negative:** Boilerplate serialization code must be maintained per adapter. If several partner channels share identical REST specifications, duplication can emerge without a common abstract base class.
