<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Internal;

/**
 * @internal
 */
final readonly class RequestConcurrencyContext
{
    public function __construct(
        public object $entity,
        public bool $requireIfMatch,
        public ?string $scope,
    ) {
    }
}
