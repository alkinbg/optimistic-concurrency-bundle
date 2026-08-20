<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\EventSubscriber;

use Doctrine\ORM\OptimisticLockException;
use OptimisticConcurrency\Bundle\Attribute\EntityTag;
use OptimisticConcurrency\Bundle\Attribute\RequireIfMatch;
use OptimisticConcurrency\Bundle\Context\EntityTagContext;
use OptimisticConcurrency\Bundle\Contract\EntityTagProviderInterface;
use OptimisticConcurrency\Bundle\Http\IfMatchEvaluator;
use OptimisticConcurrency\Bundle\Internal\RequestConcurrencyContext;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @internal
 */
final readonly class OptimisticConcurrencySubscriber implements EventSubscriberInterface
{
    private const CONTEXT_ATTRIBUTE = '_optimistic_concurrency.context';
    private const PRECONDITION_PRIORITY = -20000;

    public function __construct(
        private EntityTagProviderInterface $entityTagProvider,
        private IfMatchEvaluator $ifMatchEvaluator,
        private VersionedEntityGuard $versionedEntityGuard,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Authorization must complete before a validator can be derived or
            // disclosed. The functional suite verifies this ordering against
            // Symfony Security on every supported dependency line.
            KernelEvents::CONTROLLER_ARGUMENTS => ['onControllerArguments', self::PRECONDITION_PRIORITY],
            KernelEvents::RESPONSE => ['onResponse', 0],
            KernelEvents::EXCEPTION => ['onException', 64],
        ];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $entityTagAttributes = $event->getAttributes(EntityTag::class);
        $requireIfMatchAttributes = $event->getAttributes(RequireIfMatch::class);

        if ([] === $entityTagAttributes && [] === $requireIfMatchAttributes) {
            return;
        }

        if (count($entityTagAttributes) > 1 || count($requireIfMatchAttributes) > 1) {
            throw new \LogicException('Only one optimistic-concurrency attribute of each type may be used on a controller.');
        }

        if ([] !== $entityTagAttributes && [] !== $requireIfMatchAttributes) {
            throw new \LogicException('Do not combine #[EntityTag] and #[RequireIfMatch] on the same controller. #[RequireIfMatch] already emits the updated ETag.');
        }

        $attribute = $requireIfMatchAttributes[0] ?? $entityTagAttributes[0];
        $requireIfMatch = $attribute instanceof RequireIfMatch;
        $arguments = $event->getNamedArguments();

        if (!array_key_exists($attribute->argument, $arguments)) {
            throw new \LogicException(sprintf('Controller argument "$%s" referenced by #[%s] does not exist.', $attribute->argument, $requireIfMatch ? 'RequireIfMatch' : 'EntityTag'));
        }

        $entity = $arguments[$attribute->argument];

        if (!is_object($entity)) {
            throw new \LogicException(sprintf('Controller argument "$%s" referenced by #[%s] must be a Doctrine ORM entity object, %s given.', $attribute->argument, $requireIfMatch ? 'RequireIfMatch' : 'EntityTag', get_debug_type($entity)));
        }

        if ($requireIfMatch && 'DELETE' === $event->getRequest()->getMethod()) {
            throw new \LogicException('#[RequireIfMatch] does not support DELETE because Doctrine ORM does not include the version field in its standard DELETE statement. Use an application-specific atomic delete strategy.');
        }

        if ($requireIfMatch) {
            // Parse client syntax first so malformed input cannot invoke Doctrine
            // or custom-provider work. Once syntax is valid, still preflight the
            // provider for wildcard conditions: a broken provider must fail before
            // a controller can flush a mutation and only then discover the error.
            $condition = $this->ifMatchEvaluator->parse($this->ifMatchFieldValues($event->getRequest()));
            $this->ifMatchEvaluator->assertMatches(
                $condition,
                $this->generateEntityTag(
                    $entity,
                    new EntityTagContext($event->getRequest(), $attribute->scope),
                ),
            );
        }

        $event->getRequest()->attributes->set(
            self::CONTEXT_ATTRIBUTE,
            new RequestConcurrencyContext($entity, $requireIfMatch, $attribute->scope),
        );
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $context = $this->context($event->getRequest());

        if (null === $context) {
            return;
        }

        $statusCode = $event->getResponse()->getStatusCode();

        if (($statusCode < 200 || $statusCode >= 300) && 304 !== $statusCode) {
            return;
        }

        $event->getResponse()->setEtag($this->generateEntityTag(
            $context->entity,
            new EntityTagContext($event->getRequest(), $context->scope),
        ));
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $context = $this->context($event->getRequest());
        $throwable = $event->getThrowable();

        if (
            null === $context
            || !$context->requireIfMatch
            || !$throwable instanceof OptimisticLockException
            || $throwable->getEntity() !== $context->entity
        ) {
            return;
        }

        $event->setThrowable(new PreconditionFailedHttpException(
            'The resource changed while this request was being processed. Fetch the latest representation and retry with its ETag.',
            $throwable,
            0,
            ['Cache-Control' => 'no-store'],
        ));
    }

    private function generateEntityTag(object $entity, EntityTagContext $context): string
    {
        $this->versionedEntityGuard->assertCanProtect($entity);

        $entityTag = $this->entityTagProvider->generate($entity, $context);
        $this->ifMatchEvaluator->assertValidStrongEntityTag($entityTag);

        return $entityTag;
    }

    /**
     * @return list<string>
     */
    private function ifMatchFieldValues(Request $request): array
    {
        $values = [];

        foreach ($request->headers->all('if-match') as $value) {
            $values[] = $value ?? '';
        }

        return $values;
    }

    private function context(Request $request): ?RequestConcurrencyContext
    {
        $context = $request->attributes->get(self::CONTEXT_ATTRIBUTE);

        return $context instanceof RequestConcurrencyContext ? $context : null;
    }
}
