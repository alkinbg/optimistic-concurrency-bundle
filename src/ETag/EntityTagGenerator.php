<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\ETag;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use OptimisticConcurrency\Bundle\Context\EntityTagContext;
use OptimisticConcurrency\Bundle\Contract\EntityTagProviderInterface;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityInspector;

/**
 * Default opaque validator derived from entity identity, version and scope.
 *
 * @internal
 */
final readonly class EntityTagGenerator implements EntityTagProviderInterface
{
    public function __construct(
        private VersionedEntityInspector $inspector,
        private ManagerRegistry $registry,
    ) {
    }

    public function generate(object $entity, EntityTagContext $context): string
    {
        $state = $this->inspector->inspect($entity);
        $seen = [];

        try {
            $canonical = json_encode(
                [
                    'entity' => $state->metadata->getName(),
                    'id' => $this->normalizeArray($state->identifier, $seen),
                    'version' => $this->normalizeValue($state->version, $seen),
                    'scope' => $this->normalizeValue($context->scope, $seen),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (\JsonException $exception) {
            throw new \LogicException('Unable to encode the optimistic-concurrency ETag payload.', 0, $exception);
        }

        $digest = hash('sha256', $canonical, true);
        $encoded = rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');

        return '"oc1-'.$encoded.'"';
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<int, true>        $seen
     *
     * @return array<string, mixed>
     */
    private function normalizeArray(array $values, array &$seen): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $normalized[(string) $key] = $this->normalizeValue($value, $seen);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param array<int, true> $seen
     *
     * @return array<string, mixed>
     */
    private function normalizeValue(mixed $value, array &$seen): array
    {
        return match (true) {
            null === $value => ['type' => 'null'],
            is_bool($value) => ['type' => 'bool', 'value' => $value],
            is_int($value) => ['type' => 'int', 'value' => (string) $value],
            is_float($value) => ['type' => 'float', 'value' => $this->normalizeFloat($value)],
            is_string($value) => ['type' => 'string', 'value' => $this->encodeBytes($value)],
            $value instanceof \BackedEnum => [
                'type' => 'enum',
                'class' => $value::class,
                'value' => $this->normalizeValue($value->value, $seen),
            ],
            $value instanceof \DateTimeInterface => [
                'type' => 'datetime',
                'class' => $value::class,
                'value' => $value->format('U.u'),
            ],
            is_array($value) => [
                'type' => 'array',
                'value' => $this->normalizeArray($value, $seen),
            ],
            is_object($value) => $this->normalizeObject($value, $seen),
            default => throw new \LogicException(sprintf('Cannot normalize a value of type "%s" for an optimistic-concurrency ETag.', get_debug_type($value))),
        };
    }

    /**
     * @param array<int, true> $seen
     *
     * @return array<string, mixed>
     */
    private function normalizeObject(object $value, array &$seen): array
    {
        $manager = $this->registry->getManagerForClass($value::class);

        if ($manager instanceof EntityManagerInterface) {
            $metadata = $manager->getClassMetadata($value::class);
            $identifier = $metadata->getIdentifierValues($value);

            if ([] === $identifier) {
                throw new \LogicException(sprintf('Identifier object "%s" has no assigned Doctrine identifier.', $metadata->getName()));
            }

            $objectId = spl_object_id($value);

            if (isset($seen[$objectId])) {
                throw new \LogicException(sprintf('A cyclic object identifier involving "%s" cannot be normalized for an optimistic-concurrency ETag.', $value::class));
            }

            $seen[$objectId] = true;

            try {
                return [
                    'type' => 'entity',
                    'class' => $metadata->getName(),
                    'id' => $this->normalizeArray($identifier, $seen),
                ];
            } finally {
                unset($seen[$objectId]);
            }
        }

        if ($value instanceof \Stringable) {
            return [
                'type' => 'stringable',
                'class' => $value::class,
                'value' => $this->encodeBytes((string) $value),
            ];
        }

        throw new \LogicException(sprintf('Cannot normalize an object of type "%s" for an optimistic-concurrency ETag.', $value::class));
    }

    private function normalizeFloat(float $value): string
    {
        if (!is_finite($value)) {
            throw new \LogicException('Cannot normalize a non-finite float for an optimistic-concurrency ETag.');
        }

        return bin2hex(pack('E', $value));
    }

    private function encodeBytes(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
