<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ClaudeApiClient
{
    private string $apiKey;
    private string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.api_key');
    }

    /**
     * Send a text message to Claude. Returns:
     *   ['content' => string, 'input_tokens' => int, 'output_tokens' => int, 'cost' => float]
     */
    public function message(string $model, string $system, string $userMessage, float $temperature = 0.3): array
    {
        $request = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(120);

        if (app()->environment('local')) {
            $request = $request->withoutVerifying();
        }

        $response = $request->post("{$this->baseUrl}/messages", [
            'model' => $model,
            'max_tokens' => 4096,
            'temperature' => $temperature,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Claude API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Claude API request failed: ' . $response->body());
        }

        $data = $response->json();

        return [
            'content' => collect($data['content'])->where('type', 'text')->pluck('text')->implode(''),
            'input_tokens' => $data['usage']['input_tokens'] ?? 0,
            'output_tokens' => $data['usage']['output_tokens'] ?? 0,
            'cost' => $this->calculateCost($model, $data['usage']['input_tokens'] ?? 0, $data['usage']['output_tokens'] ?? 0),
        ];
    }

    /**
     * Streaming variant — $onChunk receives the accumulated text after each delta.
     */
    public function messageStream(string $model, string $system, string $userMessage, float $temperature = 0.3, ?callable $onChunk = null): array
    {
        $url = "{$this->baseUrl}/messages";
        $body = json_encode([
            'model' => $model,
            'max_tokens' => 4096,
            'temperature' => $temperature,
            'stream' => true,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ]);

        $ch = curl_init($url);
        $accumulated = '';
        $rawResponse = '';
        $inputTokens = 0;
        $outputTokens = 0;

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_SSL_VERIFYPEER => !app()->environment('local'),
            CURLOPT_TIMEOUT => 180,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$accumulated, &$rawResponse, &$inputTokens, &$outputTokens, $onChunk) {
                $rawResponse .= $data;
                foreach (explode("\n", $data) as $line) {
                    $line = trim($line);
                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }
                    $json = json_decode(substr($line, 6), true);
                    if (!$json) {
                        continue;
                    }

                    if (($json['type'] ?? '') === 'content_block_delta' && isset($json['delta']['text'])) {
                        $accumulated .= $json['delta']['text'];
                        if ($onChunk) {
                            $onChunk($accumulated);
                        }
                    }
                    if (($json['type'] ?? '') === 'message_delta' && isset($json['usage'])) {
                        $outputTokens = $json['usage']['output_tokens'] ?? $outputTokens;
                    }
                    if (($json['type'] ?? '') === 'message_start' && isset($json['message']['usage'])) {
                        $inputTokens = $json['message']['usage']['input_tokens'] ?? 0;
                    }
                }
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("Claude API streaming failed: {$httpCode} {$error} — " . mb_substr($rawResponse, 0, 500));
        }

        return [
            'content' => $accumulated,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $this->calculateCost($model, $inputTokens, $outputTokens),
        ];
    }

    /**
     * USD cost per call. Pricing in $/1M tokens. Falls back to Haiku rates for unknown models.
     */
    private function calculateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = [
            'claude-opus-4-7'            => ['input' => 15.0, 'output' => 75.0],
            'claude-opus-4-6'            => ['input' => 15.0, 'output' => 75.0],
            'claude-sonnet-4-6'          => ['input' => 3.0,  'output' => 15.0],
            'claude-haiku-4-5-20251001'  => ['input' => 1.0,  'output' => 5.0],
        ];

        $rates = $pricing[$model] ?? $pricing['claude-haiku-4-5-20251001'];

        return ($inputTokens * $rates['input'] / 1_000_000) + ($outputTokens * $rates['output'] / 1_000_000);
    }
}
