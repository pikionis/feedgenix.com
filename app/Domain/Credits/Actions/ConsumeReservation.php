<?php

declare(strict_types=1);

namespace App\Domain\Credits\Actions;

use App\Domain\Credits\Data\Charge;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Charges one billed AI attempt against its reservation, atomically (legacy D14: spend was a cache
 * read-modify-write). The row lock serialises concurrent charges; consumed never passes amount, and
 * whatever the hold cannot cover is returned as unbilled rather than taken from the customer.
 */
final readonly class ConsumeReservation
{
    public function __construct(private TenantContext $context) {}

    public function handle(int $reservationId, int $millicredits): Charge
    {
        if ($millicredits < 0) {
            throw new InvalidArgumentException('A charge cannot be negative.');
        }
        if ($millicredits === 0) {
            return new Charge(0, 0);
        }
        $tenantId = $this->context->requireTenantId();

        $charged = (int) DB::scalar(<<<'SQL'
            WITH held AS (
                SELECT id, LEAST(?::bigint, amount - consumed) AS charge
                FROM credit_reservations
                WHERE id = ? AND tenant_id = ? AND status = 'open'
                FOR UPDATE
            )
            UPDATE credit_reservations r
            SET consumed = r.consumed + held.charge, updated_at = now()
            FROM held
            WHERE r.id = held.id
            RETURNING held.charge
            SQL, [$millicredits, $reservationId, $tenantId], false);

        return new Charge($charged, $millicredits - $charged);
    }
}
