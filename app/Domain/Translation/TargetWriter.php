<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use Illuminate\Support\Facades\DB;

/**
 * Writes one translation, fenced (ADR 0003): only while the source is still the version it was
 * made from, and never over a translation a person wrote or a language the feed provides itself.
 * A result that lost the race is simply not written; the next plan picks the text up again.
 */
final class TargetWriter
{
    /**
     * @param  array<string, mixed>|null  $checks
     * @return bool whether it was written
     */
    public static function write(int $tenantId, int $productId, string $field, string $sourceLocale, string $sourceHash, string $targetLocale, string $value, string $origin, string $status, ?string $prompt = null, ?string $model = null, ?array $checks = null): bool
    {
        $written = DB::affectingStatement(<<<'SQL'
            INSERT INTO product_texts (tenant_id, product_id, field, locale, role, value, status, origin, source_hash, source_locale, prompt, model, checks, created_at, updated_at)
            SELECT ?, ?, ?, ?, 'target', ?, ?, ?, ?, ?, ?, ?, ?::jsonb, now(), now()
            WHERE EXISTS (
                SELECT 1 FROM product_texts s
                WHERE s.product_id = ? AND s.field = ? AND s.locale = ? AND s.role = 'source' AND s.source_hash = ?
            )
            ON CONFLICT (product_id, field, locale) DO UPDATE SET
                value = EXCLUDED.value, status = EXCLUDED.status, origin = EXCLUDED.origin, source_hash = EXCLUDED.source_hash,
                source_locale = EXCLUDED.source_locale, prompt = EXCLUDED.prompt, model = EXCLUDED.model, checks = EXCLUDED.checks,
                updated_at = now()
            WHERE product_texts.role = 'target' AND product_texts.origin <> 'manual'
            SQL, [
            $tenantId, $productId, $field, $targetLocale, $value, $status, $origin, $sourceHash, $sourceLocale, $prompt, $model,
            $checks === null ? null : json_encode($checks, JSON_THROW_ON_ERROR),
            $productId, $field, $sourceLocale, $sourceHash,
        ]);

        return $written > 0;
    }
}
