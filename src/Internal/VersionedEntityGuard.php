<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Internal;

/**
 * @internal
 */
final readonly class VersionedEntityGuard
{
    public function __construct(private VersionedEntityInspector $inspector)
    {
    }

    public function assertCanProtect(object $entity): void
    {
        $this->inspector->inspect($entity);
    }
}
