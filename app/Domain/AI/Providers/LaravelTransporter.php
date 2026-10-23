<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The PSR-18 client under the Anthropic SDK: Laravel's HTTP client. So the SDK's requests pass the
 * same guards as every other outbound call: the AI egress guard, TLS verification, the timeout,
 * and in tests Http::fake() and preventStrayRequests() (ADR 0004, ADR 0010).
 */
final readonly class LaravelTransporter implements ClientInterface
{
    public function __construct(private int $timeoutSeconds) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            // Host and Content-Length follow from the URI and the body; Guzzle sets both.
            if (! in_array(strtolower($name), ['host', 'content-length'], true)) {
                $headers[$name] = implode(', ', $values);
            }
        }

        try {
            return Http::withHeaders($headers + [AiEgressGuard::HEADER => 'anthropic'])
                ->timeout($this->timeoutSeconds)
                ->withBody((string) $request->getBody(), $request->getHeaderLine('Content-Type') ?: 'application/json')
                ->send($request->getMethod(), (string) $request->getUri())
                ->toPsrResponse();
        } catch (ConnectionException) {
            // No message from the underlying exception: it can carry the request, headers included.
            throw new class($request) extends RuntimeException implements NetworkExceptionInterface
            {
                public function __construct(private readonly RequestInterface $failed)
                {
                    parent::__construct('The model host could not be reached.');
                }

                public function getRequest(): RequestInterface
                {
                    return $this->failed;
                }
            };
        }
    }
}
