<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Actions;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\Models\Import;
use App\Domain\Ingest\Models\UploadSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

/**
 * Chunked uploads, so a 2 GB feed survives a flaky connection: the client sends fixed-size
 * chunks (any order, any retry), then asks to complete. Every path comes from the server
 * (uploads/{tenant}/{session}/{index}); the client's file name is only ever shown.
 *
 * The legacy lesson: a crafted upload_id made the old endpoint delete files outside storage.
 */
final readonly class Uploads
{
    public const int CHUNK_SIZE = 5 * 1024 * 1024;

    public function __construct(private QueueImport $queue) {}

    public function start(Feed $feed, string $filename, int $size): UploadSession
    {
        return UploadSession::query()->create([
            'feed_id' => $feed->id,
            'filename' => self::displayName($filename),
            'size' => $size,
            'chunk_size' => self::CHUNK_SIZE,
            'total_chunks' => (int) ceil($size / self::CHUNK_SIZE),
            'expires_at' => now()->addDay(),
        ]);
    }

    /**
     * Stores chunk $index from $body. The chunk must have exactly its expected length.
     *
     * @param  resource  $body
     */
    public function store(UploadSession $session, int $index, $body): void
    {
        $this->assertOpen($session);
        if ($index < 0 || $index >= $session->total_chunks) {
            throw ValidationException::withMessages(['index' => "Chunk {$index} does not exist; this upload has {$session->total_chunks}."]);
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory($session->directory());
        $final = $disk->path($session->directory()."/{$index}");
        $partial = $final.'.part';
        $expected = $session->expectedLength($index);

        $out = fopen($partial, 'wb');
        if ($out === false) {
            throw new RuntimeException('Cannot store the chunk.');
        }

        try {
            // One byte past the expected length is enough to know it is too long.
            $written = (int) stream_copy_to_stream($body, $out, $expected + 1);
        } finally {
            fclose($out);
        }

        if ($written !== $expected) {
            unlink($partial);

            throw ValidationException::withMessages(['chunk' => "Chunk {$index} must be {$expected} bytes; {$written} arrived."]);
        }

        rename($partial, $final);
    }

    /** Assembles the chunks, checks the size (and the SHA-256 when given), and queues the import. */
    public function complete(UploadSession $session, ?string $sha256): Import
    {
        $this->assertOpen($session);
        $disk = Storage::disk('local');

        $missing = [];
        for ($index = 0; $index < $session->total_chunks; $index++) {
            $path = $session->directory()."/{$index}";
            if (! $disk->exists($path) || $disk->size($path) !== $session->expectedLength($index)) {
                $missing[] = $index;
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages(['chunks' => 'Missing or incomplete chunks: '.implode(', ', array_slice($missing, 0, 20)).'.']);
        }

        $staged = "staging/{$session->tenant_id}/{$session->id}";
        $disk->makeDirectory(dirname($staged));
        try {
            $this->assemble($session, $disk->path($staged));
        } catch (RuntimeException $failure) {
            $this->abandon($session, $staged);

            throw $failure;
        }

        if ($sha256 !== null && ! hash_equals(strtolower($sha256), (string) hash_file('sha256', $disk->path($staged)))) {
            $this->abandon($session, $staged);

            throw ValidationException::withMessages(['sha256' => 'The assembled file does not match its checksum. Upload it again.']);
        }

        $disk->deleteDirectory($session->directory());
        $import = $this->queue->upload($session->feed()->firstOrFail(), $staged, $session->filename);
        $session->forceFill(['status' => 'completed', 'import_id' => $import->id])->save();

        return $import;
    }

    /**
     * Appends the chunks in order, deleting each once appended: the file never exists twice, which
     * matters at 50 GB. A failure after this point means uploading again.
     */
    private function assemble(UploadSession $session, string $destination): void
    {
        $disk = Storage::disk('local');
        $out = fopen($destination, 'wb');
        if ($out === false) {
            throw new RuntimeException('Cannot assemble the upload.');
        }

        try {
            for ($index = 0; $index < $session->total_chunks; $index++) {
                $chunk = $disk->path($session->directory()."/{$index}");
                $in = fopen($chunk, 'rb');
                if ($in === false) {
                    throw new RuntimeException("Cannot read chunk {$index}.");
                }
                $copied = stream_copy_to_stream($in, $out);
                fclose($in);
                if ($copied !== $session->expectedLength($index)) {
                    throw new RuntimeException("Chunk {$index} could not be appended in full.");
                }
                unlink($chunk);
            }
        } finally {
            fclose($out);
        }

        if (filesize($destination) !== $session->size) {
            unlink($destination);

            throw new RuntimeException('The assembled upload has the wrong size.');
        }
    }

    /** The chunks are gone: this upload cannot complete any more, and says so (410) if asked again. */
    private function abandon(UploadSession $session, string $staged): void
    {
        $disk = Storage::disk('local');
        $disk->delete($staged);
        $disk->deleteDirectory($session->directory());
        $session->forceFill(['expires_at' => now()])->save();
    }

    private function assertOpen(UploadSession $session): void
    {
        if ($session->status !== 'open' || $session->expires_at->isPast()) {
            throw new GoneHttpException('This upload is finished or has expired. Start a new one.');
        }
    }

    /** The name shown to the customer: no directories, no control characters, at most 200 characters. */
    public static function displayName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $base));

        return mb_substr($clean === '' ? 'upload' : $clean, -200);
    }
}
