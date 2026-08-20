<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Unit\Context;

use OptimisticConcurrency\Bundle\Context\EntityTagContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EntityTagContextTest extends TestCase
{
    public function testContextPreservesRequestAndNormalizesScope(): void
    {
        $request = Request::create('/documents/1?view=full', 'PATCH');
        $context = new EntityTagContext($request, ' detail ');

        self::assertSame($request, $context->request);
        self::assertSame('detail', $context->scope);
    }

    public function testScopeIsOptional(): void
    {
        $context = new EntityTagContext(Request::create('/documents/1'));

        self::assertNull($context->scope);
    }

    public function testEmptyScopeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EntityTagContext(Request::create('/'), ' ');
    }
}
