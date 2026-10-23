<?php

declare(strict_types=1);

namespace App\Domain\AI\Gateway;

use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Exceptions\AiDenied;
use App\Domain\AI\Pricing;
use App\Domain\AI\Routes;

/**
 * Reserve before paid work (ADR 0005): the call's reservation must be this workspace's, open, and
 * hold at least the worst case of the costliest model it may run on. The Recorder repeats the check
 * for every attempt, since retries consume the same reservation.
 */
final readonly class ReservationGate implements AiClient
{
    public function __construct(
        private AiClient $next,
        private Routes $routes,
        private Pricing $pricing,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $worst = 0;
        foreach ($this->routes->candidates($request) as $candidate) {
            $worst = max($worst, $this->pricing->worstCase($request, $candidate['model']));
        }

        $reservation = Reservations::open($request);
        if ($reservation->remaining() < $worst) {
            throw AiDenied::because('reservation_exhausted', 'The reservation does not cover this call.');
        }

        return $this->next->generate($request);
    }
}
