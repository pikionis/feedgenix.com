<?php

declare(strict_types=1);

use App\Domain\Ingest\Actions\Uploads;
use App\Domain\Ingest\Models\Import;
use App\Domain\Ingest\Models\UploadSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Storage::fake('local');
});

/** @return array{0: Tenant, 1: int} */
function uploadFeedOf(string $plan = 'free'): array
{
    $tenant = Tenant::factory()->onPlan($plan)->create();

    return [$tenant, (int) createFeed(owner($tenant), ['name' => 'Uploads'])->json('feed.id')];
}

function putChunk(User $user, int $feedId, string $uploadId, int $index, string $bytes): TestResponse
{
    return test()->actingAs($user)->call('PUT', "/api/v1/feeds/{$feedId}/uploads/{$uploadId}/chunks/{$index}", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes);
}

it('uploads a multi-chunk file in any order and imports it', function (): void {
    [$tenant, $feedId] = uploadFeedOf();
    $csv = "sku,description\n".implode("\n", array_map(static fn (int $i): string => "A-{$i},".str_repeat('x', 200), range(1, 60_000)))."\n";
    expect(strlen($csv))->toBeGreaterThan(2 * Uploads::CHUNK_SIZE);

    uploadFeed(owner($tenant), $feedId, $csv, 'C:\\exports\\catalogue.csv')
        ->assertStatus(202)
        ->assertJsonPath('import.status', 'profiled')
        ->assertJsonPath('import.original_name', 'catalogue.csv')
        ->assertJsonPath('import.row_count', 60_000);
});

it('refuses a chunk of the wrong length, and a chunk that does not exist', function (): void {
    [$tenant, $feedId] = uploadFeedOf();
    $id = (string) $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads", ['filename' => 'f.csv', 'size' => 10])->json('upload.id');

    putChunk(owner($tenant), $feedId, $id, 0, '123456789')->assertUnprocessable()->assertJsonValidationErrors('chunk');
    putChunk(owner($tenant), $feedId, $id, 0, '12345678901')->assertUnprocessable()->assertJsonValidationErrors('chunk');
    putChunk(owner($tenant), $feedId, $id, 1, '1234567890')->assertUnprocessable()->assertJsonValidationErrors('index');
    putChunk(owner($tenant), $feedId, $id, 0, "sku\nA-1\nA-")->assertNoContent();
});

it('will not complete with a missing chunk, or with a checksum that does not match', function (): void {
    [$tenant, $feedId] = uploadFeedOf();
    $bytes = "sku\nA-1\n";
    $id = (string) $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads", ['filename' => 'f.csv', 'size' => strlen($bytes)])->json('upload.id');

    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads/{$id}/complete")->assertUnprocessable()->assertJsonValidationErrors('chunks');

    putChunk(owner($tenant), $feedId, $id, 0, $bytes)->assertNoContent();
    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads/{$id}/complete", ['sha256' => str_repeat('0', 64)])
        ->assertUnprocessable()->assertJsonValidationErrors('sha256');

    // The chunks went into the file as it was assembled: this upload is over, and says so.
    expect(tenancy()->run($tenant->id, fn (): int => Import::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads/{$id}/complete")->assertStatus(410);
});

it('refuses files over the size cap before any byte is sent', function (): void {
    [$tenant, $feedId] = uploadFeedOf();

    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads", ['filename' => 'huge.csv', 'size' => (int) config('ingest.limits.max_bytes') + 1])
        ->assertUnprocessable()->assertJsonValidationErrors('size');
});

it('closes a completed upload, and an expired one', function (): void {
    [$tenant, $feedId] = uploadFeedOf();
    $bytes = "sku\nA-1\n";
    $id = (string) $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads", ['filename' => 'f.csv', 'size' => strlen($bytes)])->json('upload.id');
    putChunk(owner($tenant), $feedId, $id, 0, $bytes)->assertNoContent();
    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads/{$id}/complete")->assertStatus(202);

    putChunk(owner($tenant), $feedId, $id, 0, $bytes)->assertStatus(410);
    $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads/{$id}/complete")->assertStatus(410);

    $expired = (string) $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads", ['filename' => 'f.csv', 'size' => 4])->json('upload.id');
    DB::table('upload_sessions')->where('id', $expired)->update(['expires_at' => now()->subMinute()]);
    putChunk(owner($tenant), $feedId, $expired, 0, 'sku ')->assertStatus(410);
});

it('stores chunks only under server-made paths: the file name never reaches the disk', function (): void {
    [$tenant, $feedId] = uploadFeedOf();
    $response = $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads", ['filename' => '../../../etc/cron.d/evil', 'size' => 4])
        ->assertCreated()->assertJsonPath('upload.filename', 'evil');
    $id = (string) $response->json('upload.id');

    putChunk(owner($tenant), $feedId, $id, 0, 'sku ')->assertNoContent();

    $session = tenancy()->run($tenant->id, fn (): UploadSession => UploadSession::query()->findOrFail($id));
    Storage::disk('local')->assertExists("uploads/{$tenant->id}/{$id}/0");
    expect(Storage::disk('local')->allFiles())->toBe(["uploads/{$tenant->id}/{$id}/0"])
        ->and($session->directory())->toBe("uploads/{$tenant->id}/{$id}");
});

it('answers 404 for an upload of another feed or workspace, and for an id that is not one', function (): void {
    [$tenant, $feedId] = uploadFeedOf('pro');
    $otherFeed = (int) createFeed(owner($tenant), ['name' => 'Other'])->json('feed.id');
    $id = (string) $this->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/uploads", ['filename' => 'f.csv', 'size' => 4])->json('upload.id');
    [$stranger] = uploadFeedOf();

    putChunk(owner($tenant), $otherFeed, $id, 0, 'sku ')->assertNotFound();
    putChunk(owner($stranger), $feedId, $id, 0, 'sku ')->assertNotFound();
    putChunk(owner($tenant), $feedId, 'not-a-uuid', 0, 'sku ')->assertNotFound();
    putChunk(owner($tenant), $feedId, $id, 0, 'sku ')->assertNoContent();
});

it('lets members upload into their workspace’s feeds', function (): void {
    [$tenant, $feedId] = uploadFeedOf();

    uploadFeed(memberOf($tenant, 'member'), $feedId, "sku\nA-1\n")->assertStatus(202)->assertJsonPath('import.status', 'profiled');
});

it('removes the chunks once assembled and the staged file once stored', function (): void {
    [$tenant, $feedId] = uploadFeedOf();

    uploadFeed(owner($tenant), $feedId, "sku\nA-1\n")->assertStatus(202);

    $blob = tenancy()->run($tenant->id, fn (): string => (string) Import::query()->firstOrFail()->blob_path);
    expect(Storage::disk('local')->allFiles())->toBe([$blob]);
});
