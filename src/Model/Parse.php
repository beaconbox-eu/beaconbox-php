<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * Reading a decoded JSON payload without trusting its shape.
 *
 * Every model is built through these, and they all share one rule: **a field that is missing,
 * null or the wrong type produces a sensible zero value rather than an error.** A client library
 * that fatals on a field it did not expect turns an additive API change into an outage in a
 * merchant's checkout, months after the SDK was last touched.
 *
 * The trade is that a genuinely broken response is quiet. That is the right side of it here,
 * because the models also keep the raw payload: anything this cannot express is still readable
 * through `->raw`.
 *
 * @internal
 */
final class Parse
{
    /**
     * The UTC zone, once.
     *
     * Memoised because a page of messages carries dozens of timestamps and every one of them was
     * allocating its own `DateTimeZone`. `DateTimeZone` is immutable in practice, so sharing it
     * across calls is safe.
     */
    private static ?\DateTimeZone $utc = null;

    private static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }

    /** @param array<string, mixed> $payload */
    public static function string(array $payload, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $payload */
    public static function nullableString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * An integer field, or `$default` when the API sent something else.
     *
     * An integral `float` counts, because JSON has one number type and `is_int()` alone did not:
     * a proxy, a language boundary or a re-serialising gateway that writes `1` as `1.0` turned one
     * credit into zero here, silently, in a field a merchant is billed on. A non-integral float is
     * not coerced — a truncated `1.5` would be a different kind of silent wrongness.
     *
     * @param array<string, mixed> $payload
     */
    public static function int(array $payload, string $key, int $default = 0): int
    {
        $value = $payload[$key] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        // Strict `<` on the upper bound, not `<=`. `(float) PHP_INT_MAX` rounds *up* to
        // 9223372036854775808.0, so `<=` admitted a float one larger than any int — which casts to
        // PHP_INT_MIN, a *negative* credit count, and emits a warning that `failOnWarning="true"`
        // would surface as an unrelated test failure.
        if (\is_float($value) && !is_nan($value) && !is_infinite($value) && $value === floor($value)
            && $value > (float) PHP_INT_MIN && $value < (float) PHP_INT_MAX) {
            return (int) $value;
        }

        return $default;
    }

    /** @param array<string, mixed> $payload */
    public static function bool(array $payload, string $key, bool $default = false): bool
    {
        $value = $payload[$key] ?? null;

        return \is_bool($value) ? $value : $default;
    }

    /**
     * A nested object, or an empty array when it is absent.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function object(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    /**
     * A list of objects, skipping any entry that is not one.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * A list of strings.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    public static function stringList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (\is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * An RFC 3339 timestamp, or null.
     *
     * `DateTimeImmutable` rather than `DateTime`: a mutable timestamp on a readonly value object
     * would be a hole straight through the immutability the rest of these models rely on. A
     * caller who writes `$result->createdAt->modify('+1 day')` on a `DateTime` has silently
     * edited the response.
     *
     * @param array<string, mixed> $payload
     */
    public static function nullableDatetime(array $payload, string $key): ?\DateTimeImmutable
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            // **UTC, not `date.timezone`.** Without the second argument PHP reads a timestamp that
            // carries no offset in the *server's* configured zone, so `2026-01-01T10:00:00` became
            // 10:00 in Sao Paulo on one host and 10:00 in Tallinn on the next — the same response
            // parsed as two different instants, five hours apart, with nothing to indicate it. The
            // API sends UTC, so UTC is what a value with no offset means. A value that does carry
            // one is unaffected: an explicit offset in the string wins over this argument, which is
            // exactly the behaviour wanted, and matches the Python SDK's `_parse_datetime`.
            return new \DateTimeImmutable($value, self::utc());
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * A timestamp the API declares required. Falls back to the epoch rather than throwing.
     *
     * @param array<string, mixed> $payload
     */
    public static function datetime(array $payload, string $key): \DateTimeImmutable
    {
        return self::nullableDatetime($payload, $key)
            ?? new \DateTimeImmutable('@0');
    }

    /**
     * The enum case for this value, or the raw string when it is one this SDK has not heard of.
     *
     * **The fallback is the point.** A `MessageStatus` shipped next quarter must not become a
     * fatal error in an old SDK running in a merchant's job runner. The cost is that an
     * unrecognised value has to be compared as a string, which is a cost paid by the few callers
     * who meet one rather than by everybody.
     *
     * @template T of \BackedEnum
     *
     * @param class-string<T>      $enum
     * @param array<string, mixed> $payload
     *
     * @return T|string
     */
    public static function enum(string $enum, array $payload, string $key): \BackedEnum|string
    {
        $value = self::string($payload, $key);

        return $enum::tryFrom($value) ?? $value;
    }
}
