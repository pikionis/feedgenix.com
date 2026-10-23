<?php

declare(strict_types=1);

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Exceptions\AiDenied;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Exceptions\AiTerminal;

/** The only way to reach a model (ADR 0004). Every link of the gateway chain implements it. */
interface AiClient
{
    /**
     * @throws AiDenied
     * @throws AiRetryable
     * @throws AiTerminal
     */
    public function generate(AiRequest $request): AiResponse;
}
