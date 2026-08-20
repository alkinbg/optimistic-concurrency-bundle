# OptimisticConcurrencyBundle Changelog

All notable changes to this project will be documented in this file.

The project follows Semantic Versioning.

## [Unreleased]

## [1.0.0] - 2026-08-20

### Added

- Strong opaque ETag generation for managed Doctrine ORM versioned entities.
- `#[EntityTag]` for read responses.
- `#[RequireIfMatch]` for conditional writes.
- Optional representation `scope` on both public attributes; the default validator incorporates the scope while remaining independent of the request method.
- Minimal public `EntityTagContext` exposing the current request and optional representation scope to custom providers.
- RFC 9110 `If-Match` parsing with strong comparison, wildcard support, quoted commas, repeated field values and recipient-tolerant empty list elements.
- A bounded tolerance budget for empty `If-Match` list elements to preserve interoperability without allowing unbounded parser work.
- `428 Precondition Required` for missing write preconditions.
- `412 Precondition Failed` for stale validators.
- Translation of Doctrine optimistic-lock conflicts for the protected entity to HTTP 412.
- Deterministic validator generation across application nodes without shared state.
- Replaceable `EntityTagProviderInterface` with strict strong-validator validation.
- Independent Doctrine version/managed-state validation so a custom ETag provider cannot silently bypass the ORM optimistic-lock requirement.
- Explicit rejection of detached protected entities and HTTP DELETE protection, with documented rejection of hard-delete flows under any verb.
- Functional tests with a real SQLite-backed Doctrine entity and a real optimistic-lock race.
- Functional Symfony Security coverage proving authorization denial occurs before optimistic precondition processing and cannot disclose resource validators through that path.
- Doctrine lazy-reference validator stability coverage, including an official `EntityManagerDecorator` compatibility regression test.
- Explicit rejection coverage for entities scheduled for insertion even when application-assigned identifiers and initialized version fields already exist.
- Public API architecture checks suitable for the 1.x backward-compatibility promise, including provider parameter names/types and public constructor signatures.
- Optional DBAL URL support for running the portable functional suite against PostgreSQL or another supported database.
- PHPStan level-max analysis and Symfony coding-style checks.
- CI coverage for PHP 8.2 through 8.5, including a lowest-dependency build.
- Isolated GitHub-hosted CI for untrusted fork pull requests.
- PostgreSQL portability CI for the public release line.
- Dependabot maintenance for Composer and GitHub Actions dependencies.

### Changed

- Finalized the pre-1.0 project identity as `OptimisticConcurrencyBundle`, Composer package `alkinbg/optimistic-concurrency-bundle`, and PHP namespace `OptimisticConcurrency\Bundle`.
- The default opaque validator format uses the `oc1-` prefix, reserving an explicit versioned wire format for the stable package identity.
- `If-Match` syntax is parsed before ETag-provider and Doctrine entity-state work.
- Wildcard preconditions still preflight provider validity before controller execution so configuration failures cannot be discovered only after a mutation has flushed.
- Doctrine version/identifier/managed-state invariants now have one internal inspection implementation shared by the guard and default provider.
- `EntityTagProviderInterface::generate()` receives `EntityTagContext`, finalizing the representation-aware public contract before the first stable release.
- The default validator includes an explicit representation scope but deliberately ignores the HTTP method so a validator issued by a read endpoint can satisfy the subsequent write precondition for the same unchanged representation.
- Functional database schema setup and teardown is symmetrical so external-database runs remain isolated after the intentional optimistic-lock race.
- CI runs entirely on GitHub-hosted runners; trusted refs receive the full matrix and PostgreSQL gate while fork pull requests remain isolated.

### Fixed

- Lazy Doctrine objects are initialized through the stable `ObjectManager::initializeObject()` contract without requiring `isUninitializedObject()`, preserving compatibility with official `EntityManagerDecorator` implementations across the supported Doctrine Persistence 3.x and 4.x range.
- Entities that are only scheduled for insertion are rejected before validator generation, even if they already have an application-assigned identifier and initialized version value.

### Security

- The security ordering guarantee is covered by a real `SecurityBundle` integration test instead of relying only on listener-priority assertions.
- Missing and malformed preconditions are rejected before validator derivation, reducing unnecessary application work and avoiding validator disclosure from invalid requests.
- Malformed, missing and stale precondition responses are explicitly non-cacheable.
- Third-party GitHub Actions used by the hosted CI path are pinned to immutable commits.
