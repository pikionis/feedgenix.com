<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Global HTTP middleware (ADR 0004): a request to a model host leaves only when a gateway provider
 * sent it. It is an allowlist of callers, fail closed; the legacy guard was a denylist of frozen
 * workspaces that let everything else through. A feed URL pointed at a model host is refused too.
 * The marker header is removed before the request leaves.
 */
final class AiEgressGuard
{
    public const string HEADER = 'X-FeedGenix-AI-Gateway';

    /** Hosts of model APIs, the ones the gateway uses and the ones it must never be bypassed for. */
    public const array HOSTS = [
        'generativelanguage.googleapis.com',
        'aiplatform.googleapis.com',
        'api.anthropic.com',
        'api.openai.com',
    ];

    /** Runs on every outbound request of Laravel's HTTP client, the SDK's included. */
    public static function install(): void
    {
        Http::globalRequestMiddleware(static fn (RequestInterface $request): RequestInterface => self::check($request));
    }

    public static function check(RequestInterface $request): RequestInterface
    {
        $host = strtolower($request->getUri()->getHost());
        $isModelHost = in_array($host, self::HOSTS, true)
            || array_filter(self::HOSTS, static fn (string $known): bool => str_ends_with($host, '.'.$known)) !== []
            // Vertex AI's regional endpoints: europe-west4-aiplatform.googleapis.com.
            || str_ends_with($host, '-aiplatform.googleapis.com');

        if ($isModelHost && ! $request->hasHeader(self::HEADER)) {
            throw new RuntimeException("Refused a request to the model host [{$host}] that did not come through the AI gateway.");
        }

        return $request->withoutHeader(self::HEADER);
    }
}
