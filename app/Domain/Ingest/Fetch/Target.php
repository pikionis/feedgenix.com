<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/** A feed address taken apart after it passed the guard. */
final readonly class Target
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
    ) {}

    public function isIpLiteral(): bool
    {
        return filter_var($this->host, FILTER_VALIDATE_IP) !== false;
    }

    public function isWeb(): bool
    {
        return $this->scheme === 'http' || $this->scheme === 'https';
    }

    /** The file on a file server: decoded, and relative to the login's home when it starts with /~/. */
    public function filePath(): string
    {
        $path = rawurldecode((string) parse_url($this->url, PHP_URL_PATH));

        return str_starts_with($path, '/~/') ? substr($path, 3) : $path;
    }

    /** Scheme, host and port: what a login belongs to. */
    public function origin(): string
    {
        return "{$this->scheme}://{$this->host}:{$this->port}";
    }

    /** A Location header, absolute or relative, resolved against this URL. */
    public function resolve(string $location): string
    {
        $location = trim($location);
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location) === 1) {
            return $location;
        }

        $origin = $this->scheme.'://'.(str_contains($this->host, ':') ? "[{$this->host}]" : $this->host)
            .($this->port === ($this->scheme === 'https' ? 443 : 80) ? '' : ':'.$this->port);

        if (str_starts_with($location, '//')) {
            return $this->scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = (string) parse_url($this->url, PHP_URL_PATH);
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.($directory === '' ? '/' : $directory).$location;
    }
}
