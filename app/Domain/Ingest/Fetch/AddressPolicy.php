<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/**
 * Which IP addresses a feed URL may reach: public unicast only. Loopback, private and CGNAT
 * ranges, link-local (the cloud metadata endpoint 169.254.169.254), multicast, documentation and
 * reserved ranges are refused, and so is any IPv6 form that wraps an IPv4 address
 * (::ffff:127.0.0.1, NAT64, 6to4, Teredo, IPv4-compatible).
 */
final class AddressPolicy
{
    /** @var list<array{0: string, 1: int}> */
    private const array BLOCKED_V4 = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
        ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
        ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    /** @var list<array{0: string, 1: int}> */
    private const array BLOCKED_V6 = [
        ['::', 128], ['::1', 128], ['100::', 64], ['2001:db8::', 32], ['fc00::', 7], ['fe80::', 10],
        ['fec0::', 10], ['ff00::', 8],
    ];

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = (string) inet_pton($ip);

        if (strlen($packed) === 4) {
            return ! self::inAny($packed, self::BLOCKED_V4);
        }

        $embedded = self::embeddedV4($packed);
        if ($embedded !== null) {
            return self::isPublic((string) inet_ntop($embedded));
        }

        return ! self::inAny($packed, self::BLOCKED_V6);
    }

    /** The IPv4 address an IPv6 address wraps, if it wraps one. */
    private static function embeddedV4(string $packed): ?string
    {
        $prefix = static fn (string $cidr, int $bits): bool => self::inRange($packed, (string) inet_pton($cidr), $bits);

        return match (true) {
            $prefix('::ffff:0:0', 96), $prefix('::', 96) => substr($packed, 12, 4),          // mapped, compatible
            $prefix('64:ff9b::', 96), $prefix('64:ff9b:1::', 48) => substr($packed, 12, 4), // NAT64
            $prefix('2002::', 16) => substr($packed, 2, 4),                                  // 6to4
            $prefix('2001::', 32) => ~substr($packed, 12, 4),                                // Teredo (client address, inverted)
            default => null,
        };
    }

    /** @param list<array{0: string, 1: int}> $ranges */
    private static function inAny(string $packed, array $ranges): bool
    {
        foreach ($ranges as [$network, $bits]) {
            if (self::inRange($packed, (string) inet_pton($network), $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $address, string $network, int $bits): bool
    {
        if (strlen($address) !== strlen($network)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
