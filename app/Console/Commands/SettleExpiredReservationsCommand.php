<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Credits\Actions\SettleReservation;
use App\Domain\Credits\Models\CreditReservation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Settles reservations their work never settled (a crashed or killed worker): what was consumed is
 * charged, the rest goes back to the wallet. Each one is settled as its own workspace.
 */
#[AsCommand(name: 'credits:settle-expired', description: 'Settle expired credit reservations and release what they still hold')]
final class SettleExpiredReservationsCommand extends Command
{
    public function handle(TenantContext $context, SettleReservation $settle): int
    {
        $expired = $context->runAsSystem('settle expired credit reservations', static fn (): array => CreditReservation::query()
            ->where('status', 'open')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(1000)
            ->get(['id', 'tenant_id'])
            ->map(static fn (CreditReservation $reservation): array => ['id' => $reservation->id, 'tenant_id' => $reservation->tenant_id])
            ->all());

        $settled = 0;
        foreach ($expired as $reservation) {
            if ($context->run($reservation['tenant_id'], static fn (): bool => $settle->handle($reservation['id']))) {
                $settled++;
            }
        }

        $this->info("Settled {$settled} expired reservation(s).");

        return self::SUCCESS;
    }
}
