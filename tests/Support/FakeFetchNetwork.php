<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ingest\Fetch\FetchFailed;
use App\Domain\Ingest\Fetch\FetchFailure;
use App\Domain\Ingest\Fetch\FetchSettings;
use App\Domain\Ingest\Fetch\Resolver;
use App\Domain\Ingest\Fetch\Target;
use App\Domain\Ingest\Fetch\Transport;
use App\Domain\Ingest\Fetch\TransportResponse;
use RuntimeException;

/**
 * A pretend internet for fetcher tests: DNS answers and HTTP responses are scripted, every lookup
 * and request is recorded, and nothing leaves the process.
 */
final class FakeFetchNetwork implements Resolver, Transport
{
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

    /** @var list<array{url: string, host: string, address: string, headers: array<string, string>}> */
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
        $this->requests[] = ['url' => $target->url, 'host' => $target->host, 'address' => $address, 'headers' => $headers];

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
