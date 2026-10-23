<?php

declare(strict_types=1);

namespace App\Domain\AI\Gateway;

use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Exceptions\AiDenied;
use App\Domain\Credits\Models\CreditReservation;

/** The reservation a call spends, read fresh: another workspace's reservation is invisible (ADR 0002). */
final class Reservations
{
    /** @throws AiDenied */
    public static function open(AiRequest $request): CreditReservation
    {
        $reservation = $request->reservationId === null ? null : CreditReservation::query()->find($request->reservationId);
        if ($reservation === null || $reservation->tenant_id !== $request->tenantId) {
            throw AiDenied::because('no_reservation', 'A model call needs a credit reservation.');
        }
        if (! $reservation->isOpenAt(now())) {
            throw AiDenied::because('reservation_closed', 'The reservation is settled or expired.');
        }

        return $reservation;
    }
}
