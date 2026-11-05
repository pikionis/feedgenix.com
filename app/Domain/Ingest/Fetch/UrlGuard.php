<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/**
 * What a feed address may look like before anything is resolved: http, https, ftp, ftps or sftp, a
 * real host name or a standard IP address, no credentials inside the address, and a port of that
 * service or from 1024 up, so FeedGenix cannot be used to probe mail or admin ports. The address of a
 * file server is the path of one file, and cannot smuggle a command or a transfer mode into it.
 */
final class UrlGuard
{
    private const array WEB_SCHEMES = ['http', 'https'];

    /** @var array<string, array{default: int, also?: list<int>}> */
    private const array PORTS = [
        'http' => ['default' => 80, 'also' => [443]],
        'https' => ['default' => 443, 'also' => [80]],
        'ftp' => ['default' => 21],
        // Explicit TLS on the FTP port is what servers usually offer; 990 is implicit TLS.
        'ftps' => ['default' => 21, 'also' => [990]],
        'sftp' => ['default' => 22],
    ];

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
        if (! isset(self::PORTS[$scheme])) {
            throw self::invalid("Only http, https, ftp, ftps and sftp addresses can be fetched (not {$scheme}://).");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw self::invalid('Addresses with a username and password inside them are not supported. Add them as the feed’s login.');
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

        $ports = self::PORTS[$scheme];
        $port = $parts['port'] ?? $ports['default'];
        // parse_url() already refuses ports above 65535.
        if ($port < 1024 && $port !== $ports['default'] && ! in_array($port, $ports['also'] ?? [], true)) {
            throw self::invalid("Port {$port} cannot be used for {$scheme}://; use its usual port or one from 1024.");
        }

        if (! in_array($scheme, self::WEB_SCHEMES, true)) {
            self::checkFilePath($parts);
        }

        return new Target($url, $scheme, $host, $port);
    }

    /** The same rules, for where only the web may lead: a redirect never moves a fetch to another protocol. */
    public static function checkWeb(string $url): Target
    {
        $target = self::check($url);
        if (! $target->isWeb()) {
            throw self::invalid("A web address cannot redirect to {$target->scheme}://.");
        }

        return $target;
    }

    /** @param array<string, int|string> $parts */
    private static function checkFilePath(array $parts): void
    {
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || str_ends_with($path, '/')) {
            throw self::invalid('Give the path of the file, not a folder: sftp://host/folder/feed.csv.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw self::invalid('A file address has no ? or #. Write them in a file name as %3F and %23.');
        }

        // An encoded line break would be a second command to the server, and ";type=a" would make
        // curl transfer in text mode, which rewrites the file's line endings.
        if (preg_match('/%(?:[01][0-9a-f]|7f)/i', $path) === 1 || stripos($path, ';type=') !== false) {
            throw self::invalid('The path of the file contains characters that cannot be used.');
        }
    }

    private static function invalid(string $message): FetchFailed
    {
        return FetchFailed::because(FetchFailure::InvalidUrl, $message);
    }
}
