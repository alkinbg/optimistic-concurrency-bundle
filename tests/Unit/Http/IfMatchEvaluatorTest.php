<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Tests\Unit\Http;

use OptimisticConcurrency\Bundle\Http\IfMatchEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

final class IfMatchEvaluatorTest extends TestCase
{
    private IfMatchEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new IfMatchEvaluator();
    }

    public function testExactStrongTagMatches(): void
    {
        $this->assertFieldValuesMatch(['"current"'], '"current"');
    }

    public function testMatchingTagInAListMatches(): void
    {
        $this->assertFieldValuesMatch(['"old", W/"current", "current"'], '"current"');
    }

    public function testMultipleHeaderFieldLinesAreCombinedAsAList(): void
    {
        $this->assertFieldValuesMatch(['"old"', '"current"'], '"current"');
    }

    public function testWildcardMatchesAnExistingResource(): void
    {
        $this->assertFieldValuesMatch(["\t * \t"], '"current"');
    }

    public function testWildcardStillRequiresAValidProviderTag(): void
    {
        $condition = $this->evaluator->parse(['*']);

        $this->expectException(\LogicException::class);
        $this->evaluator->assertMatches($condition, 'W/"invalid-provider-tag"');
    }

    public function testWeakTagNeverStronglyMatches(): void
    {
        try {
            $this->assertFieldValuesMatch(['W/"current"'], '"current"');
            self::fail('Expected a precondition failure.');
        } catch (PreconditionFailedHttpException $exception) {
            self::assertSame('"current"', $exception->getHeaders()['ETag']);
            self::assertSame('no-store', $exception->getHeaders()['Cache-Control']);
        }
    }

    public function testOpaqueTagMayContainComma(): void
    {
        $this->assertFieldValuesMatch(['"old,still-one-tag", "current"'], '"current"');
    }

    #[DataProvider('toleratedEmptyListElements')]
    public function testReasonableEmptyListElementsAreIgnored(string $header): void
    {
        $this->assertFieldValuesMatch([$header], '"current"');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function toleratedEmptyListElements(): iterable
    {
        yield 'leading' => [', "current"'];
        yield 'trailing' => ['"current",'];
        yield 'interior' => ['"old", , "current"'];
        yield 'several with optional whitespace' => [", \t, \t\"current\" , ,"];
        yield 'tolerance budget boundary' => [str_repeat(',', 32).'"current"'];
    }

    #[DataProvider('emptyPresentHeaders')]
    public function testPresentHeaderWithNoEntityTagsFailsThePrecondition(string $header): void
    {
        try {
            $this->assertFieldValuesMatch([$header], '"current"');
            self::fail('Expected a precondition failure.');
        } catch (PreconditionFailedHttpException $exception) {
            self::assertSame(412, $exception->getStatusCode());
            self::assertSame('"current"', $exception->getHeaders()['ETag']);
            self::assertSame('no-store', $exception->getHeaders()['Cache-Control']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyPresentHeaders(): iterable
    {
        yield 'empty field value' => [''];
        yield 'optional whitespace only' => [" \t "];
        yield 'empty list elements only' => [' , , '];
    }

    public function testMissingHeaderRequiresAPrecondition(): void
    {
        try {
            $this->evaluator->parse([]);
            self::fail('Expected a precondition-required response.');
        } catch (PreconditionRequiredHttpException $exception) {
            self::assertSame(428, $exception->getStatusCode());
            self::assertSame('no-store', $exception->getHeaders()['Cache-Control']);
        }
    }

    public function testStaleTagReturnsCurrentEtag(): void
    {
        try {
            $this->assertFieldValuesMatch(['"stale"'], '"current"');
            self::fail('Expected a precondition failure.');
        } catch (PreconditionFailedHttpException $exception) {
            self::assertSame(412, $exception->getStatusCode());
            self::assertSame('"current"', $exception->getHeaders()['ETag']);
            self::assertSame('no-store', $exception->getHeaders()['Cache-Control']);
        }
    }

    public function testExcessiveEmptyListElementsAreRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('too many empty list elements');

        $this->evaluator->parse([str_repeat(',', 33).'"current"']);
    }

    public function testCanonicalStrongProviderTagIsAccepted(): void
    {
        $this->evaluator->assertValidStrongEntityTag('"current"');

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidProviderTags')]
    public function testProviderTagMustBeOneCanonicalStrongEntityTag(string $entityTag): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('EntityTagProviderInterface::generate()');

        $this->evaluator->assertValidStrongEntityTag($entityTag);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProviderTags(): iterable
    {
        yield 'empty' => [''];
        yield 'weak tag' => ['W/"current"'];
        yield 'unquoted tag' => ['current'];
        yield 'multiple tags' => ['"one", "two"'];
        yield 'trailing empty list element' => ['"current",'];
        yield 'wildcard' => ['*'];
        yield 'surrounding whitespace' => [' "current" '];
    }

    #[DataProvider('malformedHeaders')]
    public function testMalformedHeaderIsRejected(string $header): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->evaluator->parse([$header]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'bare token' => ['current'];
        yield 'unterminated quote' => ['"current'];
        yield 'wildcard mixed with list' => ['*, "current"'];
        yield 'wildcard after list' => ['"current", *'];
        yield 'lowercase weak prefix' => ['w/"current"'];
        yield 'control character in tag' => ["\"cur\nrent\""];
        yield 'garbage after tag' => ['"current" nope'];
    }

    /**
     * @param list<string> $fieldValues
     */
    private function assertFieldValuesMatch(array $fieldValues, string $currentEntityTag): void
    {
        $condition = $this->evaluator->parse($fieldValues);
        $this->evaluator->assertMatches($condition, $currentEntityTag);
        $this->addToAssertionCount(1);
    }
}
