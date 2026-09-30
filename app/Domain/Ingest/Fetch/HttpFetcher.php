<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

use DateTimeImmutable;
use RuntimeException;

/**
 * Fetches a feed URL without letting it reach anything but the public internet (SSRF):
 *
 * 1. the URL passes UrlGuard (scheme, host form, port);
 * 2. the host is resolved once, every address must be public (AddressPolicy), and the connection
 *    is pinned to the first one, so DNS rebinding cannot switch it to an internal address;
 * 3. redirects are followed by hand, up to the limit, and every hop goes through 1 and 2 again;
 * 4. the body streams to disk up to the size cap, and a Content-Length that does not match what
 *    arrived is a cut-off download, not a feed.
 *
 * 403 and 429 mean "come back later" (the legacy lesson: a rate limit is not a broken feed).
 */
final readonly class HttpFetcher implements Fetcher
{
    public function __construct(
        private Resolver $resolver,
        private Transport $transport,
        private FetchSettings $settings,
    ) {}

    public static function make(): self
    {
        return new self(new DnsResolver, new CurlTransport, FetchSettings::fromConfig());
    }

    public function fetch(string $url, string $destination, ?string $etag = null, ?string $lastModified = null): FetchResult
    {
        $current = $url;
        for ($hop = 0; ; $hop++) {
            $target = UrlGuard::check($current);
            $address = $this->pin($target);

            $headers = ['Accept' => '*/*', 'Accept-Encoding' => 'gzip, identity'];
            if ($etag !== null) {
                $headers['If-None-Match'] = $etag;
            }
            if ($lastModified !== null) {
                $headers['If-Modified-Since'] = $lastModified;
            }

            $sink = fopen($destination, 'wb');
            if ($sink === false) {
                throw new RuntimeException('Cannot write the downloaded feed.');
            }

            try {
                $response = $this->transport->get($target, $address, $headers, $sink, $this->settings->maxBytes, $this->settings);
            } finally {
                fclose($sink);
            }

            $status = $response->status;
            if (in_array($status, [301, 302, 303, 307, 308], true) && $response->header('location') !== null) {
                if ($hop >= $this->settings->maxRedirects) {
                    throw FetchFailed::because(FetchFailure::TooManyRedirects, "The address redirected more than {$this->settings->maxRedirects} times.");
                }
                $current = $target->resolve($response->header('location'));

                continue;
            }

            if ($status === 304) {
                return new FetchResult(true, 0, $current, etag: $etag, lastModified: $lastModified);
            }

            $this->assertSuccess($status, $response);

            $declared = $response->header('content-length');
            if ($declared !== null && ctype_digit($declared) && (int) $declared !== $response->bytes) {
                throw FetchFailed::because(FetchFailure::Truncated, sprintf('The download stopped at %s of %s bytes. Try again.', number_format($response->bytes), number_format((int) $declared)));
            }

            return new FetchResult(
                notModified: false,
                bytes: $response->bytes,
                finalUrl: $current,
                contentType: $response->header('content-type'),
                etag: $response->header('etag'),
                lastModified: $response->header('last-modified'),
            );
        }
    }

    /** Resolves the host once; every address must be public; the first one is used. */
    private function pin(Target $target): string
    {
        $addresses = $this->resolver->resolve($target->host);
        if ($addresses === []) {
            throw FetchFailed::because(FetchFailure::DnsFailed, "The host {$target->host} does not exist.");
        }

        if (! $this->settings->allowPrivateNetworks) {
            foreach ($addresses as $address) {
                if (! AddressPolicy::isPublic($address)) {
                    throw FetchFailed::because(FetchFailure::BlockedAddress, "{$target->host} points to a private or reserved network address, which FeedGenix never fetches from.");
                }
            }
        }

        return $addresses[0];
    }

    private function assertSuccess(int $status, TransportResponse $response): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw match (true) {
            $status === 403, $status === 429 => FetchFailed::because(FetchFailure::RateLimited, "The server refused for now (HTTP {$status}). FeedGenix will try again later.", $status, $this->retryAfter($response->header('retry-after'))),
            $status === 401 => FetchFailed::because(FetchFailure::HttpStatus, 'The address needs a login (HTTP 401). Use a feed URL that works without one.', $status),
            $status === 404, $status === 410 => FetchFailed::because(FetchFailure::HttpStatus, "Nothing was found at this address (HTTP {$status}). Check it is still valid.", $status),
            default => FetchFailed::because(FetchFailure::HttpStatus, "The server answered HTTP {$status}.", $status),
        };
    }

    /** Retry-After in seconds, from seconds or an HTTP date; capped at a day. */
    private function retryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (ctype_digit(trim($value))) {
            return min((int) $value, 86_400);
        }

        $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, trim($value));
        if ($date === false) {
            return null;
        }

        return max(0, min($date->getTimestamp() - time(), 86_400));
    }
}
