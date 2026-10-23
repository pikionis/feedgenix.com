<?php

declare(strict_types=1);

namespace App\Domain\Credits\Actions;

use App\Domain\Credits\Models\CreditTransaction;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Adds credits to the workspace in context: a staff grant (the private-beta AI budget, welcome
 * credits), later a purchase or a refund. The wallet is created on first use.
 */
final readonly class GrantCredits
{
    private const array KINDS = ['grant', 'purchase', 'refund'];

    public function __construct(private TenantContext $context) {}

    public function handle(int $millicredits, string $kind = 'grant', ?string $note = null, ?string $externalRef = null): CreditTransaction
    {
        if ($millicredits < 1) {
            throw new InvalidArgumentException('A grant must add at least one millicredit.');
        }
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Credits are not added as [{$kind}].");
        }
        $tenantId = $this->context->requireTenantId();

        return DB::transaction(static function () use ($tenantId, $millicredits, $kind, $note, $externalRef): CreditTransaction {
            $balance = DB::scalar(<<<'SQL'
                INSERT INTO credit_wallets (tenant_id, balance, reserved, created_at, updated_at)
                VALUES (?, ?, 0, now(), now())
                ON CONFLICT (tenant_id) DO UPDATE
                    SET balance = credit_wallets.balance + EXCLUDED.balance, updated_at = now()
                RETURNING balance
                SQL, [$tenantId, $millicredits], false);

            return CreditTransaction::query()->forceCreate([
                'kind' => $kind,
                'amount' => $millicredits,
                'balance_after' => (int) $balance,
                'reservation_id' => null,
                'external_ref' => $externalRef,
                'note' => $note,
            ]);
        });
    }
}
