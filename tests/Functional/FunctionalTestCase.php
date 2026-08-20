<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use OptimisticConcurrency\Bundle\Tests\Functional\Fixture\Document;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

abstract class FunctionalTestCase extends TestCase
{
    protected TestKernel $kernel;
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->removeDirectory(TestKernel::testBaseDir());

        $this->kernel = new TestKernel('test', false);
        $this->kernel->boot();
        $this->client = new KernelBrowser($this->kernel);

        $registry = $this->client->getContainer()->get('doctrine');

        if (!$registry instanceof ManagerRegistry) {
            throw new \LogicException('The functional test container does not expose a Doctrine ManagerRegistry.');
        }

        $entityManager = $registry->getManager();

        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('The functional test Doctrine manager is not an ORM EntityManagerInterface.');
        }

        $this->entityManager = $entityManager;

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->metadata());
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager)) {
                // The intentional optimistic-lock race may leave the EntityManager
                // closed or unsuitable for further ORM work. Its metadata and DBAL
                // connection remain available for schema cleanup on the supported
                // Doctrine paths, so drop the schema before closing an open manager.
                (new SchemaTool($this->entityManager))->dropSchema($this->metadata());

                if ($this->entityManager->isOpen()) {
                    $this->entityManager->close();
                }
            }

            if (isset($this->kernel)) {
                $this->kernel->shutdown();
            }

            $this->removeDirectory(TestKernel::testBaseDir());
        } finally {
            parent::tearDown();
        }
    }

    protected function createDocument(string $title = 'Original'): Document
    {
        $document = new Document($title);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    /**
     * @return list<\Doctrine\ORM\Mapping\ClassMetadata<object>>
     */
    private function metadata(): array
    {
        return $this->entityManager->getMetadataFactory()->getAllMetadata();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                throw new \LogicException('Recursive directory cleanup yielded a non-file entry.');
            }

            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
