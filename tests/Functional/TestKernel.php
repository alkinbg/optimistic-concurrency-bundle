<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Functional;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use OptimisticConcurrency\Bundle\Contract\EntityTagProviderInterface;
use OptimisticConcurrency\Bundle\OptimisticConcurrencyBundle;
use OptimisticConcurrency\Bundle\Tests\Functional\Fixture\RecordingEntityTagProvider;
use OptimisticConcurrency\Bundle\Tests\Functional\Fixture\TestController;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new OptimisticConcurrencyBundle();
    }

    public static function testBaseDir(): string
    {
        return sys_get_temp_dir().'/optimistic-concurrency-bundle/'.getmypid();
    }

    public function getCacheDir(): string
    {
        return self::testBaseDir().'/cache';
    }

    public function getLogDir(): string
    {
        return self::testBaseDir().'/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'optimistic-concurrency-bundle-test',
            'test' => true,
            'router' => [
                'utf8' => true,
            ],
        ]);

        $container->extension('security', [
            'providers' => [
                'users_in_memory' => [
                    'memory' => null,
                ],
            ],
            'firewalls' => [
                'main' => [
                    'lazy' => true,
                    'provider' => 'users_in_memory',
                ],
            ],
        ]);

        $databaseUrl = getenv('OPTIMISTIC_CONCURRENCY_DATABASE_URL');
        $dbal = is_string($databaseUrl) && '' !== trim($databaseUrl)
            ? ['url' => $databaseUrl]
            : [
                'driver' => 'pdo_sqlite',
                'path' => '%kernel.cache_dir%/database.sqlite',
            ];

        $container->extension('doctrine', [
            'dbal' => $dbal,
            'orm' => [
                'mappings' => [
                    'OptimisticConcurrencyBundleTests' => [
                        'type' => 'attribute',
                        'dir' => __DIR__.'/Fixture',
                        'prefix' => 'OptimisticConcurrency\\Bundle\\Tests\\Functional\\Fixture',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        $services = $container->services();

        // Expected 4xx precondition exceptions are asserted by the functional
        // tests. Discard kernel exception logs so PHPUnit's strict output checks
        // remain enabled without treating those expected logs as output.
        $services->set('logger', NullLogger::class);

        $services->set(RecordingEntityTagProvider::class)
            ->autowire()
            ->public();
        $services->alias(EntityTagProviderInterface::class, RecordingEntityTagProvider::class);

        $services->set(TestController::class)
            ->autowire()
            ->autoconfigure()
            ->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('document_show', '/documents/{id}')
            ->controller([TestController::class, 'show'])
            ->methods(['GET']);

        $routes->add('document_update', '/documents/{id}')
            ->controller([TestController::class, 'update'])
            ->methods(['PATCH']);

        $routes->add('document_secured_update', '/documents/{id}/secured')
            ->controller([TestController::class, 'securedUpdate'])
            ->methods(['PATCH']);

        $routes->add('document_delete', '/documents/{id}')
            ->controller([TestController::class, 'delete'])
            ->methods(['DELETE']);

        $routes->add('document_race', '/documents/{id}/race')
            ->controller([TestController::class, 'race'])
            ->methods(['PATCH']);
    }
}
