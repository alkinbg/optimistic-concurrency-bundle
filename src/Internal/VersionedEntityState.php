<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Internal;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @internal
 */
final readonly class VersionedEntityState
{
    /**
     * @param ClassMetadata<object>   $metadata
     * @param array<array-key, mixed> $identifier
     */
    public function __construct(
        public ClassMetadata $metadata,
        public array $identifier,
        public mixed $version,
    ) {
    }
}
