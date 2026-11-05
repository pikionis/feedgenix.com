<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

use DateTimeImmutable;
use RuntimeException;

/**
 * Fetches a web address without letting it reach anything but the public internet (SSRF):
 *
 * 1. the address passes UrlGuard (scheme, host form, port), and stays on the web;
 * 2. the host is resolved once, every address must be public (AddressPolicy), and the connection
 *    is pinned to the first one, so DNS rebinding cannot switch it to an internal address;
 * 3. redirects are followed by hand, up to the limit, and every hop goes through 1 and 2 again;
 * 4. the body streams to disk up to the size cap, and a Content-Length that does not match what
 *    arrived is a cut-off download, not a feed;
 * 5. a login travels over https to the address's own origin only: a redirect elsewhere goes without it.
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

    public function fetch(string $url, string $destination, ?string $etag = null, ?string $lastModified = null, ?int $maxBytes = null, ?Credentials $credentials = null, ?string $hostKey = null): FetchResult
    {
        $maxBytes = min($maxBytes ?? PHP_INT_MAX, $this->settings->maxBytes);
        $origin = UrlGuard::checkWeb($url);
        $refusal = $credentials?->refusalFor($origin);
        if ($refusal !== null) {
            throw FetchFailed::because(FetchFailure::InvalidUrl, $refusal);
        }

        $pin = new AddressPin($this->resolver, $this->settings->allowPrivateNetworks);
        $current = $url;
        for ($hop = 0; ; $hop++) {
            $target = UrlGuard::checkWeb($current);
            $address = $pin->for($target);

            $headers = ['Accept' => '*/*', 'Accept-Encoding' => 'gzip, identity'];
            if ($etag !== null) {
                $headers['If-None-Match'] = $etag;
            }
            if ($lastModified !== null) {
                $headers['If-Modified-Since'] = $lastModified;
            }
            $loginSent = $credentials instanceof Credentials && $target->origin() === $origin->origin();
            if ($loginSent) {
                $headers = [...$headers, ...$credentials->httpHeaders()];
            }

            $sink = fopen($destination, 'wb');
            if ($sink === false) {
                throw new RuntimeException('Cannot write the downloaded feed.');
            }

            try {
                $response = $this->transport->get($target, $address, $headers, $sink, $maxBytes, $this->settings);
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

            $this->assertSuccess($status, $response, $credentials instanceof Credentials, $loginSent);

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

    private function assertSuccess(int $status, TransportResponse $response, bool $hasLogin, bool $loginSent): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw match (true) {
            $status === 401 && $loginSent => FetchFailed::because(FetchFailure::LoginRefused, 'The server refused the login (HTTP 401). Check it in the feed’s source settings.', $status),
            $status === 403 && $loginSent => FetchFailed::because(FetchFailure::RateLimited, 'The server refused for now (HTTP 403). Try again later; if it keeps refusing, check the login in the feed’s source settings.', $status, $this->retryAfter($response->header('retry-after'))),
            $status === 403, $status === 429 => FetchFailed::because(FetchFailure::RateLimited, "The server refused for now (HTTP {$status}). Try again later.", $status, $this->retryAfter($response->header('retry-after'))),
            $status === 401 && $hasLogin => FetchFailed::because(FetchFailure::HttpStatus, 'The address redirected to another server, which asks for a login (HTTP 401). The saved login is only sent to the feed’s own server.', $status),
            $status === 401 => FetchFailed::because(FetchFailure::LoginNeeded, 'The address needs a login (HTTP 401). Add one in the feed’s source settings.', $status),
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
