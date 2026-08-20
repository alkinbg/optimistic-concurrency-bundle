<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Internal;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Owns the single source of truth for the Doctrine state required by the bundle.
 *
 * @internal
 */
final readonly class VersionedEntityInspector
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function inspect(object $entity): VersionedEntityState
    {
        $manager = $this->entityManagerFor($entity);
        $metadata = $manager->getClassMetadata($entity::class);
        $versionField = $metadata->versionField;

        if (!$metadata->isVersioned || null === $versionField) {
            throw new \LogicException(sprintf('The entity "%s" is not versioned. Add a Doctrine #[ORM\\Version] field before using optimistic HTTP concurrency.', $metadata->getName()));
        }

        $identifier = $metadata->getIdentifierValues($entity);

        if ([] === $identifier) {
            throw new \LogicException(sprintf('The entity "%s" has no assigned identifier. ETags can only be generated for persisted resources.', $metadata->getName()));
        }

        if (!$manager->contains($entity)) {
            throw new \LogicException(sprintf('The entity "%s" is not managed by Doctrine. ETags can only be generated from the currently managed resource state.', $metadata->getName()));
        }

        try {
            $version = $metadata->getFieldValue($entity, $versionField);
        } catch (\Error $exception) {
            throw new \LogicException(sprintf('The version field "%s" on entity "%s" is not initialized.', $versionField, $metadata->getName()), 0, $exception);
        }

        if (null === $version) {
            throw new \LogicException(sprintf('The version field "%s" on entity "%s" is not initialized.', $versionField, $metadata->getName()));
        }

        return new VersionedEntityState($metadata, $identifier, $version);
    }

    private function entityManagerFor(object $entity): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass($entity::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException(sprintf('No Doctrine ORM entity manager manages "%s".', $entity::class));
        }

        return $manager;
    }
}
