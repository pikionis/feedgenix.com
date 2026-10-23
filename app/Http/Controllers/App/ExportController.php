<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Export\Actions\PublishArtifact;
use App\Domain\Export\Actions\QueueExportBuild;
use App\Domain\Export\Actions\RotateFeedToken;
use App\Domain\Export\Actions\SaveExportProfile;
use App\Domain\Export\Exceptions\ProfileChanged;
use App\Domain\Export\Models\ExportArtifact;
use App\Domain\Export\Models\ExportProfile;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Translation\Models\Language;
use App\Domain\Translation\TranslationPlanner;
use App\Http\Controllers\App\Concerns\ActsInWorkspace;
use App\Http\Resources\ExportProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ExportController
{
    use ActsInWorkspace;

    /** The feed's exports with their URLs and builds, the presets, and the languages an export can hold. */
    public function index(Feed $feed, TranslationPlanner $planner): JsonResponse
    {
        $names = Language::query()->pluck('name', 'code');

        return response()->json([
            'exports' => ExportProfile::query()->where('feed_id', $feed->id)->orderBy('id')->get()
                ->map(static fn (ExportProfile $profile): ExportProfileResource => new ExportProfileResource($profile))->values()->all(),
            /** @var list<array{key: string, label: string, dialects: list<'csv'|'tsv'>, layouts: list<'wide'|'per_language'>}> */
            'presets' => collect((array) config('export.presets'))->map(static fn (array $preset, string $key): array => [
                'key' => $key, 'label' => (string) $preset['label'], 'dialects' => $preset['dialects'], 'layouts' => $preset['layouts'],
            ])->values()->all(),
            /** @var list<array{code: string, name: string}> */
            'locales' => array_map(static fn (string $code): array => ['code' => $code, 'name' => (string) ($names[$code] ?? $code)], SaveExportProfile::languages($feed, $planner)),
        ]);
    }

    /** Create an export (owner or admin): it gets its URLs at once and its first build is queued. */
    public function store(Request $request, Feed $feed, SaveExportProfile $save): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'preset' => ['required', 'string', 'max:32'],
            'dialect' => ['required', 'string', 'in:csv,tsv'],
            'layout' => ['required', 'string', 'in:wide,per_language'],
            'locales' => ['required', 'array', 'min:1', 'max:50'],
            'locales.*' => ['string', 'max:16'],
        ]);
        $profile = $this->apply(static fn (): ExportProfile => $save->create($feed, (string) $data['name'], (string) $data['preset'], (string) $data['dialect'], (string) $data['layout'], array_values(array_map(strval(...), (array) $data['locales']))));

        return (new ExportProfileResource($profile))->response()->setStatusCode(201);
    }

    /**
     * Change an export (owner or admin): its name, dialect, layout, languages, or pause it. A paused
     * export keeps serving its last build. 409 on a stale version.
     *
     * @throws ProfileChanged
     */
    public function update(Request $request, ExportProfile $export, SaveExportProfile $save): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'dialect' => ['sometimes', 'string', 'in:csv,tsv'],
            'layout' => ['sometimes', 'string', 'in:wide,per_language'],
            'locales' => ['sometimes', 'array', 'min:1', 'max:50'],
            'locales.*' => ['string', 'max:16'],
            'status' => ['sometimes', 'string', 'in:active,paused'],
        ]);
        /** @var array{name?: string, dialect?: string, layout?: string, locales?: list<string>, status?: string} $changes */
        $changes = array_intersect_key($data, array_flip(['name', 'dialect', 'layout', 'locales', 'status']));
        $profile = $this->apply(static fn (): ExportProfile => $save->update($export, (int) $data['version'], $changes));

        return (new ExportProfileResource($profile))->response()->setStatusCode(200);
    }

    /** Delete an export (owner or admin): its URLs stop working at once. */
    public function destroy(Request $request, ExportProfile $export): Response
    {
        $this->authorizeManage($request);
        $export->delete();

        return response()->noContent();
    }

    /** Build it now (owner or admin). `allow_shrink` publishes a build the shrink guard would hold back. */
    public function build(Request $request, ExportProfile $export, QueueExportBuild $queue): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['allow_shrink' => ['sometimes', 'boolean']]);
        $queue->handle($export, (bool) ($data['allow_shrink'] ?? false));

        return (new ExportProfileResource($export->refresh()))->response()->setStatusCode(202);
    }

    /** New URLs (owner or admin); the old ones keep working for the grace period. */
    public function rotate(Request $request, ExportProfile $export, RotateFeedToken $rotate): JsonResponse
    {
        $this->authorizeManage($request);
        $rotate->handle($export);

        return (new ExportProfileResource($export->refresh()))->response()->setStatusCode(200);
    }

    /** Serve an earlier build again (owner or admin). */
    public function publish(Request $request, ExportProfile $export, ExportArtifact $artifact, PublishArtifact $publish): JsonResponse
    {
        $this->authorizeManage($request);
        $profile = $this->apply(static fn (): ExportProfile => $publish->handle($export, $artifact));

        return (new ExportProfileResource($profile))->response()->setStatusCode(200);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    private function apply(callable $change): mixed
    {
        try {
            return $change();
        } catch (InvalidArgumentException $invalid) {
            throw ValidationException::withMessages(['export' => $invalid->getMessage()]);
        }
    }
}
