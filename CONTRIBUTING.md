# Contributing to OptimisticConcurrencyBundle

Contributions are welcome when they keep the bundle focused on HTTP optimistic concurrency for Symfony and Doctrine.

## Development setup

```bash
composer update
composer qa
```

The functional suite uses SQLite by default. To exercise a supported external database locally, set `OPTIMISTIC_CONCURRENCY_DATABASE_URL` to a Doctrine DBAL URL before running PHPUnit.

## Pull requests

Before opening a pull request:

1. add or update tests for behavior changes;
2. run `composer qa`;
3. keep the 1.x public API backward-compatible unless the change is intentionally reserved for the next major version;
4. document HTTP-semantic or concurrency-guarantee changes in `README.md`, `SECURITY.md` when relevant, and `CHANGELOG.md`.

The supported 1.x API consists of the bundle class, the two controller attributes, `EntityTagContext` and `EntityTagProviderInterface`. Types marked `@internal` are implementation details. The architecture suite deliberately enforces that boundary and locks the provider and public-constructor signatures.

## CI trust boundary

The project uses a maintainer-operated self-hosted runner for trusted same-repository refs. Untrusted fork code is never executed on that machine.

When the repository is public, fork pull requests use an isolated GitHub-hosted portability job instead. Maintainers should still review dependency or workflow changes carefully before reproducing them on trusted refs.

## Design principles

- Doctrine's version check at `flush()` remains the final concurrency authority for protected ORM updates.
- Never claim atomic hard-delete protection unless the delete itself is version-guarded at the database statement level; changing the HTTP verb does not make Doctrine's normal removal safe.
- HTTP validators are opaque; raw entity identifiers and versions must not be exposed directly.
- Parse malformed preconditions before invoking application validator logic or Doctrine state inspection.
- Malformed precondition headers are rejected rather than guessed and precondition error responses remain non-cacheable.
- A custom `EntityTagProviderInterface` may broaden the HTTP representation fingerprint, but it does not broaden Doctrine's persistence-level atomicity boundary. Extra concurrency-significant state needs an equivalent atomic database precondition or must transactionally bump the protected entity version.
- Representation-aware providers should use explicit attribute scopes when possible and include only stable, non-secret request inputs that remain equivalent between the read and subsequent write.
- Keep the public context minimal. Do not add controller or operation metadata when the same information is application-specific or would encourage different read/write validators.
- The bundle must not silently call `flush()`, add transaction boundaries or acquire database locks.
- Error responses should use standard Symfony HTTP exceptions so applications retain control of content negotiation and rendering.
