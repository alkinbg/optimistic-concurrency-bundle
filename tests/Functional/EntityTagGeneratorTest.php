<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Functional;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use OptimisticConcurrency\Bundle\Context\EntityTagContext;
use OptimisticConcurrency\Bundle\ETag\EntityTagGenerator;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityInspector;
use OptimisticConcurrency\Bundle\Tests\Functional\Fixture\BinaryIdentifierDocument;
use OptimisticConcurrency\Bundle\Tests\Functional\Fixture\Document;
use OptimisticConcurrency\Bundle\Tests\Functional\Fixture\UnversionedDocument;
use Symfony\Component\HttpFoundation\Request;

final class EntityTagGeneratorTest extends FunctionalTestCase
{
    public function testTagIsStableForSamePersistedEntityVersion(): void
    {
        $document = $this->createDocument();
        $generator = $this->generator();

        self::assertSame($this->generate($generator, $document), $this->generate($generator, $document));
    }

    /**
     * @throws OptimisticLockException
     * @throws ORMException
     */
    public function testTagIsStableAcrossManagedInstancesOfSameDatabaseState(): void
    {
        $document = $this->createDocument();
        $generator = $this->generator();
        $id = $document->id();
        $before = $this->generate($generator, $document);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Document::class, $id);

        if (!$reloaded instanceof Document) {
            throw new \LogicException('Expected the persisted test document to be reloadable.');
        }

        self::assertNotSame($document, $reloaded);
        self::assertSame($before, $this->generate($generator, $reloaded));
    }

    /**
     * @throws ORMException
     */
    public function testTagIsStableForDoctrineLazyReferenceOfSameDatabaseState(): void
    {
        $document = $this->createDocument();
        $generator = $this->generator();
        $id = $document->id();
        $before = $this->generate($generator, $document);

        $this->entityManager->clear();
        $reference = $this->entityManager->getReference(Document::class, $id);
        if (!$reference instanceof Document) {
            throw new \LogicException('Expected Doctrine to return a Document lazy reference.');
        }

        self::assertSame($before, $this->generate($generator, $reference));
    }

    public function testTagChangesWhenVersionChanges(): void
    {
        $document = $this->createDocument();
        $generator = $this->generator();
        $before = $this->generate($generator, $document);

        $document->rename('Changed');
        $this->entityManager->flush();

        self::assertNotSame($before, $this->generate($generator, $document));
    }

    public function testDifferentResourcesWithSameVersionHaveDifferentTags(): void
    {
        $first = $this->createDocument('First');
        $second = $this->createDocument('Second');
        $generator = $this->generator();

        self::assertNotSame($this->generate($generator, $first), $this->generate($generator, $second));
    }

    public function testDifferentExplicitRepresentationScopesHaveDifferentTags(): void
    {
        $document = $this->createDocument();
        $generator = $this->generator();

        self::assertNotSame(
            $this->generate($generator, $document, 'document-detail'),
            $this->generate($generator, $document, 'document-summary'),
        );
    }

    public function testSameScopeAndStateProduceSameTagAcrossReadAndWriteRequests(): void
    {
        $document = $this->createDocument();
        $generator = $this->generator();

        self::assertSame(
            $this->generate($generator, $document, 'document-detail', 'GET'),
            $this->generate($generator, $document, 'document-detail', 'PATCH'),
        );
    }

    /**
     * @throws Exception
     */
    public function testBinaryStringIdentifierBytesProduceAValidStrongTag(): void
    {
        $platformClass = $this->entityManager
    ->getConnection()
    ->getDatabasePlatform()::class;

        if (!str_ends_with(strtolower($platformClass), '\\sqliteplatform')) {
            $this->markTestSkipped(
                'The invalid-UTF8 text fixture is SQLite-specific; cross-database jobs cover the portable concurrency path.',
            );
        }

        $document = new BinaryIdentifierDocument("binary-\xFF-identifier");
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        self::assertMatchesRegularExpression(
            '/^"oc1-[A-Za-z0-9_-]{43}"$/',
            $this->generate($this->generator(), $document),
        );
    }

    public function testUnversionedEntityIsRejected(): void
    {
        $document = new UnversionedDocument('No version');
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not versioned');

        $this->generate($this->generator(), $document);
    }

    public function testTransientEntityWithoutIdentifierIsRejected(): void
    {
        $document = new Document('Transient');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no assigned identifier');

        $this->generate($this->generator(), $document);
    }

    public function testDetachedEntityIsRejected(): void
    {
        $document = $this->createDocument();
        $this->entityManager->detach($document);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not managed by Doctrine');

        $this->generate($this->generator(), $document);
    }

    private function generator(): EntityTagGenerator
    {
        $registry = $this->client->getContainer()->get('doctrine');

        if (!$registry instanceof ManagerRegistry) {
            throw new \LogicException('The functional test container does not expose a Doctrine ManagerRegistry.');
        }

        return new EntityTagGenerator(new VersionedEntityInspector($registry), $registry);
    }

    private function generate(
        EntityTagGenerator $generator,
        object $entity,
        ?string $scope = null,
        string $method = 'GET',
    ): string {
        return $generator->generate(
            $entity,
            new EntityTagContext(Request::create('/test-resource', $method), $scope),
        );
    }
}
