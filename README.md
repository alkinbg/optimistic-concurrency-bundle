# OptimisticConcurrencyBundle

[![CI](https://github.com/alkinbg/optimistic-concurrency-bundle/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/alkinbg/optimistic-concurrency-bundle/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.2%E2%80%938.5-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-7.4%20LTS%20%7C%208.1%2B-000000?logo=symfony&logoColor=white)
![Doctrine ORM](https://img.shields.io/badge/Doctrine%20ORM-3.4.4%2B-FC6A31?logo=doctrine&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)
[![Latest Stable Version](https://img.shields.io/packagist/v/alkinbg/optimistic-concurrency-bundle?logo=packagist)](https://packagist.org/packages/alkinbg/optimistic-concurrency-bundle)
[![Total Downloads](https://img.shields.io/packagist/dt/alkinbg/optimistic-concurrency-bundle?logo=packagist)](https://packagist.org/packages/alkinbg/optimistic-concurrency-bundle)

HTTP optimistic concurrency control for Symfony and Doctrine using strong ETags and `If-Match`.

The bundle prevents **lost updates** when two clients edit the same Doctrine entity from an older representation. It connects HTTP conditional requests to Doctrine's versioned entities without adding another persistence layer.

The 1.x public API is intentionally small: `OptimisticConcurrencyBundle`, `#[EntityTag]`, `#[RequireIfMatch]`, `EntityTagContext` and `EntityTagProviderInterface`. Everything else is an implementation detail and may evolve without expanding the supported API surface.

## Requirements

- PHP 8.2+
- Symfony 7.4 LTS or Symfony 8.1+
- Doctrine ORM 3.4.4+
- A Doctrine entity using `#[ORM\Version]`

## Installation

```bash
composer require alkinbg/optimistic-concurrency-bundle
```

Symfony Flex registers the bundle automatically. If your application does not use Flex, register it manually:

```php
// config/bundles.php

return [
    // ...
    OptimisticConcurrency\Bundle\OptimisticConcurrencyBundle::class => ['all' => true],
];
```

No bundle configuration is required.

## 1. Version the entity with Doctrine

Doctrine performs the final atomic optimistic-lock check during `flush()`. Use an integer version when possible.

```php
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version;

    // ...
}
```

The example entity is intentionally non-final so it also works with proxy-based lazy-loading configurations available within the supported dependency range. If your Doctrine setup supports final entities through native lazy objects, follow the constraints of that setup.

The version field remains an implementation detail. The bundle never exposes it directly.

## 2. Emit an ETag when the resource is read

```php
use OptimisticConcurrency\Bundle\Attribute\EntityTag;
use Symfony\Component\HttpFoundation\JsonResponse;

#[EntityTag('document', scope: 'document-detail-v1')]
public function show(Document $document): JsonResponse
{
    return new JsonResponse([
        'id' => $document->getId(),
        'title' => $document->getTitle(),
    ]);
}
```

`scope` is optional. It gives the representation a stable application-defined name and avoids coupling validator logic to route names. The default provider includes an explicit scope in its opaque token, so two intentionally different scopes for the same entity/version receive different validators. Use the same scope on the read and write endpoints when they protect the same representation contract.

For long-lived APIs, version the scope when a deployment intentionally changes representation semantics independently of entity state, for example `document-detail-v1` → `document-detail-v2`. That prevents validators issued for an older representation contract from being treated as validators for the new one.

A successful response contains an opaque strong validator:

```http
HTTP/1.1 200 OK
ETag: "oc1-..."
```

The default ETag is derived from the Doctrine entity class, identifier, version and optional explicit representation scope, then SHA-256 hashed. Raw identifiers, version values and scope strings are not placed directly in the header. ETags are validators, not secrets. The `oc1-` prefix versions the bundle's token format so a future encoding can be introduced deliberately.

When either optimistic-concurrency attribute is active, the bundle owns the response `ETag` header for successful responses. A controller-supplied ETag is therefore replaced by the validator generated from the protected entity.

The default token is deterministic and does not depend on process-local state or a shared cache, so different application nodes running the same representation contract generate the same validator for the same entity identifier, version and scope.

## 3. Require `If-Match` before a write

```php
use OptimisticConcurrency\Bundle\Attribute\RequireIfMatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

#[RequireIfMatch('document', scope: 'document-detail-v1')]
public function update(
    Document $document,
    EntityManagerInterface $entityManager,
): JsonResponse {
    $document->rename('New title');
    $entityManager->flush();

    return new JsonResponse([
        'id' => $document->getId(),
        'title' => $document->getTitle(),
    ]);
}
```

The client sends the ETag it received earlier:

```http
PATCH /documents/42
If-Match: "oc1-..."
Content-Type: application/json
```

If the tag still represents the current entity version, the controller runs. A successful write response receives the **new** ETag automatically.

If the resource changed before the request arrived:

```http
HTTP/1.1 412 Precondition Failed
ETag: "oc1-current..."
Cache-Control: no-store
```

If the client omitted `If-Match`:

```http
HTTP/1.1 428 Precondition Required
Cache-Control: no-store
```

Malformed syntax is rejected before validator work:

```http
HTTP/1.1 400 Bad Request
Cache-Control: no-store
```

Missing and malformed preconditions are handled before ETag-provider or Doctrine entity-state work. Invalid client syntax therefore cannot invoke application validator logic or disclose a current resource validator.

Resource resolution happens before the bundle's precondition listener. If Symfony or Doctrine cannot resolve the controller's entity argument, the application's normal not-found behavior remains authoritative; the bundle only evaluates `If-Match` for an entity that has actually been resolved.

## Race safety

The bundle intentionally uses two checks:

1. `If-Match` is validated after Symfony has resolved controller arguments and before the controller runs.
2. Doctrine's `#[ORM\Version]` check remains authoritative during `flush()`.

The second check closes the race window between the HTTP precondition check and an ORM entity update. If Doctrine reports an `OptimisticLockException` for the protected entity, the bundle converts it to `412 Precondition Failed`.

A conflict detected by Doctrine during `flush()` intentionally does not include a replacement ETag. At that point the in-memory entity may be stale and the EntityManager may no longer be safe to use for deriving fresh state. The client should fetch the resource again before retrying.

The bundle never calls `flush()` and never commits a transaction on the application's behalf. A protected write must complete its intended versioned ORM write before returning a successful response if the response ETag is expected to describe that write.

Do not remove Doctrine versioning and rely only on the HTTP header check.

### Controller argument lifecycle

The generic integration point is Symfony's `kernel.controller_arguments` event because it gives the bundle the actual resolved Doctrine entity without duplicating `MapEntity` or custom value-resolver behavior.

That event fires after all controller arguments have been resolved. If another argument resolver deserializes or validates the request body, that work can therefore happen before `If-Match` is evaluated. The protected controller itself still does not execute before the precondition succeeds, and Doctrine's version predicate remains the atomic write guard.

Applications that require conditional headers to be evaluated before any request-body parsing need an application-specific earlier integration tied to their resource resolution strategy.

## `If-Match` semantics

The implementation follows HTTP strong-comparison semantics:

- `If-Match: *` matches an existing resolved resource;
- a comma-separated list succeeds if any **strong** tag matches;
- weak validators such as `W/"..."` never satisfy the precondition;
- quoted opaque tags may contain commas;
- a reasonable number of empty list elements is ignored as required by HTTP list parsing rules;
- an explicitly present list containing no entity tags evaluates false and returns `412`;
- malformed syntax is rejected instead of being guessed.

`If-Match: *` is only an **existence precondition**. It does not prove that the client still holds the version it originally read, so it should not be used when the goal is lost-update prevention. The resolved entity is still required to be a managed, persisted, versioned Doctrine entity even for the wildcard form. An entity that is only scheduled for insertion is not considered persisted, even when it already has an application-assigned identifier and initialized version value. The provider is also preflighted so an invalid custom validator fails before application mutation work starts.

## Representation scope

An HTTP ETag validates a representation, while the default provider derives its token from entity identity, version and an optional explicit scope. Use `#[EntityTag]` only when that version and scope together identify every state change that matters to the protected representation.

If the response can vary independently of those inputs — for example because of user-specific fields, serializer groups, locale, query parameters or related data whose changes do not bump the entity version — use a custom `EntityTagProviderInterface` that includes the relevant representation state, or handle the concurrency contract at application level. Do not reuse the default generated ETag as a generic cache validator for representations with independent variation.

Every provider receives an `EntityTagContext` containing the current Symfony `Request` and the optional explicit `scope`. This keeps representation-aware providers straightforward without requiring `RequestStack` or controller reflection while deliberately keeping the stable public context small.

A custom provider changes the **HTTP validator only**. It does not broaden Doctrine's final atomic optimistic-lock check. If additional state in the validator can change independently after the `If-Match` check, make those changes bump the protected entity's version transactionally or enforce an equivalent atomic database precondition yourself. Otherwise the extra state is useful for early stale detection but is not protected against the final check-to-write race.

The bundle intentionally does not implement `If-None-Match` or automatic `304 Not Modified` handling; its scope is optimistic write concurrency.

## Custom ETag providers

`EntityTagProviderInterface` is the supported extension point for applications whose representation version is broader than one Doctrine version field or whose validator format needs to be application-specific.

A provider can replace the default service entirely. Replacing or decorating the provider does **not** opt out of the Doctrine safety boundary: before any provider output is used, the bundle independently requires the protected object to be a managed, persisted, versioned Doctrine ORM entity with an initialized version. When only selected resources need extra fingerprint state, decorating the default provider avoids duplicating its entity identity/version logic:

```php
use OptimisticConcurrency\Bundle\Context\EntityTagContext;
use OptimisticConcurrency\Bundle\Contract\EntityTagProviderInterface;
use App\Entity\Document;

final readonly class ApplicationEntityTagProvider implements EntityTagProviderInterface
{
    public function __construct(private EntityTagProviderInterface $inner)
    {
    }

    public function generate(object $entity, EntityTagContext $context): string
    {
        $baseTag = $this->inner->generate($entity, $context);

        if (!$entity instanceof Document || 'document-detail-v1' !== $context->scope) {
            return $baseTag;
        }

        $fingerprint = implode("\0", [
            $baseTag,
            $context->request->getLocale(),
            $entity->relatedStateFingerprint(),
        ]);

        return '"app1-'.hash('sha256', $fingerprint).'"';
    }
}
```

Register the decorator:

```yaml
# config/services.yaml
services:
    App\Http\ApplicationEntityTagProvider:
      decorates: OptimisticConcurrency\Bundle\Contract\EntityTagProviderInterface
      arguments:
        $inner: '@App\Http\ApplicationEntityTagProvider.inner'
```

A provider must return exactly one canonical **strong** entity-tag, including the surrounding double quotes. Weak tags, wildcard values, lists, unquoted values and values with surrounding whitespace are rejected with a `LogicException` before they can be emitted or used for `If-Match` comparison.

Custom validators must be deterministic across application nodes and must change whenever state relevant to the protected representation changes. If request data participates in the fingerprint, canonicalize only stable representation inputs that are equivalent between the read and subsequent write. Avoid request methods, bodies, timestamps, credentials, session identifiers, volatile headers or other values that would make the pre-mutation validator differ between requests or leak sensitive state through validator changes.

## Hard deletes are intentionally not supported

Doctrine ORM's normal entity `DELETE` statement is keyed by the entity identifier and does **not** include the optimistic-lock version in the `WHERE` clause. A pre-controller `If-Match` check alone would therefore leave a race window in which a newer resource could be deleted.

For that reason, applying `#[RequireIfMatch]` to an actual HTTP `DELETE` request is rejected early with a `LogicException`. More generally, **do not hard-delete the protected entity from a `#[RequireIfMatch]` controller under another HTTP verb either**. The bundle cannot infer such application behavior before the controller runs, and Doctrine's standard removal remains unversioned regardless of whether the route used `POST`, `PATCH` or another verb.

A versioned soft delete that updates ordinary entity state and is persisted through Doctrine's normal version-checked `UPDATE` path is different and can use the same optimistic-write contract as any other versioned update.

If your API needs a hard conditional delete, implement an application-specific database operation whose `DELETE` statement atomically includes both the identifier and the expected version.

## Security ordering

The precondition listener runs on `kernel.controller_arguments` at priority `-20000`.

This is deliberately below Symfony's standard controller-attribute authorization processing on every supported framework line. The functional suite boots the real `SecurityBundle` and verifies that an authenticated user lacking the required role receives `403` before optimistic precondition processing, without invoking the ETag provider or disclosing an `ETag`, even when the request supplies a syntactically valid stale `If-Match` value. Anonymous requests may instead receive `401` according to the application's firewall; the required invariant is that authentication and authorization complete before validator derivation.

Application-specific authorization listeners that protect the same controller must also complete before priority `-20000`. Do not place sensitive authorization exclusively inside the controller body: `#[RequireIfMatch]` necessarily evaluates its precondition before that body executes.

## Scope and guarantees

The bundle is deliberately small:

- it protects one Doctrine ORM entity argument per controller method;
- the entity must use `#[ORM\Version]` and be written through the ORM Unit of Work when relying on the bundle's Doctrine race guarantee;
- the entity must already represent persisted database state; merely scheduling an entity for insertion is rejected even when an identifier and version value are already present;
- a successful protected write must flush the intended versioned entity change before the response ETag is emitted; the bundle never flushes automatically;
- the protected entity must remain managed by Doctrine while the bundle generates or validates its ETag, regardless of the configured provider;
- direct DBAL/DQL bulk updates bypass Doctrine's per-entity version check and are outside the guarantee;
- custom provider state outside the entity version requires its own atomic concurrency guard unless it transactionally bumps the protected entity version;
- hard deletion of the protected entity is outside the guarantee because Doctrine's standard removal is not version-guarded;
- it does not implement pessimistic locking;
- it does not start or manage transactions;
- it does not replace Doctrine's optimistic locking;
- it does not prescribe JSON error bodies; Symfony's normal exception rendering remains in control.

For a strong validator to be meaningful, the validator must change for every state change relevant to the representation protected by the ETag. Collection-only, deployment-level representation changes or out-of-band state changes that do not increment the entity version require an explicit scope revision, a custom provider, or — when they are concurrency-significant — an application-level atomic guard.

## Backward-compatibility policy

The 1.x line follows Semantic Versioning. The documented public classes and interface are covered by architecture tests that lock provider parameter names/types, public constructor shapes, readonly public properties and the public/internal boundary. Classes marked `@internal` are not part of the backward-compatibility promise.

New optional constructor arguments or additive API may be introduced in minor releases. Removing or changing supported public signatures is reserved for a new major version.

## Testing

```bash
composer update
composer qa
```

The default functional suite uses SQLite. To run the portable concurrency suite against another Doctrine-supported database, provide a DBAL URL:

```bash
OPTIMISTIC_CONCURRENCY_DATABASE_URL='postgresql://postgres:postgres@127.0.0.1:5432/optimistic_concurrency?serverVersion=16' \
    vendor/bin/phpunit
```

CI also runs the portable functional suite against MariaDB 11.8. To reproduce that gate locally:

```bash
OPTIMISTIC_CONCURRENCY_DATABASE_URL='mysql://optimistic_concurrency:optimistic_concurrency@127.0.0.1:3306/optimistic_concurrency?charset=utf8mb4' \
    vendor/bin/phpunit
```

The test suite covers strong/weak validator behavior, malformed headers and no-store responses, parse-before-provider ordering, `428`, stale writes, updated ETags, wildcard matching and provider preflight, tolerant list parsing, provider output validation, provider-independent Doctrine version enforcement, the stable public API boundary, representation scopes, binary-safe string identifier normalization on the default SQLite fixture, Doctrine lazy references and `EntityManagerDecorator` compatibility, real Symfony Security ordering, detached-entity and scheduled-insert rejection, hard-delete rejection and a real database race that Doctrine turns into an optimistic-lock conflict.

## License

MIT. See [LICENSE](LICENSE).
