<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Architecture;

use OptimisticConcurrency\Bundle\Attribute\EntityTag;
use OptimisticConcurrency\Bundle\Attribute\RequireIfMatch;
use OptimisticConcurrency\Bundle\Context\EntityTagContext;
use OptimisticConcurrency\Bundle\Contract\EntityTagProviderInterface;
use OptimisticConcurrency\Bundle\ETag\EntityTagGenerator;
use OptimisticConcurrency\Bundle\EventSubscriber\OptimisticConcurrencySubscriber;
use OptimisticConcurrency\Bundle\Http\IfMatchCondition;
use OptimisticConcurrency\Bundle\Http\IfMatchEvaluator;
use OptimisticConcurrency\Bundle\Internal\RequestConcurrencyContext;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityGuard;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityInspector;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityState;
use OptimisticConcurrency\Bundle\OptimisticConcurrencyBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PublicApiTest extends TestCase
{
    /**
     * @param class-string $class
     *
     * @throws \ReflectionException
     */
    #[DataProvider('publicApiClassProvider')]
    public function testSupportedPublicApiIsNotMarkedInternal(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        self::assertStringNotContainsString(
            '@internal',
            $reflection->getDocComment() ?: '',
            sprintf('%s is part of the supported public API.', $class),
        );
    }

    /**
     * @param class-string $class
     *
     * @throws \ReflectionException
     */
    #[DataProvider('internalClassProvider')]
    public function testImplementationClassesRemainInternal(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        self::assertStringContainsString(
            '@internal',
            $reflection->getDocComment() ?: '',
            sprintf('%s must remain an internal implementation detail.', $class),
        );
    }

    /**
     * Locks the 1.0 provider signature so accidental source-BC breaks fail CI.
     *
     * @throws \ReflectionException
     */
    public function testEntityTagProviderContractSignatureIsStable(): void
    {
        $method = new \ReflectionMethod(EntityTagProviderInterface::class, 'generate');
        $parameters = $method->getParameters();

        self::assertSame('string', (string) $method->getReturnType());
        self::assertCount(2, $parameters);
        self::assertSame('object', (string) $parameters[0]->getType());
        self::assertSame(EntityTagContext::class, (string) $parameters[1]->getType());
        self::assertFalse($parameters[0]->isOptional());
        self::assertFalse($parameters[1]->isOptional());
    }

    /**
     * @throws \ReflectionException
     */
    public function testAttributeConstructorsKeepTheirStableShape(): void
    {
        foreach ([EntityTag::class, RequireIfMatch::class] as $attributeClass) {
            $constructor = (new \ReflectionClass($attributeClass))->getConstructor();

            self::assertNotNull($constructor);
            $parameters = $constructor->getParameters();
            self::assertCount(2, $parameters);
            self::assertSame('argument', $parameters[0]->getName());
            self::assertSame('string', (string) $parameters[0]->getType());
            self::assertFalse($parameters[0]->isOptional());
            self::assertSame('scope', $parameters[1]->getName());
            self::assertSame('?string', (string) $parameters[1]->getType());
            self::assertTrue($parameters[1]->isOptional());
            self::assertNull($parameters[1]->getDefaultValue());
        }
    }

    /**
     * @throws \ReflectionException
     */
    public function testEntityTagContextConstructorKeepsItsStableShape(): void
    {
        $constructor = (new \ReflectionClass(EntityTagContext::class))->getConstructor();

        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        self::assertCount(2, $parameters);
        self::assertSame('request', $parameters[0]->getName());
        self::assertSame(Request::class, (string) $parameters[0]->getType());
        self::assertFalse($parameters[0]->isOptional());
        self::assertSame('scope', $parameters[1]->getName());
        self::assertSame('?string', (string) $parameters[1]->getType());
        self::assertTrue($parameters[1]->isOptional());
        self::assertNull($parameters[1]->getDefaultValue());
    }

    /**
     * @throws \ReflectionException
     */
    public function testPublicReadonlyPropertiesKeepTheirStableShape(): void
    {
        $expected = [
            EntityTag::class => [
                'argument' => 'string',
                'scope' => '?string',
            ],
            RequireIfMatch::class => [
                'argument' => 'string',
                'scope' => '?string',
            ],
            EntityTagContext::class => [
                'request' => Request::class,
                'scope' => '?string',
            ],
        ];

        foreach ($expected as $class => $properties) {
            $reflection = new \ReflectionClass($class);

            foreach ($properties as $name => $type) {
                $property = $reflection->getProperty($name);

                self::assertTrue($property->isPublic(), sprintf('%s::$%s must remain public.', $class, $name));
                self::assertTrue($property->isReadOnly(), sprintf('%s::$%s must remain readonly.', $class, $name));
                self::assertSame($type, (string) $property->getType());
            }
        }
    }

    public function testLegacyBundleBrandingIsAbsentFromReleaseSources(): void
    {
        $projectRoot = \dirname(__DIR__, 2);
        $legacyNeedles = [
            'Alkin\\OptimisticLockBundle',
            'Alkin\OptimisticConcurrencyBundle',
            'Alkin\\OptimisticConcurrencyBundle',
            'OptimisticLockBundle',
            'Optimistic Lock Bundle',
            'optimistic-lock-bundle',
            'OPTIMISTIC_LOCK_DATABASE_URL',
            '_alkin_optimistic_concurrency.context',
            'ol1-',
        ];
        $files = [
            $projectRoot.'/composer.json',
            $projectRoot.'/README.md',
            $projectRoot.'/CHANGELOG.md',
            $projectRoot.'/CONTRIBUTING.md',
            $projectRoot.'/SECURITY.md',
            $projectRoot.'/phpunit.xml.dist',
            $projectRoot.'/phpstan.neon',
            $projectRoot.'/.php-cs-fixer.dist.php',
            $projectRoot.'/.gitattributes',
        ];

        foreach (['src', 'config', 'tests', '.github'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($projectRoot.'/'.$directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach ($files as $file) {
            $contents = \file_get_contents($file);

            self::assertIsString($contents, sprintf('Unable to read %s.', $file));

            foreach ($legacyNeedles as $legacyNeedle) {
                self::assertStringNotContainsString(
                    $legacyNeedle,
                    $contents,
                    sprintf('Legacy bundle branding remains in %s.', $file),
                );
            }
        }

        self::assertFileExists($projectRoot.'/src/OptimisticConcurrencyBundle.php');
        self::assertFileExists($projectRoot.'/src/EventSubscriber/OptimisticConcurrencySubscriber.php');
        self::assertFileExists($projectRoot.'/tests/Functional/OptimisticConcurrencyBundleTest.php');
        self::assertFileExists($projectRoot.'/tests/Unit/EventSubscriber/OptimisticConcurrencySubscriberTest.php');

        self::assertFileDoesNotExist($projectRoot.'/src/OptimisticLockBundle.php');
        self::assertFileDoesNotExist($projectRoot.'/src/EventSubscriber/OptimisticLockSubscriber.php');
        self::assertFileDoesNotExist($projectRoot.'/tests/Functional/OptimisticLockBundleTest.php');
        self::assertFileDoesNotExist($projectRoot.'/tests/Unit/EventSubscriber/OptimisticLockSubscriberTest.php');
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function publicApiClassProvider(): iterable
    {
        yield 'bundle' => [OptimisticConcurrencyBundle::class];
        yield 'entity tag attribute' => [EntityTag::class];
        yield 'If-Match attribute' => [RequireIfMatch::class];
        yield 'entity tag context' => [EntityTagContext::class];
        yield 'entity tag provider contract' => [EntityTagProviderInterface::class];
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function internalClassProvider(): iterable
    {
        yield 'default entity tag generator' => [EntityTagGenerator::class];
        yield 'parsed If-Match condition' => [IfMatchCondition::class];
        yield 'If-Match evaluator' => [IfMatchEvaluator::class];
        yield 'event subscriber' => [OptimisticConcurrencySubscriber::class];
        yield 'request concurrency context' => [RequestConcurrencyContext::class];
        yield 'versioned entity guard' => [VersionedEntityGuard::class];
        yield 'versioned entity inspector' => [VersionedEntityInspector::class];
        yield 'versioned entity state' => [VersionedEntityState::class];
    }
}
