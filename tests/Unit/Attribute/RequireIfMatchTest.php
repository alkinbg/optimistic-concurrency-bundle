<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Unit\Attribute;

use OptimisticConcurrency\Bundle\Attribute\RequireIfMatch;
use PHPUnit\Framework\TestCase;

final class RequireIfMatchTest extends TestCase
{
    public function testArgumentNameIsStored(): void
    {
        $attribute = new RequireIfMatch('document');

        self::assertSame('document', $attribute->argument);
        self::assertNull($attribute->scope);
    }

    public function testArgumentNameAndScopeAreTrimmed(): void
    {
        $attribute = new RequireIfMatch('  document  ', '  document-detail  ');

        self::assertSame('document', $attribute->argument);
        self::assertSame('document-detail', $attribute->scope);
    }

    public function testEmptyArgumentNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RequireIfMatch('   ');
    }

    public function testEmptyScopeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RequireIfMatch('document', '   ');
    }
}
