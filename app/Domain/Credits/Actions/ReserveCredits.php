<?php

declare(strict_types=1);

namespace App\Domain\Credits\Actions;

use App\Domain\Credits\Exceptions\InsufficientCredits;
use App\Domain\Credits\Models\CreditReservation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Holds credits for one piece of paid work before any AI call (ADR 0005: no reservation, no call).
 * One conditional UPDATE moves the hold, so concurrent reservations can never together exceed the
 * balance; the reference is unique per workspace (one reservation per work unit and attempt).
 */
final readonly class ReserveCredits
{
    public function __construct(private TenantContext $context) {}

    /** @throws InsufficientCredits */
    public function handle(string $reference, int $millicredits): CreditReservation
    {
        if ($millicredits < 1) {
            throw new InvalidArgumentException('A reservation must hold at least one millicredit.');
        }
        if ($reference === '' || mb_strlen($reference) > 128) {
            throw new InvalidArgumentException('A reservation needs a reference of 1 to 128 characters.');
        }
        $tenantId = $this->context->requireTenantId();
        $ttl = (int) config('credits.reservation_ttl_minutes', 60);

        return DB::transaction(static function () use ($tenantId, $reference, $millicredits, $ttl): CreditReservation {
            $held = DB::update(
                'UPDATE credit_wallets SET reserved = reserved + ?, updated_at = now() WHERE tenant_id = ? AND balance - reserved >= ?',
                [$millicredits, $tenantId, $millicredits],
            );
            if ($held !== 1) {
                $available = DB::scalar('SELECT balance - reserved FROM credit_wallets WHERE tenant_id = ?', [$tenantId], false);

                throw new InsufficientCredits($millicredits, (int) $available);
            }

            return CreditReservation::query()->forceCreate([
                'reference' => $reference,
                'amount' => $millicredits,
                'expires_at' => now()->addMinutes($ttl),
            ]);
        });
    }
}
