<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use OptimisticConcurrency\Bundle\Attribute\EntityTag;
use OptimisticConcurrency\Bundle\Attribute\RequireIfMatch;
use OptimisticConcurrency\Bundle\Contract\EntityTagProviderInterface;
use OptimisticConcurrency\Bundle\EventSubscriber\OptimisticConcurrencySubscriber;
use OptimisticConcurrency\Bundle\Http\IfMatchEvaluator;
use OptimisticConcurrency\Bundle\Internal\RequestConcurrencyContext;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityGuard;
use OptimisticConcurrency\Bundle\Internal\VersionedEntityInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class OptimisticConcurrencySubscriberTest extends TestCase
{
    public function testPreconditionCheckRunsAtDocumentedPriority(): void
    {
        $events = OptimisticConcurrencySubscriber::getSubscribedEvents();

        self::assertSame(
            ['onControllerArguments', -20000],
            $events[KernelEvents::CONTROLLER_ARGUMENTS],
        );
    }

    public function testOptimisticLockExceptionMappingRunsBeforeDefaultExceptionRendering(): void
    {
        $events = OptimisticConcurrencySubscriber::getSubscribedEvents();

        self::assertSame(
            ['onException', 64],
            $events[KernelEvents::EXCEPTION],
        );
    }

    public function testReadOnlyEntityTagDoesNotGenerateValidatorBeforeControllerRuns(): void
    {
        $provider = $this->createMock(EntityTagProviderInterface::class);
        $provider->expects($this->never())->method('generate');

        $controller = new class {
            #[EntityTag('entity')]
            public function show(object $entity): void
            {
            }
        };

        $event = new ControllerArgumentsEvent(
            $this->createStub(HttpKernelInterface::class),
            [$controller, 'show'],
            [new \stdClass()],
            Request::create('/resource'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new OptimisticConcurrencySubscriber(
            $provider,
            new IfMatchEvaluator(),
            $this->guard($this->createStub(ManagerRegistry::class)),
        ))->onControllerArguments($event);

        $this->addToAssertionCount(1);
    }

    public function testMissingIfMatchDoesNotGenerateOrInspectAValidator(): void
    {
        $provider = $this->createMock(EntityTagProviderInterface::class);
        $provider->expects($this->never())->method('generate');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->never())->method('getManagerForClass');

        $event = $this->writeEvent(new \stdClass(), Request::create('/resource', 'PATCH'));

        $this->expectException(PreconditionRequiredHttpException::class);

        (new OptimisticConcurrencySubscriber(
            $provider,
            new IfMatchEvaluator(),
            $this->guard($registry),
        ))->onControllerArguments($event);
    }

    public function testMalformedIfMatchDoesNotGenerateOrInspectAValidator(): void
    {
        $provider = $this->createMock(EntityTagProviderInterface::class);
        $provider->expects($this->never())->method('generate');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->never())->method('getManagerForClass');

        $request = Request::create('/resource', 'PATCH');
        $request->headers->set('If-Match', 'not-an-etag');

        $this->expectException(BadRequestHttpException::class);

        (new OptimisticConcurrencySubscriber(
            $provider,
            new IfMatchEvaluator(),
            $this->guard($registry),
        ))->onControllerArguments($this->writeEvent(new \stdClass(), $request));
    }

    public function testCustomProviderCannotBypassDoctrineVersionRequirement(): void
    {
        $entity = new \stdClass();
        $provider = $this->createMock(EntityTagProviderInterface::class);
        $provider->expects($this->never())->method('generate');

        $metadata = new ClassMetadata($entity::class);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturn($metadata);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        $request = Request::create('/resource', 'PATCH');
        $request->headers->set('If-Match', '"current"');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not versioned');

        (new OptimisticConcurrencySubscriber(
            $provider,
            new IfMatchEvaluator(),
            $this->guard($registry),
        ))->onControllerArguments($this->writeEvent($entity, $request));
    }

    public function testOptimisticLockFailureForProtectedEntityBecomesPreconditionFailure(): void
    {
        $entity = new \stdClass();
        $exception = OptimisticLockException::lockFailed($entity);
        $event = $this->exceptionEvent($entity, $exception);

        $this->subscriber()->onException($event);

        $mapped = $event->getThrowable();

        if (!$mapped instanceof PreconditionFailedHttpException) {
            self::fail(sprintf('Expected %s, got %s.', PreconditionFailedHttpException::class, $mapped::class));
        }

        self::assertSame($exception, $mapped->getPrevious());
        self::assertSame('no-store', $mapped->getHeaders()['Cache-Control']);
    }

    public function testOptimisticLockFailureForAnotherEntityIsNotReclassified(): void
    {
        $entity = new \stdClass();
        $exception = OptimisticLockException::lockFailed(new \stdClass());
        $event = $this->exceptionEvent($entity, $exception);

        $this->subscriber()->onException($event);

        self::assertSame($exception, $event->getThrowable());
    }

    public function testClassOnlyOptimisticLockFailureIsNotReclassified(): void
    {
        $entity = new \stdClass();
        $exception = OptimisticLockException::lockFailed($entity::class);
        $event = $this->exceptionEvent($entity, $exception);

        $this->subscriber()->onException($event);

        self::assertSame($exception, $event->getThrowable());
    }

    private function subscriber(): OptimisticConcurrencySubscriber
    {
        return new OptimisticConcurrencySubscriber(
            $this->createStub(EntityTagProviderInterface::class),
            new IfMatchEvaluator(),
            $this->guard($this->createStub(ManagerRegistry::class)),
        );
    }

    private function guard(ManagerRegistry $registry): VersionedEntityGuard
    {
        return new VersionedEntityGuard(new VersionedEntityInspector($registry));
    }

    private function writeEvent(object $entity, Request $request): ControllerArgumentsEvent
    {
        $controller = new class {
            #[RequireIfMatch('entity')]
            public function update(object $entity): void
            {
            }
        };

        return new ControllerArgumentsEvent(
            $this->createStub(HttpKernelInterface::class),
            [$controller, 'update'],
            [$entity],
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function exceptionEvent(object $entity, OptimisticLockException $exception): ExceptionEvent
    {
        $request = Request::create('/resource', 'PATCH');
        $request->attributes->set(
            '_optimistic_concurrency.context',
            new RequestConcurrencyContext($entity, true, null),
        );

        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
