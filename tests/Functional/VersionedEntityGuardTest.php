<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Functional;

use Doctrine\Persistence\ManagerRegistry;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityGuard;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityInspector;
use OptimisticConcurrency\Bundle\Tests\Functional\Fixture\Document;
use OptimisticConcurrency\Bundle\Tests\Functional\Fixture\UnversionedDocument;

final class VersionedEntityGuardTest extends FunctionalTestCase
{
    public function testManagedPersistedVersionedEntityIsAccepted(): void
    {
        $this->guard()->assertCanProtect($this->createDocument());

        $this->addToAssertionCount(1);
    }

    public function testUnversionedEntityIsRejected(): void
    {
        $document = new UnversionedDocument('No version');
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not versioned');

        $this->guard()->assertCanProtect($document);
    }

    public function testTransientEntityWithoutIdentifierIsRejected(): void
    {
        $document = new Document('Transient');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no assigned identifier');

        $this->guard()->assertCanProtect($document);
    }

    public function testDetachedEntityIsRejected(): void
    {
        $document = $this->createDocument();
        $this->entityManager->detach($document);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not managed by Doctrine');

        $this->guard()->assertCanProtect($document);
    }

    private function guard(): VersionedEntityGuard
    {
        $registry = $this->client->getContainer()->get('doctrine');

        if (!$registry instanceof ManagerRegistry) {
            throw new \LogicException('The functional test container does not expose a Doctrine ManagerRegistry.');
        }

        return new VersionedEntityGuard(new VersionedEntityInspector($registry));
    }
}
