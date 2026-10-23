<?php

declare(strict_types=1);

namespace App\Domain\Export\Actions;

use App\Domain\Export\ColumnPlan;
use App\Domain\Export\Exceptions\ProfileChanged;
use App\Domain\Export\FeedUrls;
use App\Domain\Export\Models\ExportProfile;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Translation\TranslationPlanner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates or changes an export profile. The dialect and layout must be ones its preset allows; the
 * languages must be the feed's own or the workspace's. A new profile gets its token and a first
 * build; a change that alters the files builds again.
 */
final readonly class SaveExportProfile
{
    public function __construct(
        private TranslationPlanner $planner,
        private QueueExportBuild $queue,
    ) {}

    /**
     * @param  list<string>  $locales
     *
     * @throws InvalidArgumentException
     */
    public function create(Feed $feed, string $name, string $preset, string $dialect, string $layout, array $locales): ExportProfile
    {
        $this->check($feed, $preset, $dialect, $layout, $locales);
        try {
            $profile = DB::transaction(static function () use ($feed, $name, $preset, $dialect, $layout, $locales): ExportProfile {
                $profile = ExportProfile::query()->forceCreate([
                    'feed_id' => $feed->id, 'name' => trim($name), 'preset' => $preset, 'dialect' => $dialect,
                    'layout' => $layout, 'locales' => array_values(array_unique($locales)),
                ]);
                FeedUrls::issue($profile);

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('This feed already has an export with that name.');
        }
        $this->queue->handle($profile);

        // The build may have run already (a sync queue): what it published belongs in the answer.
        return $profile->refresh();
    }

    /**
     * @param  array{name?: string, dialect?: string, layout?: string, locales?: list<string>, status?: string}  $changes
     *
     * @throws ProfileChanged on a stale version
     * @throws InvalidArgumentException
     */
    public function update(ExportProfile $profile, int $version, array $changes): ExportProfile
    {
        $dialect = $changes['dialect'] ?? $profile->dialect;
        $layout = $changes['layout'] ?? $profile->layout;
        $locales = $changes['locales'] ?? $profile->locales;
        $this->check($profile->feed, $profile->preset, $dialect, $layout, $locales);
        if (isset($changes['status']) && ! in_array($changes['status'], ['active', 'paused'], true)) {
            throw new InvalidArgumentException('An export is active or paused.');
        }

        $rebuild = false;
        try {
            DB::transaction(static function () use ($profile, $version, $changes, $dialect, $layout, $locales, &$rebuild): void {
                $locked = ExportProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
                if ($locked->version !== $version) {
                    throw new ProfileChanged($locked->version);
                }
                $rebuild = $dialect !== $locked->dialect || $layout !== $locked->layout || $locales !== $locked->locales
                    || (($changes['status'] ?? $locked->status) === 'active' && $locked->status === 'paused');
                $locked->forceFill([
                    'name' => isset($changes['name']) ? trim($changes['name']) : $locked->name,
                    'dialect' => $dialect, 'layout' => $layout, 'locales' => array_values(array_unique($locales)),
                    'status' => $changes['status'] ?? $locked->status,
                    'version' => $locked->version + 1,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('This feed already has an export with that name.');
        }
        $profile->refresh();
        if ($rebuild && $profile->status === 'active') {
            $this->queue->handle($profile);
        }

        return $profile->refresh();
    }

    /** @param list<string> $locales */
    private function check(Feed $feed, string $preset, string $dialect, string $layout, array $locales): void
    {
        $definition = ColumnPlan::preset($preset);
        if (! in_array($dialect, $definition['dialects'], true)) {
            throw new InvalidArgumentException("{$definition['label']} is written as ".implode(' or ', $definition['dialects']).'.');
        }
        if (! in_array($layout, $definition['layouts'], true)) {
            throw new InvalidArgumentException("{$definition['label']} needs ".($definition['layouts'] === ['per_language'] ? 'one file per language' : 'one file with a column per language').'.');
        }
        if ($locales === []) {
            throw new InvalidArgumentException('Choose at least one language.');
        }
        $available = self::languages($feed, $this->planner);
        foreach ($locales as $locale) {
            if (! in_array($locale, $available, true)) {
                throw new InvalidArgumentException("[{$locale}] is neither a language of the feed nor one of the workspace's.");
            }
        }
    }

    /** @return list<string> the languages an export of the feed can hold: the feed's own and the workspace's */
    public static function languages(Feed $feed, TranslationPlanner $planner): array
    {
        $own = array_column($planner->sources($feed), 'locale');

        return array_values(array_unique([...$own, ...TenantLanguage::query()->orderBy('language_code')->pluck('language_code')->all()]));
    }
}
