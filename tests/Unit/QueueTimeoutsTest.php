<?php

declare(strict_types=1);

use App\Domain\Ingest\Jobs\ProcessImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use Symfony\Component\Finder\Finder;

/*
 * Legacy lesson: a queue's retry_after shorter than a job's run time hands the same job to a
 * second worker while the first still runs. Every job must declare a timeout (#[Timeout] or
 * $timeout, as Laravel reads either), and every queue connection must wait longer than the
 * longest one.
 */

/** @return array<class-string, int|null> job class => its timeout in seconds */
function jobTimeouts(): array
{
    $timeouts = [];
    foreach (Finder::create()->files()->in(app_path('Domain'))->path('Jobs')->name('*.php') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getRealPath(), strlen(app_path()) + 1));
        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        $attribute = $reflection->getAttributes(Timeout::class)[0] ?? null;
        $property = $reflection->getDefaultProperties()['timeout'] ?? null;
        $timeouts[$class] = $attribute?->newInstance()->timeout ?? (is_int($property) ? $property : null);
    }

    return $timeouts;
}

it('finds the queued jobs it is meant to check, and reads their timeouts', function (): void {
    expect(jobTimeouts())->toHaveKey(ProcessImport::class)
        ->and(jobTimeouts()[ProcessImport::class])->toBe(10_800);
});

it('has every job declare its timeout', function (): void {
    expect(array_keys(array_filter(jobTimeouts(), static fn (?int $timeout): bool => $timeout === null)))->toBe([]);
});

it('waits longer before retrying than the longest job may run, on every queue connection', function (string $connection): void {
    $longest = max(array_filter(jobTimeouts()));

    expect((int) config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan($longest);
})->with(['database', 'redis', 'beanstalkd']);
