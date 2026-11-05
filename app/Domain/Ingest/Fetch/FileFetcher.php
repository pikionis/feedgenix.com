<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

use RuntimeException;

/**
 * Fetches one file from a file server (ADR 0031, ADR 0032), under the same rules as the web
 * (ADR 0013): the address passes UrlGuard, the connection is pinned to a public address that was
 * checked, and the body streams to disk up to the size cap. The server's size and time stand in for
 * an ETag, so an unchanged file is not downloaded again, and a body short of the announced size is a
 * cut-off download, not a feed.
 */
final readonly class FileFetcher implements Fetcher
{
    public function __construct(
        private AddressPin $pin,
        private FileServer $ftp,
        private FileServer $sftp,
        private FetchSettings $settings,
    ) {}

    public static function make(): self
    {
        $settings = FetchSettings::fromConfig();

        return new self(new AddressPin(new DnsResolver, $settings->allowPrivateNetworks), new CurlFtpServer, new SftpServer(new PhpseclibSftpConnector), $settings);
    }

    public function fetch(string $url, string $destination, ?string $etag = null, ?string $lastModified = null, ?int $maxBytes = null, ?Credentials $credentials = null, ?string $hostKey = null): FetchResult
    {
        $maxBytes = min($maxBytes ?? PHP_INT_MAX, $this->settings->maxBytes);
        $target = UrlGuard::check($url);
        $refusal = $credentials?->refusalFor($target);
        if ($refusal !== null) {
            throw FetchFailed::because(FetchFailure::InvalidUrl, $refusal);
        }

        $server = $target->scheme === 'sftp' ? $this->sftp : $this->ftp;
        $address = $this->pin->for($target);
        $info = $server->info($target, $address, $credentials, $hostKey, $this->settings);
        // The second connection must meet the same server as the first.
        $hostKey = $info->hostKey ?? $hostKey;
        if ($etag !== null && $info->validator() === $etag) {
            return new FetchResult(true, 0, $url, etag: $etag, hostKey: $hostKey);
        }

        if ($info->size !== null && $info->size > $maxBytes) {
            throw FetchFailed::because(FetchFailure::TooLarge, 'The file is larger than one import may be.');
        }

        $sink = fopen($destination, 'wb');
        if ($sink === false) {
            throw new RuntimeException('Cannot write the downloaded feed.');
        }

        try {
            $bytes = $server->download($target, $address, $credentials, $hostKey, $sink, $maxBytes, $this->settings);
        } finally {
            fclose($sink);
        }

        if ($info->size !== null && $bytes !== $info->size) {
            throw FetchFailed::because(FetchFailure::Truncated, sprintf('The download stopped at %s of %s bytes. Try again.', number_format($bytes), number_format($info->size)));
        }

        return new FetchResult(false, $bytes, $url, etag: $info->validator(), hostKey: $hostKey);
    }
}
