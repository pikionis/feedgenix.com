<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Actions;

use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\ImportStatus;
use App\Domain\Ingest\Models\Import;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Heuristics;
use App\Domain\Mapping\Models\Mapping;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The mapping for a feed's current columns: the one it already has for this column signature
 * (legacy D6 reused a mapping by file id, across uploads with other columns), or a free draft from
 * the heuristics. No AI is called here.
 */
final readonly class DraftMapping
{
    public function __construct(
        private StoreDecisions $store,
        private CanonicalSchema $schema,
    ) {}

    /** The mapping of the feed's current profile, drafted if it has none yet; null before any profile. */
    public function current(Feed $feed): ?Mapping
    {
        $import = Import::query()
            ->where('feed_id', $feed->id)
            ->where('status', ImportStatus::Profiled->value)
            ->whereNotNull('column_signature')
            ->orderByDesc('id')
            ->first();

        return $import instanceof Import ? $this->handle($import) : null;
    }

    public function handle(Import $import): Mapping
    {
        $signature = (string) $import->column_signature;
        $existing = Mapping::query()->where('feed_id', $import->feed_id)->where('column_signature', $signature)->first();
        if ($existing instanceof Mapping) {
            return $existing;
        }

        $input = MappingInput::fromImport($import);
        $sourceLocale = Heuristics::sourceLocale($input);
        try {
            // Its own (sub)transaction: a unique violation must not abort the caller's transaction.
            $mapping = DB::transaction(static fn (): Mapping => Mapping::query()->forceCreate([
                'feed_id' => $import->feed_id,
                'column_signature' => $signature,
                'import_id' => $import->id,
                'source_locale' => $sourceLocale,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Drafted at the same moment by another request or job.
            return Mapping::query()->where('feed_id', $import->feed_id)->where('column_signature', $signature)->firstOrFail();
        }

        return $this->store->handle($mapping, (new Heuristics($this->schema))->propose($input, $sourceLocale), isChange: false);
    }
}
