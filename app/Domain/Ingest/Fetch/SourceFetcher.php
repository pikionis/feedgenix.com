<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

/** The fetcher the app uses: the address's scheme says which one does the work. */
final readonly class SourceFetcher implements Fetcher
{
    public function __construct(
        private HttpFetcher $web,
        private FileFetcher $files,
    ) {}

    public static function make(): self
    {
        return new self(HttpFetcher::make(), FileFetcher::make());
    }

    public function fetch(string $url, string $destination, ?string $etag = null, ?string $lastModified = null, ?int $maxBytes = null, ?Credentials $credentials = null, ?string $hostKey = null): FetchResult
    {
        $fetcher = UrlGuard::check($url)->isWeb() ? $this->web : $this->files;

        return $fetcher->fetch($url, $destination, $etag, $lastModified, $maxBytes, $credentials, $hostKey);
    }
}
