<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Ingest\Fetch\FetchFailed;
use App\Domain\Ingest\Fetch\FetchFailure;
use App\Domain\Ingest\Fetch\FetchSettings;
use App\Domain\Ingest\Fetch\FileInfo;
use App\Domain\Ingest\Fetch\FileServer;
use App\Domain\Ingest\Fetch\Resolver;
use App\Domain\Ingest\Fetch\Target;
use App\Domain\Ingest\Fetch\Transport;
use App\Domain\Ingest\Fetch\TransportResponse;
use RuntimeException;

/**
 * A pretend internet for fetcher tests: DNS answers, HTTP responses and files on file servers are
 * scripted, every lookup and request is recorded, and nothing leaves the process.
 */
final class FakeFetchNetwork implements FileServer, Resolver, Transport
{
    /** @var array<string, array{bytes: string, info: FileInfo}|FetchFailed> url => what a file server holds */
    private array $files = [];

    /** @var list<array{call: string, url: string, address: string, login: ?Credentials, host_key: ?string}> */
    public array $fileRequests = [];

    /** @var array<string, list<list<string>>> host => answers, one per lookup (the last one repeats) */
    private array $dns = [];

    /**
     * Each response is served once, in order; the last one served then repeats. A response added
     * later is served next, so a test can change what a URL answers between two fetches.
     *
     * @var array<string, list<array{status: int, headers: array<string, string>, body: string}|FetchFailed>> url => responses not served yet
     */
    private array $responses = [];

    /** @var array<string, array{status: int, headers: array<string, string>, body: string}|FetchFailed> url => the last response served */
    private array $served = [];

    /** @var list<string> */
    public array $lookups = [];

    /** @var list<array{url: string, host: string, address: string, headers: array<string, string>, max_bytes: int}> */
    public array $requests = [];

    /** @param list<string> ...$answers one list of addresses per lookup */
    public function dns(string $host, array ...$answers): self
    {
        $this->dns[$host] = array_values($answers);

        return $this;
    }

    /** @param array<string, string> $headers */
    public function respond(string $url, int $status, string $body = '', array $headers = []): self
    {
        $this->responses[$url][] = ['status' => $status, 'headers' => array_change_key_case($headers), 'body' => $body];

        return $this;
    }

    public function fail(string $url, FetchFailure $reason): self
    {
        $this->responses[$url][] = FetchFailed::because($reason, "simulated {$reason->value}");

        return $this;
    }

    /** A file on a file server. By default it announces its real size and a fixed time. */
    public function file(string $url, string $bytes, ?int $size = -1, ?int $modifiedAt = 1_790_000_000, ?string $hostKey = null): self
    {
        $this->files[$url] = ['bytes' => $bytes, 'info' => new FileInfo($size === -1 ? strlen($bytes) : $size, $modifiedAt, $hostKey)];

        return $this;
    }

    public function failFile(string $url, FetchFailure $reason): self
    {
        $this->files[$url] = FetchFailed::because($reason, "simulated {$reason->value}");

        return $this;
    }

    public function info(Target $target, string $address, ?Credentials $credentials, ?string $hostKey, FetchSettings $settings): FileInfo
    {
        return $this->scriptedFile('info', $target, $address, $credentials, $hostKey)['info'];
    }

    public function download(Target $target, string $address, ?Credentials $credentials, ?string $hostKey, $sink, int $maxBytes, FetchSettings $settings): int
    {
        $bytes = $this->scriptedFile('download', $target, $address, $credentials, $hostKey)['bytes'];
        if (strlen($bytes) > $maxBytes) {
            throw FetchFailed::because(FetchFailure::TooLarge, 'too large');
        }

        return (int) fwrite($sink, $bytes);
    }

    /** @return array{bytes: string, info: FileInfo} */
    private function scriptedFile(string $call, Target $target, string $address, ?Credentials $credentials, ?string $hostKey): array
    {
        $this->fileRequests[] = ['call' => $call, 'url' => $target->url, 'address' => $address, 'login' => $credentials, 'host_key' => $hostKey];
        $file = $this->files[$target->url] ?? throw new RuntimeException("No scripted file at {$target->url}");
        if ($file instanceof FetchFailed) {
            throw $file;
        }

        // What every server that proves who it is must do (SftpServer).
        if ($hostKey !== null && $file['info']->hostKey !== null && $hostKey !== $file['info']->hostKey) {
            throw FetchFailed::because(FetchFailure::HostKeyChanged, 'simulated host key change');
        }

        return $file;
    }

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $answers = $this->dns[$host] ?? [[]];

        return count($answers) > 1 ? array_shift($this->dns[$host]) : $answers[0];
    }

    public function get(Target $target, string $address, array $headers, $sink, int $maxBytes, FetchSettings $settings): TransportResponse
    {
        $this->requests[] = ['url' => $target->url, 'host' => $target->host, 'address' => $address, 'headers' => $headers, 'max_bytes' => $maxBytes];

        $response = ($this->responses[$target->url] ?? []) !== []
            ? array_shift($this->responses[$target->url])
            : $this->served[$target->url] ?? throw new RuntimeException("No scripted response for {$target->url}");
        $this->served[$target->url] = $response;
        if ($response instanceof FetchFailed) {
            throw $response;
        }

        if (strlen($response['body']) > $maxBytes) {
            throw FetchFailed::because(FetchFailure::TooLarge, 'too large');
        }

        fwrite($sink, $response['body']);

        return new TransportResponse($response['status'], $response['headers'], strlen($response['body']));
    }
}
