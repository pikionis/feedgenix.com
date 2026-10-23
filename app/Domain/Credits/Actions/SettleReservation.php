<?php

declare(strict_types=1);

namespace App\Domain\Credits\Actions;

use App\Domain\Credits\Models\CreditTransaction;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Ends a reservation: what was consumed leaves the wallet (one ai_usage transaction), the rest of
 * the hold is released. Settling twice is a no-op, so a finished unit and the expiry sweep can race.
 */
final readonly class SettleReservation
{
    public function __construct(private TenantContext $context) {}

    /** @return bool false when the reservation was not open (already settled, or not this workspace's) */
    public function handle(int $reservationId): bool
    {
        $tenantId = $this->context->requireTenantId();

        return DB::transaction(static function () use ($tenantId, $reservationId): bool {
            $settled = DB::selectOne(<<<'SQL'
                UPDATE credit_reservations
                SET status = 'settled', settled_at = now(), updated_at = now()
                WHERE id = ? AND tenant_id = ? AND status = 'open'
                RETURNING amount, consumed
                SQL, [$reservationId, $tenantId], false);
            if (! is_object($settled)) {
                return false;
            }
            $row = (array) $settled;
            $amount = (int) $row['amount'];
            $consumed = (int) $row['consumed'];

            $balance = DB::scalar(
                'UPDATE credit_wallets SET balance = balance - ?, reserved = reserved - ?, updated_at = now() WHERE tenant_id = ? RETURNING balance',
                [$consumed, $amount, $tenantId],
                false,
            );

            if ($consumed > 0) {
                CreditTransaction::query()->forceCreate([
                    'kind' => 'ai_usage',
                    'amount' => -$consumed,
                    'balance_after' => (int) $balance,
                    'reservation_id' => $reservationId,
                    'external_ref' => "reservation:{$reservationId}",
                    'note' => null,
                ]);
            }

            return true;
        });
    }
}
