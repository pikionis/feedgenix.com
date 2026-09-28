<?php

declare(strict_types=1);

use App\Support\TypedEnv;

afterEach(function (): void {
    putenv('FG_TYPED_ENV_TEST');
    unset($_ENV['FG_TYPED_ENV_TEST'], $_SERVER['FG_TYPED_ENV_TEST']);
});

function setTestEnv(string $value): void
{
    putenv("FG_TYPED_ENV_TEST={$value}");
    $_ENV['FG_TYPED_ENV_TEST'] = $value;
    $_SERVER['FG_TYPED_ENV_TEST'] = $value;
}

it('returns the string value', function (): void {
    setTestEnv('https://staging.example.test');

    expect(TypedEnv::string('FG_TYPED_ENV_TEST', 'http://localhost'))->toBe('https://staging.example.test');
});

it('returns the default when the variable is unset', function (): void {
    expect(TypedEnv::string('FG_TYPED_ENV_TEST', 'http://localhost'))->toBe('http://localhost');
});

it('keeps an explicitly empty value empty', function (): void {
    setTestEnv('');

    expect(TypedEnv::string('FG_TYPED_ENV_TEST', 'http://localhost'))->toBe('');
});

it('fails fast on a value env() would turn into a non-string', function (string $raw, string $type): void {
    setTestEnv($raw);

    expect(fn (): string => TypedEnv::string('FG_TYPED_ENV_TEST', 'http://localhost'))
        ->toThrow(InvalidArgumentException::class, "Environment variable [FG_TYPED_ENV_TEST] must be a string, got {$type}.");
})->with([
    'true' => ['true', 'bool'],
    'false' => ['(false)', 'bool'],
    'null' => ['null', 'null'],
]);
