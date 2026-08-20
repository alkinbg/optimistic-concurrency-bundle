<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Http;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

/**
 * @internal
 */
final class IfMatchEvaluator
{
    private const MAX_IGNORED_EMPTY_LIST_ELEMENTS = 32;
    private const NO_STORE_HEADERS = ['Cache-Control' => 'no-store'];

    /**
     * Parses the client precondition before any entity-tag provider work occurs.
     *
     * @param list<string> $fieldValues
     */
    public function parse(array $fieldValues): IfMatchCondition
    {
        $this->assertPresent($fieldValues);
        $header = implode(',', $fieldValues);

        if ('*' === trim($header, " \t")) {
            return new IfMatchCondition(true, []);
        }

        return new IfMatchCondition(false, $this->parseEntityTags($header));
    }

    /**
     * @param list<string> $fieldValues
     */
    public function assertPresent(array $fieldValues): void
    {
        if ([] === $fieldValues) {
            throw new PreconditionRequiredHttpException('This request requires an If-Match header containing the current ETag.', null, 0, self::NO_STORE_HEADERS);
        }
    }

    public function assertMatches(IfMatchCondition $condition, string $currentEntityTag): void
    {
        $this->assertValidStrongEntityTag($currentEntityTag);

        if ($condition->wildcard) {
            return;
        }

        foreach ($condition->entityTags as $tag) {
            if (!$tag['weak'] && $tag['value'] === $currentEntityTag) {
                return;
            }
        }

        throw new PreconditionFailedHttpException('The resource has changed since it was retrieved. Fetch the latest representation and retry with its ETag.', null, 0, [...self::NO_STORE_HEADERS, 'ETag' => $currentEntityTag]);
    }

    public function assertValidStrongEntityTag(string $entityTag): void
    {
        try {
            $tags = $this->parseEntityTags($entityTag);
        } catch (BadRequestHttpException $exception) {
            throw new \LogicException('EntityTagProviderInterface::generate() must return one canonical strong entity-tag including surrounding quotes.', 0, $exception);
        }

        if (1 !== count($tags) || $tags[0]['weak'] || $tags[0]['value'] !== $entityTag) {
            throw new \LogicException('EntityTagProviderInterface::generate() must return one canonical strong entity-tag including surrounding quotes.');
        }
    }

    /**
     * @return list<array{weak: bool, value: string}>
     */
    private function parseEntityTags(string $header): array
    {
        $length = strlen($header);
        $offset = 0;
        $tags = [];
        $ignoredEmptyElements = 0;

        while (true) {
            $this->skipOptionalWhitespace($header, $offset, $length);

            if ($offset >= $length) {
                $this->ignoreEmptyListElement($ignoredEmptyElements);

                break;
            }

            if (',' === $header[$offset]) {
                $this->ignoreEmptyListElement($ignoredEmptyElements);
                ++$offset;

                continue;
            }

            if ('*' === $header[$offset]) {
                throw $this->malformed();
            }

            $weak = false;

            if ('W/' === substr($header, $offset, 2)) {
                $weak = true;
                $offset += 2;
            }

            if ($offset >= $length || '"' !== $header[$offset]) {
                throw $this->malformed();
            }

            $start = $offset;
            ++$offset;

            while ($offset < $length && '"' !== $header[$offset]) {
                $code = ord($header[$offset]);

                if (!$this->isEntityTagCharacter($code)) {
                    throw $this->malformed();
                }

                ++$offset;
            }

            if ($offset >= $length) {
                throw $this->malformed();
            }

            ++$offset;

            $strongValue = substr($header, $start, $offset - $start);
            $tags[] = [
                'weak' => $weak,
                'value' => $strongValue,
            ];

            $this->skipOptionalWhitespace($header, $offset, $length);

            if ($offset >= $length) {
                break;
            }

            if (',' !== $header[$offset]) {
                throw $this->malformed();
            }

            ++$offset;
        }

        return $tags;
    }

    private function ignoreEmptyListElement(int &$ignoredEmptyElements): void
    {
        ++$ignoredEmptyElements;

        if ($ignoredEmptyElements > self::MAX_IGNORED_EMPTY_LIST_ELEMENTS) {
            throw new BadRequestHttpException('The If-Match header contains too many empty list elements.', null, 0, self::NO_STORE_HEADERS);
        }
    }

    private function skipOptionalWhitespace(string $header, int &$offset, int $length): void
    {
        while ($offset < $length && (' ' === $header[$offset] || "\t" === $header[$offset])) {
            ++$offset;
        }
    }

    private function isEntityTagCharacter(int $code): bool
    {
        return 0x21 === $code || ($code >= 0x23 && $code <= 0x7E) || $code >= 0x80;
    }

    private function malformed(): BadRequestHttpException
    {
        return new BadRequestHttpException('The If-Match header is malformed.', null, 0, self::NO_STORE_HEADERS);
    }
}
