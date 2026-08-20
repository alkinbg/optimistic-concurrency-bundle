<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Attribute;

/**
 * Emits a strong ETag for the named resolved Doctrine ORM entity on successful responses.
 *
 * The optional scope names the representation contract. Reuse the same scope
 * on read and conditional-write endpoints that exchange the same validator.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class EntityTag
{
    public string $argument;
    public ?string $scope;

    public function __construct(string $argument, ?string $scope = null)
    {
        $argument = trim($argument);

        if ('' === $argument) {
            throw new \InvalidArgumentException('The controller argument name for #[EntityTag] must not be empty.');
        }

        if (null !== $scope) {
            $scope = trim($scope);

            if ('' === $scope) {
                throw new \InvalidArgumentException('The representation scope for #[EntityTag] must be null or a non-empty string.');
            }
        }

        $this->argument = $argument;
        $this->scope = $scope;
    }
}
