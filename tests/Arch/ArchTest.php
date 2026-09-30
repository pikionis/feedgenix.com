<?php

declare(strict_types=1);

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

arch('controllers are final')
    ->expect('App\Http\Controllers')
    ->classes()
    ->toBeFinal();
