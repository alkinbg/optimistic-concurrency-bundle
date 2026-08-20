<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Contract;

use OptimisticConcurrency\Bundle\Context\EntityTagContext;

interface EntityTagProviderInterface
{
    /**
     * Returns one canonical strong HTTP entity-tag, including surrounding quotes.
     *
     * The validator must be deterministic for all state represented by the
     * protected endpoint. The context exposes representation inputs without
     * forcing providers to depend on RequestStack or controller internals.
     * Weak validators are not accepted for If-Match.
     *
     * This contract controls the HTTP validator only. It does not broaden
     * Doctrine's persistence-level optimistic-lock boundary.
     */
    public function generate(object $entity, EntityTagContext $context): string;
}
