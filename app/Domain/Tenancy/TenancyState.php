<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use InvalidArgumentException;

/** An immutable snapshot of the tenant context, so it can be restored exactly. */
final readonly class TenancyState
{
    private function __construct(
        public TenancyMode $mode,
        public ?int $tenantId,
        public ?string $reason,
    ) {}

    public static function none(): self
    {
        return new self(TenancyMode::None, null, null);
    }

    public static function tenant(int $tenantId): self
    {
        if ($tenantId < 1) {
            throw new InvalidArgumentException("A tenant id must be positive, got {$tenantId}.");
        }

        return new self(TenancyMode::Tenant, $tenantId, null);
    }

    public static function system(string $reason): self
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('The system context needs a reason (it is audited).');
        }

        return new self(TenancyMode::System, null, $reason);
    }

    /** What the database session must reflect; two states with the same key need no new query. */
    public function key(): string
    {
        return match ($this->mode) {
            TenancyMode::None => 'none',
            TenancyMode::Tenant => 'tenant:'.$this->tenantId,
            TenancyMode::System => 'system',
        };
    }
}
