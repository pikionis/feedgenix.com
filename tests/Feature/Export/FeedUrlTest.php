<?php

declare(strict_types=1);

use App\Domain\Export\Actions\RotateFeedToken;
use App\Domain\Export\FeedUrls;
use App\Domain\Export\Jobs\BuildExportJob;
use App\Domain\Export\Models\ExportArtifact;
use App\Domain\Export\Models\ExportProfile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Feed URLs (ADR 0007): the published build only, a request never builds, 503 before the first
 * build, ETags, gzip, rotated tokens with a grace period, and no session for a channel's pull.
 */

beforeEach(function (): void {
    Storage::fake('local');
});

/** A published export of a translated feed, and its token. */
function publishedFeed(array $attributes = []): array
{
    [$tenant, $feed] = translatingWorkspace(['en']);
    startTranslation($tenant, $feed);
    $profile = exportProfile($tenant, $feed, $attributes);
    $token = tenancy()->run($tenant->id, fn (): string => FeedUrls::issue($profile));
    $artifact = buildExport($tenant, $profile);

    return [$tenant, $feed, $profile, $token, $artifact];
}

it('serves the published build as it is, with its ETag, never indexed and without a session', function (): void {
    [, , , $token, $artifact] = publishedFeed();
    $plain = (string) Storage::disk('local')->get("exports/{$artifact->storage_key}/products.csv");

    $response = $this->get("/f/{$token}/products.csv")->assertOk();

    expect($response->streamedContent())->toBe($plain)
        ->and($response->headers->get('Content-Type'))->toBe('text/csv; charset=utf-8')
        ->and($response->headers->get('ETag'))->toBe('"'.hash('sha256', $plain).'"')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($response->headers->getCookies())->toBe([]);
});

it('sends the same build compressed to a client that takes gzip, and as a .gz file', function (): void {
    [, , , $token, $artifact] = publishedFeed();
    $plain = (string) Storage::disk('local')->get("exports/{$artifact->storage_key}/products.csv");

    $negotiated = $this->get("/f/{$token}/products.csv", ['Accept-Encoding' => 'br, gzip'])->assertOk();
    $file = $this->get("/f/{$token}/products.csv.gz")->assertOk();

    expect(gzdecode($negotiated->streamedContent()))->toBe($plain)
        ->and($negotiated->headers->get('Content-Encoding'))->toBe('gzip')
        ->and($negotiated->headers->get('Vary'))->toBe('Accept-Encoding')
        ->and(gzdecode($file->streamedContent()))->toBe($plain)
        ->and($file->headers->get('Content-Type'))->toBe('application/gzip')
        ->and($file->headers->has('Content-Encoding'))->toBeFalse();
});

it('answers 304 to a channel that already has this build', function (): void {
    [, , , $token, $artifact] = publishedFeed();
    $etag = '"'.$artifact->files[0]['sha256'].'"';

    $this->get("/f/{$token}/products.csv", ['If-None-Match' => $etag])->assertStatus(304);
    $this->get("/f/{$token}/products.csv", ['If-None-Match' => '"something-else"'])->assertOk();
});

it('answers 503 before the first build, and never starts one', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $profile = exportProfile($tenant, $feed);
    $token = tenancy()->run($tenant->id, fn (): string => FeedUrls::issue($profile));
    Queue::fake();

    $this->get("/f/{$token}/products.csv")->assertStatus(503)->assertHeader('Retry-After', '300');

    Queue::assertNotPushed(BuildExportJob::class);
    expect(tenancy()->run($tenant->id, fn (): int => ExportArtifact::query()->count()))->toBe(0);
});

it('is a 404 for an unknown token, an unknown file and a deleted export', function (): void {
    [$tenant, , $profile, $token] = publishedFeed();

    $this->get('/f/'.str_repeat('a', 40).'/products.csv')->assertNotFound();
    $this->get("/f/{$token}/products.tsv")->assertNotFound();
    $this->get("/f/{$token}/../products.csv")->assertNotFound();
    $this->get('/f/'.substr($token, 1).'/products.csv')->assertNotFound();

    tenancy()->run($tenant->id, fn () => ExportProfile::query()->whereKey($profile->id)->delete());
    $this->get("/f/{$token}/products.csv")->assertNotFound();
});

it('keeps an old token working for the grace period after a new one, then never again', function (): void {
    [$tenant, , $profile, $old] = publishedFeed();

    $new = tenancy()->run($tenant->id, fn (): string => resolve(RotateFeedToken::class)->handle($profile));
    $this->get("/f/{$new}/products.csv")->assertOk();
    $this->get("/f/{$old}/products.csv")->assertOk();

    $this->travel(73)->hours();
    $this->get("/f/{$old}/products.csv")->assertNotFound();
    $this->get("/f/{$new}/products.csv")->assertOk();
});

it('keeps serving the last build of a paused export', function (): void {
    [$tenant, , $profile, $token] = publishedFeed();
    tenancy()->run($tenant->id, fn () => $profile->forceFill(['status' => 'paused'])->save());

    $this->get("/f/{$token}/products.csv")->assertOk();
});

it('slows down a token pulled more than a channel would', function (): void {
    [, , , $token] = publishedFeed();

    foreach (range(1, 60) as $pull) {
        $this->get("/f/{$token}/products.csv", ['If-None-Match' => '"x"']);
    }
    $this->get("/f/{$token}/products.csv")->assertStatus(429);
});
