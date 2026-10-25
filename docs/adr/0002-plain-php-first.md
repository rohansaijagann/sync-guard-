# ADR 0002: Plain PHP 8.2 First Without Monolithic Frameworks

## Context
When building Sync Guard as an educational showcase and technical demonstration of resilient distributed systems patterns, we needed an architecture that makes core systems designs (outbox transactions, conditional locking, exponential backoff, circuit evaluation) completely transparent and auditable, without the magic or overhead of monolithic full-stack frameworks.

## Decision
We chose plain, modern PHP 8.2 with strict typing (`declare(strict_types=1)`), vanilla PDO for database interactions, and Composer PSR-4 autoloading. We deliberately omitted Laravel, Symfony, and other opinionated frameworks.

## Alternatives Considered
- **Laravel / Symfony:** Provides built-in queues, ORM (Eloquent/Doctrine), and routing. However, framework abstraction hides atomic SQL queries, transactional boundaries, and low-level HTTP concurrency, turning an engineering portfolio piece into a framework configuration exercise.
- **Micro-frameworks (Slim / Lumen):** Lighter weight, but still introduces third-party routing middleware and DI container dependencies without significant benefit for a focused 5-endpoint REST API and single-daemon worker.

## Consequences
- **Positive:** Zero framework upgrade debt, minimal attack surface, sub-millisecond execution times, ultra-light Docker images, and 100% visible architectural patterns. Any engineer can trace request-to-database execution in seconds.
- **Negative:** Hand-written routing, parameter validation, and dependency injection wiring. Lacks framework conveniences like CLI generators or built-in migration rollback engines.
