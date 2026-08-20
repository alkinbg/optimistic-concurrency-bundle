## Summary

Describe the change and the HTTP/Doctrine concurrency behavior it affects.

## Verification

- [ ] `composer validate --strict`
- [ ] `composer qa`
- [ ] Observable behavior changes have tests
- [ ] Public API / backward compatibility impact has been considered
- [ ] README / CHANGELOG are updated when required

## Protocol and concurrency checklist

- [ ] `If-Match` still uses strong entity-tag comparison
- [ ] Missing, stale and malformed preconditions remain distinguishable (`428`, `412`, `400`)
- [ ] Doctrine optimistic locking remains the final ORM race-safety boundary
- [ ] No automatic retry, hidden `flush()`, transaction boundary or domain merge policy was introduced
- [ ] Any custom representation fingerprint does not overclaim persistence-level atomicity
