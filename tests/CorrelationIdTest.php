<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests;

use Rasuvaeff\Context\Http\CorrelationId;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CorrelationId::class)]
final class CorrelationIdTest
{
    #[DataProvider('validProvider')]
    public function acceptsLogSafeValues(string $value): void
    {
        Assert::true(CorrelationId::isValid($value));
        Assert::same((new CorrelationId($value))->value, $value);
    }

    public static function validProvider(): iterable
    {
        yield 'uuid' => ['0b5c3a6e-1f2d-4c8e-9a7b-3d2e1f0a9b8c'];
        yield 'all allowed characters' => ['Az09._:-'];
        yield 'single character' => ['x'];
        yield 'maximum length' => [str_repeat('a', 128)];
    }

    #[DataProvider('invalidProvider')]
    public function rejectsEverythingElse(string $value): void
    {
        Assert::false(CorrelationId::isValid($value));

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Correlation ID must be 1-128 characters');

        new CorrelationId($value);
    }

    public static function invalidProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'space' => ['a b'];
        yield 'trailing newline' => ["abc\n"];
        yield 'log injection' => ["abc\r\nlevel=error"];
        yield 'non-ascii' => ['идентификатор'];
        yield 'slash' => ['a/b'];
    }

    public function generatesThirtyTwoHexCharacters(): void
    {
        $first = CorrelationId::generate();
        $second = CorrelationId::generate();

        Assert::same(preg_match('/^[0-9a-f]{32}\z/', $first->value), 1);
        Assert::true($first->value !== $second->value);
    }

    /**
     * Valid and invalid strings come from one generator; validity is decided
     * by the documented charset and length, not by the code under test.
     */
    #[Property(runs: 300)]
    public function isValidAcceptsExactlyTheLogSafeCharsetUpTo128Characters(string $value): void
    {
        $expected = preg_match('/^[A-Za-z0-9._:-]{1,128}\z/', $value) === 1;

        Assert::same(CorrelationId::isValid($value), $expected);

        Classify::cover($expected, 'accepted', 20);
        Classify::cover(!$expected, 'rejected', 20);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function isValidAcceptsExactlyTheLogSafeCharsetUpTo128CharactersGenerators(): array
    {
        return [
            'value' => Gen::frequency([
                [3, Gen::regex('[A-Za-z0-9._:-]{1,40}')],
                [1, Gen::stringFrom('ab:-', 120, 136)],
                [1, Gen::stringFrom("ab9 /\n\r\t=\"", 0, 12)],
                [1, Gen::naughtyString()],
            ]),
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function isValidAcceptsExactlyTheLogSafeCharsetUpTo128CharactersExamples(): iterable
    {
        yield 'trailing newline' => ["abc\n"];
        yield 'exactly 128' => [str_repeat('a', 128)];
        yield '129' => [str_repeat('a', 129)];
        yield 'empty' => [''];
    }
}
