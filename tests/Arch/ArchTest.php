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

arch('controllers are final')
    ->expect('App\Http\Controllers')
    ->classes()
    ->toBeFinal();
