<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Domain\Billing\PlanCatalog;
use App\Domain\Billing\PlanLimitExceeded;
use App\Domain\Billing\TenantEntitlements;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * How large a feed one workspace may import: its plan's `feed_bytes` and `feed_rows`, measured
 * uncompressed, never above the server's ceiling (config/ingest.php). It says so in the customer's
 * words, naming the plan that reads more only when upgrading would really help on this server.
 */
final readonly class FeedSizeLimit
{
    private function __construct(
        public int $bytes,
        public int $rows,
        private TenantEntitlements $rights,
        private IngestLimits $server,
    ) {}

    public static function for(TenantEntitlements $rights, ?IngestLimits $server = null): self
    {
        $server ??= resolve(IngestLimits::class);

        return new self(
            bytes: min($rights->limit('feed_bytes') ?? PHP_INT_MAX, $server->maxBytes, $server->maxInflatedBytes),
            rows: min($rights->limit('feed_rows') ?? PHP_INT_MAX, $server->maxRows),
            rights: $rights,
            server: $server,
        );
    }

    /** The server's limits, narrowed to this workspace: bytes received, bytes uncompressed, rows. */
    public function limits(): IngestLimits
    {
        return $this->server->with(['maxBytes' => $this->bytes, 'maxInflatedBytes' => $this->bytes, 'maxRows' => $this->rows]);
    }

    /**
     * Before an upload sends a byte. Over the plan: a 402 naming the plan that reads the file. Over
     * what this server reads at all: a 422, since no plan would help.
     *
     * @throws PlanLimitExceeded
     * @throws ValidationException
     */
    public function assertUploadFits(int $size, string $field = 'size'): void
    {
        if ($size <= $this->bytes) {
            return;
        }

        $serverMax = min($this->server->maxBytes, $this->server->maxInflatedBytes);
        if ($size > $serverMax) {
            throw ValidationException::withMessages([$field => sprintf(
                'This file is %s; feeds up to %s can be read for now.',
                self::size($size),
                self::size($serverMax),
            )]);
        }

        throw PlanLimitExceeded::feedSize(
            $this->rights->plan(),
            $this->bytes,
            $size,
            $this->rights->upgradeFor('feed_bytes', $size),
            sprintf(
                'This file is %s; your %s plan reads feeds up to %s.%s',
                self::size($size),
                $this->planName(),
                self::size($this->bytes),
                $this->upgrade('feed_bytes', $size, $serverMax, self::size(...)),
            ),
        );
    }

    /** Why a feed that turned out too large while it downloaded or was read was refused. */
    public function tooLarge(): string
    {
        return sprintf('This feed is larger than %s uncompressed, %s', self::size($this->bytes), $this->most('feed_bytes'))
            .$this->upgrade('feed_bytes', $this->bytes + 1, min($this->server->maxBytes, $this->server->maxInflatedBytes), self::size(...));
    }

    public function tooManyRows(): string
    {
        return sprintf('This feed has more than %s rows, %s', number_format($this->rows), $this->most('feed_rows'))
            .$this->upgrade('feed_rows', $this->rows + 1, $this->server->maxRows, static fn (int $rows): string => number_format($rows).' rows');
    }

    /** "2 GB", "3.1 GB", "750 MB": decimal-looking units for binary sizes, as people read them. */
    public static function size(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return rtrim(rtrim(number_format($bytes / 1024 ** 3, 1), '0'), '.').' GB';
        }

        return number_format(max(1, (int) round($bytes / 1024 ** 2))).' MB';
    }

    private function most(string $key): string
    {
        $planLimit = $this->rights->limit($key);
        $limit = $key === 'feed_bytes' ? $this->bytes : $this->rows;

        return $planLimit !== null && $planLimit <= $limit
            ? "the most your {$this->planName()} plan reads."
            : 'the most that can be read for now.';
    }

    /**
     * " Pro reads feeds up to 10 GB." — the cheapest plan that reads $needed on this server, or
     * nothing when none does (the server ceiling, or already the largest plan).
     *
     * @param  Closure(int): string  $describe
     */
    private function upgrade(string $key, int $needed, int $serverMax, Closure $describe): string
    {
        if ($needed > $serverMax) {
            return '';
        }

        $plan = $this->rights->upgradeFor($key, $needed);
        if ($plan === null) {
            return $this->rights->plan() === 'custom' ? '' : ' Write to us about a Custom plan for larger feeds.';
        }

        $limit = min(PlanCatalog::limit($plan, $key) ?? PHP_INT_MAX, $serverMax);

        return ' '.PlanCatalog::name($plan).' reads feeds up to '.$describe($limit).'.';
    }

    private function planName(): string
    {
        return PlanCatalog::name($this->rights->plan());
    }
}
