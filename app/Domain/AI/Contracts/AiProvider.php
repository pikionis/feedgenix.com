<?php

declare(strict_types=1);

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\ProviderResult;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Exceptions\AiTerminal;

/**
 * A model host (Gemini, Claude, the fake). Only the gateway calls a provider; a provider never
 * retries, records or decides policy itself. It maps the host's errors onto typed exceptions.
 */
interface AiProvider
{
    /**
     * @throws AiRetryable
     * @throws AiTerminal
     */
    public function call(AiRequest $request): ProviderResult;
}
