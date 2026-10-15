<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests;

use Rasuvaeff\Context\Http\Internal\Baggage;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Baggage::class)]
final class BaggageTest
{
    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('parseProvider')]
    public function parsesWellFormedMembersOnly(string $header, array $expected): void
    {
        Assert::same(Baggage::parse($header), $expected);
    }

    public static function parseProvider(): iterable
    {
        yield 'empty' => ['', []];
        yield 'one member' => ['tenant=acme', ['tenant' => 'acme']];
        yield 'spaces around members and the equals sign' => [" tenant = acme ,\tlocale=de ", ['tenant' => 'acme', 'locale' => 'de']];
        yield 'properties are ignored' => ['tenant=acme;ttl=30;secret, locale=de', ['tenant' => 'acme', 'locale' => 'de']];
        yield 'percent-decoded' => ['note=a%2Cb%3Bc%20d%E2%82%AC', ['note' => "a,b;c d\u{20ac}"]];
        yield 'empty value' => ['flag=', ['flag' => '']];
        yield 'first of duplicate keys wins' => ['tenant=first,tenant=second', ['tenant' => 'first']];
        yield 'a member without = is skipped' => ['tenant,locale=de', ['locale' => 'de']];
        yield 'an invalid key is skipped' => ['ten ant=acme,(x)=y,locale=de', ['locale' => 'de']];
        yield 'a space inside a value is skipped' => ['note=a b,locale=de', ['locale' => 'de']];
        yield 'a quote or backslash in a value is skipped' => ['a="x",b=c\\d,locale=de', ['locale' => 'de']];
        yield 'empty members are tolerated' => [',,tenant=acme,,', ['tenant' => 'acme']];
        yield 'an oversized header reads as empty' => ['tenant=acme,' . str_repeat('x', Baggage::MAX_BYTES), []];
    }

    public function mergeAppendsAndKeepsOtherPropagatorsVerbatim(): void
    {
        $existing = 'sentry-trace_id=abc;prop=1, userId=alice';

        Assert::same(
            Baggage::merge($existing, ['tenant' => 'acme', 'locale' => 'de']),
            'sentry-trace_id=abc;prop=1,userId=alice,tenant=acme,locale=de',
        );
    }

    public function mergeNeverOverridesAKeyAlreadyPresent(): void
    {
        Assert::same(Baggage::merge('tenant=theirs', ['tenant' => 'ours', 'locale' => 'de']), 'tenant=theirs,locale=de');
    }

    public function mergeIntoNothing(): void
    {
        Assert::same(Baggage::merge('', ['tenant' => 'acme']), 'tenant=acme');
        Assert::same(Baggage::merge('', []), '');
        Assert::same(Baggage::merge('tenant=acme', []), 'tenant=acme');
    }

    public function mergePercentEncodesValues(): void
    {
        Assert::same(Baggage::merge('', ['note' => "a,b;c d\u{20ac}"]), 'note=a%2Cb%3Bc%20d%E2%82%AC');
    }

    public function mergeSkipsAnInvalidKey(): void
    {
        Assert::same(Baggage::merge('', ['ten ant' => 'x', 'locale' => 'de']), 'locale=de');
    }

    public function mergeRespectsTheByteLimit(): void
    {
        $existing = 'big=' . str_repeat('x', Baggage::MAX_BYTES - 20);

        $merged = Baggage::merge($existing, ['huge' => str_repeat('y', 100), 'ok' => '1']);

        Assert::same($merged, $existing . ',ok=1');
        Assert::true(\strlen($merged) <= Baggage::MAX_BYTES);
    }

    public function mergeFillsUpToExactlyTheByteLimit(): void
    {
        $existing = 'big=' . str_repeat('x', Baggage::MAX_BYTES - 4 - 7);

        Assert::same(\strlen(Baggage::merge($existing, ['k' => '1234'])), Baggage::MAX_BYTES);
        Assert::same(Baggage::merge($existing, ['k' => '12345']), $existing);
    }

    public function mergeRespectsTheMemberLimit(): void
    {
        $members = [];
        for ($i = 0; $i < Baggage::MAX_MEMBERS - 1; $i++) {
            $members[] = 'k' . $i . '=v';
        }

        $merged = Baggage::merge(implode(',', $members), ['first' => '1', 'second' => '2']);

        Assert::same(\count(explode(',', $merged)), Baggage::MAX_MEMBERS);
        Assert::true(str_ends_with($merged, ',first=1'));
    }

    public function mergeDropsAnOversizedExistingHeader(): void
    {
        Assert::same(Baggage::merge(str_repeat('x', Baggage::MAX_BYTES + 1), ['tenant' => 'acme']), 'tenant=acme');
    }

    /**
     * @param array<string, string> $values
     */
    #[Property(runs: 300)]
    public function whatIsMergedIsReadBack(array $values, string $existingKey): void
    {
        $existing = $existingKey . '=theirs';

        $parsed = Baggage::parse(Baggage::merge($existing, $values));

        $expected = [$existingKey => 'theirs'];
        foreach ($values as $key => $value) {
            $expected[$key] ??= $value;
        }
        Assert::same($parsed, $expected);

        Classify::cover(\array_key_exists($existingKey, $values), 'a key collides with an existing member', 5);
        Classify::cover($values !== [] && !\array_key_exists($existingKey, $values), 'no collision', 20);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function whatIsMergedIsReadBackGenerators(): array
    {
        $key = Gen::elements(['tenant', 'locale', 'flag', 'user.id', 'a-b_c']);

        return [
            'values' => Gen::dictOf($key, Gen::string(), 0, 5),
            'existingKey' => $key,
        ];
    }
}
