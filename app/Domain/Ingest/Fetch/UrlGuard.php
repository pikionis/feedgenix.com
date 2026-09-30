<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/**
 * What a feed URL may look like before anything is resolved: http or https, a real host name or a
 * standard IP address, no credentials inside the URL, and a web port (80, 443 or 1024 and up, so
 * FeedGenix cannot be used to probe mail or admin ports).
 */
final class UrlGuard
{
    public static function check(string $url): Target
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw self::invalid('The address is empty, too long, or contains spaces.');
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw self::invalid('Enter a full address, starting with https://.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw self::invalid("Only http and https addresses can be fetched (not {$scheme}://). FTP and SFTP come later.");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw self::invalid('Addresses with a username and password inside them are not supported.');
        }

        $host = rtrim(strtolower(trim($parts['host'], '[]')), '.');
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

        // Hosts made only of numbers ("2130706433", "0x7f.1", "127.1") are read as IP addresses by
        // resolvers and curl alike, in ways that differ: refuse them rather than guess.
        $labels = explode('.', $host);
        $numeric = array_filter($labels, static fn (string $label): bool => preg_match('/^(?:0x[0-9a-f]*|\d+)$/i', $label) === 1);
        if (! $isIp && count($numeric) === count($labels)) {
            throw self::invalid('Use a host name or a standard IP address.');
        }

        if (! $isIp && preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1) {
            throw self::invalid('The host name is not valid.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        // parse_url() already refuses ports above 65535.
        if (! in_array($port, [80, 443], true) && $port < 1024) {
            throw self::invalid("Port {$port} cannot be used; feeds are served on 80, 443 or a port from 1024.");
        }

        return new Target($url, $scheme, $host, $port);
    }

    private static function invalid(string $message): FetchFailed
    {
        return FetchFailed::because(FetchFailure::InvalidUrl, $message);
    }
}
