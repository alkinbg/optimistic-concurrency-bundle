<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Attribute;

/**
 * Requires a strong If-Match precondition for the named resolved Doctrine ORM entity.
 *
 * A successful response receives the current strong ETag after the controller has run.
 * The optional scope must match the representation scope that issued the client tag.
 *
 * This attribute protects versioned ORM updates. Doctrine's standard hard delete
 * is not version-guarded and is intentionally outside the bundle's guarantee,
 * regardless of which HTTP verb an application uses to trigger the removal.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class RequireIfMatch
{
    public string $argument;
    public ?string $scope;

    public function __construct(string $argument, ?string $scope = null)
    {
        $argument = trim($argument);

        if ('' === $argument) {
            throw new \InvalidArgumentException('The controller argument name for #[RequireIfMatch] must not be empty.');
        }

        if (null !== $scope) {
            $scope = trim($scope);

            if ('' === $scope) {
                throw new \InvalidArgumentException('The representation scope for #[RequireIfMatch] must be null or a non-empty string.');
            }
        }

        $this->argument = $argument;
        $this->scope = $scope;
    }
}
