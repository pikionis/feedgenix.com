<?php

declare(strict_types=1);

use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\ProviderResult;
use App\Domain\Credits\Actions\GrantCredits;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Translation\Models\WorkUnit;
use App\Models\Tenant;

/*
 * Translation helpers: a fake translator that writes each target's script and keeps the numbers,
 * a funded workspace with a synced feed, and starting the plan through the API.
 */

/** A translation the checks accept: a word of the target's script, the source's numbers, its tags. */
function fakeTranslation(string $source, string $target): string
{
    $word = match (explode('-', $target)[0]) {
        'el' => 'Μετάφραση',
        'ja' => '翻訳されたテキスト',
        'ar' => 'نص مترجم',
        'uk' => 'Переклад',
        default => "[{$target}]",
    };
    preg_match_all('/\d+(?:[.,]\d+)*/u', strip_tags($source), $numbers);
    $parts = [$word, ...$numbers[0]];
    if (preg_match('/\p{Latin}/u', $word) === 1) {
        $parts = [$word, strip_tags($source)];
    }
    $text = implode(' ', $parts);

    // Keep the markup: the same tags around the translated text.
    return preg_match('~^(<[^>]+>)+~', $source, $open) === 1 && preg_match('~(</[^>]+>)+$~', $source, $close) === 1
        ? $open[0].$text.$close[0]
        : $text;
}

/** A fake model that translates every item it is given. */
function translator(?Closure $adjust = null): Closure
{
    return static function (AiRequest $request) use ($adjust): ProviderResult {
        preg_match('/\(([A-Za-z-]+)\)\.\s/', $request->prompt->system, $target);
        $values = [];
        foreach ($request->items as $item) {
            $values[$item->id] = ['text' => fakeTranslation($item->text, $target[1] ?? 'en')];
        }
        $values = $adjust instanceof Closure ? $adjust($values, $request) : $values;

        return new ProviderResult($values, new AiUsage(inputTokens: 800, outputTokens: 300), (string) $request->model);
    };
}

/** A funded workspace with English and French, a synced German feed, and fake AI translating. */
function translatingWorkspace(array $languages = ['en', 'fr'], int $credits = 200_000_000): array
{
    $tenant = aiWorkspace();
    foreach ($languages as $language) {
        addLanguage($tenant, $language);
    }
    tenancy()->run($tenant->id, fn () => resolve(GrantCredits::class)->handle($credits));
    $feed = profiledFeed($tenant);
    approveMapping($tenant, $feed);
    $fakes = fakeAi();
    $fakes['gemini']->push(...array_fill(0, 200, translator()));

    return [$tenant, $feed, $fakes];
}

function startTranslation(Tenant $tenant, Feed $feed): array
{
    $version = (int) test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/translation-plan")->json('translation_plan.version');

    return test()->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feed->id}/translation-plan/start", ['version' => $version])
        ->assertStatus(202)->json('translation_plan');
}

/** @return array<string, int> status => units */
function unitStatuses(Tenant $tenant): array
{
    return tenancy()->run($tenant->id, static fn (): array => WorkUnit::query()->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status')->map(static fn (mixed $value): int => (int) $value)->all());
}
