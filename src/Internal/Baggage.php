<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Internal;

/**
 * The W3C `baggage` header: `key=value;property, key2=value2`. Values are
 * percent-encoded; properties are kept on members we pass through and ignored
 * on read. The spec's propagation limits (8192 bytes, 180 members) bound
 * both what is read and what is added.
 *
 * @internal
 */
final class Baggage
{
    public const int MAX_BYTES = 8192;

    public const int MAX_MEMBERS = 180;

    /** RFC 7230 token. */
    private const string KEY_PATTERN = "/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+\\z/";

    /** baggage-octet: %x21 / %x23-2B / %x2D-3A / %x3C-5B / %x5D-7E. */
    private const string VALUE_PATTERN = '/^[\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]*\z/';

    private function __construct() {}

    /**
     * Key => decoded value of every well-formed member; malformed members are
     * skipped, the first of duplicate keys wins, an oversized header reads as
     * empty.
     *
     * @return array<string, string>
     */
    public static function parse(string $header): array
    {
        if (\strlen($header) > self::MAX_BYTES) {
            return [];
        }

        $values = [];
        foreach (self::members($header) as $member) {
            $pair = self::pair($member);
            if ($pair !== null && !\array_key_exists($pair[0], $values)) {
                $values[$pair[0]] = $pair[1];
            }
        }

        return $values;
    }

    /**
     * `$existing` with `$values` appended: members already present are kept
     * verbatim (another propagator's data), a key already present is never
     * overridden, and an entry that would break the size or member limit is
     * dropped.
     *
     * @param array<string, string> $values key => raw value
     */
    public static function merge(string $existing, array $values): string
    {
        $members = \strlen($existing) > self::MAX_BYTES ? [] : self::members($existing);
        $present = [];
        foreach ($members as $member) {
            $pair = self::pair($member);
            if ($pair !== null) {
                $present[$pair[0]] = true;
            }
        }

        $length = \strlen(implode(',', $members));
        foreach ($values as $key => $value) {
            if (isset($present[$key]) || preg_match(self::KEY_PATTERN, $key) !== 1 || \count($members) >= self::MAX_MEMBERS) {
                continue;
            }

            $member = $key . '=' . rawurlencode($value);
            $added = $length + ($members === [] ? 0 : 1) + \strlen($member);
            if ($added > self::MAX_BYTES) {
                continue;
            }

            $members[] = $member;
            $present[$key] = true;
            $length = $added;
        }

        return implode(',', $members);
    }

    /** @return list<string> trimmed, non-empty members */
    private static function members(string $header): array
    {
        $members = [];
        foreach (explode(',', $header) as $member) {
            $member = trim($member, " \t");
            if ($member !== '') {
                $members[] = $member;
            }
        }

        return $members;
    }

    /** @return array{string, string}|null */
    private static function pair(string $member): ?array
    {
        $keyValue = explode(';', $member, 2)[0];
        $parts = explode('=', $keyValue, 2);
        if (\count($parts) !== 2) {
            return null;
        }

        $key = trim($parts[0], " \t");
        $value = trim($parts[1], " \t");
        if (preg_match(self::KEY_PATTERN, $key) !== 1 || preg_match(self::VALUE_PATTERN, $value) !== 1) {
            return null;
        }

        return [$key, rawurldecode($value)];
    }
}
