<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * All AI goes through the gateway (ADR 0004). A model host's address may appear only where the
 * gateway reaches it: config/ai.php and the provider classes. Anywhere else it is a second path.
 */
const AI_HOSTS = '/generativelanguage\.googleapis\.com|aiplatform\.googleapis\.com|api\.anthropic\.com|api\.openai\.com/i';

const AI_HOST_HOMES = ['config/ai.php', 'app/Domain/AI/Providers/'];

/** @return list<string> */
function aiHostHits(): array
{
    $root = dirname(__DIR__, 2);
    $dirs = array_values(array_filter(
        [$root.'/app', $root.'/bootstrap', $root.'/config', $root.'/database', $root.'/routes', $root.'/resources', $root.'/frontend/src', $root.'/ops'],
        is_dir(...),
    ));

    $hits = [];
    foreach (Finder::create()->files()->in($dirs)->exclude(['node_modules', 'cache']) as $file) {
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $isHome = array_filter(AI_HOST_HOMES, static fn (string $home): bool => str_starts_with($path, $home)) !== [];
        if (! $isHome && preg_match(AI_HOSTS, $file->getContents(), $match) === 1) {
            $hits[] = "{$path} → {$match[0]}";
        }
    }

    return $hits;
}

it('names model hosts only in the AI config and the providers', function (): void {
    expect(aiHostHits())->toBe([]);
});

it('really detects a model host (guards against a silently broken scanner)', function (): void {
    expect(preg_match(AI_HOSTS, "Http::post('https://api.anthropic.com/v1/messages')"))->toBe(1)
        ->and(preg_match(AI_HOSTS, 'https://generativelanguage.googleapis.com/v1beta/models'))->toBe(1);
});
