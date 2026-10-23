<?php

declare(strict_types=1);

use App\Providers\AiServiceProvider;
use Illuminate\Support\Facades\Http;

arch('php hygiene')->preset()->php();

arch('no insecure functions')->preset()->security();

arch('strict types in all application code')
    ->expect('App')
    ->toUseStrictTypes();

arch('only AI providers and ingest fetchers make outbound HTTP calls')
    ->expect(Http::class)
    ->toOnlyBeUsedIn(['App\Domain\AI\Providers', 'App\Domain\Ingest\Fetch']);

arch('only ingest fetchers and AI providers open raw network connections or resolve names')
    ->expect(['curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'dns_get_record', 'gethostbynamel'])
    ->toOnlyBeUsedIn(['App\Domain\Ingest\Fetch', 'App\Domain\AI\Providers']);

arch('only the AI gateway reaches a model provider (ADR 0004)')
    ->expect('App\Domain\AI\Providers')
    ->toOnlyBeUsedIn(['App\Domain\AI', AiServiceProvider::class]);

arch('the gateway links are put together in one place, so none can be skipped')
    ->expect('App\Domain\AI\Gateway')
    ->toOnlyBeUsedIn('App\Domain\AI');

arch('controllers are final')
    ->expect('App\Http\Controllers')
    ->classes()
    ->toBeFinal();
