# OptimisticConcurrencyBundle Security Policy

## Supported versions

Starting with the first stable release, security fixes are applied to the latest supported 1.x release and the `main` branch. Older major versions, when they exist, will have their support status documented here.

## Security model

OptimisticConcurrencyBundle is a concurrency-control layer, not an authorization layer.

For endpoints protected with `#[EntityTag]` or `#[RequireIfMatch]`, resource authorization must complete before the bundle's `kernel.controller_arguments` listener runs at priority `-20000`. Symfony's standard `#[IsGranted]` processing satisfies that requirement on the supported framework versions. The functional test suite boots the real `SecurityBundle` and verifies that an authenticated user lacking the required role receives `403` before optimistic precondition processing, without invoking the ETag provider or disclosing an `ETag`.

Anonymous requests may correctly receive `401` depending on the application's firewall and authentication entry point. The security invariant enforced by the bundle is ordering: authentication and authorization must complete before validator derivation or precondition evaluation for protected resources.

Do not rely only on authorization code inside the controller body for a protected endpoint when disclosing the existence or validator of the resource would be sensitive: the precondition check runs before the controller body. Move that authorization to Symfony security, `#[IsGranted]`, or another earlier listener.

Malformed `If-Match` syntax is parsed and rejected before the bundle derives a validator or inspects Doctrine version state. Missing preconditions likewise fail before provider work. This limits application work and validator disclosure on invalid requests.

ETags are opaque validators, not secrets. The implementation hashes entity identity and version to avoid putting raw values directly in headers, but the token must not be treated as an authentication credential or capability.

Custom `EntityTagProviderInterface` implementations can read request context. Treat every representation input included in a validator as observable through validator changes. Do not include credentials, bearer tokens, session identifiers, personal secrets or other sensitive material in a fingerprint. Prefer stable, non-secret representation state and one-way hashing.

## Reporting a vulnerability

Please do **not** open a public issue for a suspected security vulnerability.

Use GitHub's private vulnerability reporting feature for this repository when available. If private reporting is not available, contact the maintainer through the contact information published on the maintainer's GitHub profile.

Include:

- the affected version or commit;
- a minimal reproduction;
- the expected and actual behavior;
- the security impact you believe is possible.

Please avoid including real credentials, personal data or production secrets in the report.
