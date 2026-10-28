<?php

declare(strict_types=1);

namespace App\Domain\Credits\Actions;

use App\Domain\Credits\Exceptions\InsufficientCredits;
use App\Domain\Credits\Models\CreditTransaction;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Takes a plan fee (the overage fee, ADR 0028) from the workspace in context, all or nothing, in
 * one conditional UPDATE: only from the credits available, never from those held for work that is
 * running.
 */
final readonly class ChargeFee
{
    public function __construct(private TenantContext $context) {}

    /** @throws InsufficientCredits */
    public function handle(int $millicredits, string $kind, string $note): CreditTransaction
    {
        if ($millicredits < 1) {
            throw new InvalidArgumentException('A fee must take at least one millicredit.');
        }
        if ($kind !== 'overage') {
            throw new InvalidArgumentException("Credits are not charged as [{$kind}].");
        }
        $tenantId = $this->context->requireTenantId();

        return DB::transaction(static function () use ($tenantId, $millicredits, $kind, $note): CreditTransaction {
            $balance = DB::scalar(
                'UPDATE credit_wallets SET balance = balance - ?, updated_at = now() WHERE tenant_id = ? AND balance - reserved >= ? RETURNING balance',
                [$millicredits, $tenantId, $millicredits],
                false,
            );
            if ($balance === null) {
                $available = DB::scalar('SELECT balance - reserved FROM credit_wallets WHERE tenant_id = ?', [$tenantId], false);

                throw new InsufficientCredits($millicredits, (int) $available);
            }

            return CreditTransaction::query()->forceCreate([
                'kind' => $kind,
                'amount' => -$millicredits,
                'balance_after' => (int) $balance,
                'reservation_id' => null,
                'external_ref' => null,
                'note' => mb_substr($note, 0, 255),
            ]);
        });
    }
}
