<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Http;

/**
 * Parsed and validated If-Match precondition.
 *
 * @internal
 */
final readonly class IfMatchCondition
{
    /**
     * @param list<array{weak: bool, value: string}> $entityTags
     */
    public function __construct(
        public bool $wildcard,
        public array $entityTags,
    ) {
    }
}
