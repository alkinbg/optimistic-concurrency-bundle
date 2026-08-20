<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Functional\Fixture;

use OptimisticConcurrency\Bundle\Context\EntityTagContext;
use OptimisticConcurrency\Bundle\Contract\EntityTagProviderInterface;
use OptimisticConcurrency\Bundle\ETag\EntityTagGenerator;

final class RecordingEntityTagProvider implements EntityTagProviderInterface
{
    public ?EntityTagContext $lastContext = null;
    public int $calls = 0;

    public function __construct(private readonly EntityTagGenerator $inner)
    {
    }

    public function generate(object $entity, EntityTagContext $context): string
    {
        $this->lastContext = $context;
        ++$this->calls;

        return $this->inner->generate($entity, $context);
    }

    public function reset(): void
    {
        $this->lastContext = null;
        $this->calls = 0;
    }
}
