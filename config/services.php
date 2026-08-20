<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use OptimisticConcurrency\Bundle\Contract\EntityTagProviderInterface;
use OptimisticConcurrency\Bundle\ETag\EntityTagGenerator;
use OptimisticConcurrency\Bundle\EventSubscriber\OptimisticConcurrencySubscriber;
use OptimisticConcurrency\Bundle\Http\IfMatchEvaluator;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityGuard;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityInspector;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(VersionedEntityInspector::class)
        ->arg('$registry', service('doctrine'));

    $services->set(EntityTagGenerator::class)
        ->arg('$registry', service('doctrine'));

    $services->alias(EntityTagProviderInterface::class, EntityTagGenerator::class);

    $services->set(IfMatchEvaluator::class);
    $services->set(VersionedEntityGuard::class);
    $services->set(OptimisticConcurrencySubscriber::class);
};
