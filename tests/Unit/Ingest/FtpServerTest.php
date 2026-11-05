<?php

declare(strict_types=1);

use App\Domain\Ingest\Fetch\AddressPin;
use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Ingest\Fetch\CurlFtpServer;
use App\Domain\Ingest\Fetch\DnsResolver;
use App\Domain\Ingest\Fetch\FetchFailed;
use App\Domain\Ingest\Fetch\FetchFailure;
use App\Domain\Ingest\Fetch\FetchResult;
use App\Domain\Ingest\Fetch\FetchSettings;
use App\Domain\Ingest\Fetch\FileFetcher;
use Tests\Support\FtpFixture;

/*
 * The real curl client against a real FTP server: a process of the test suite's own, on 127.0.0.1.
 * Nothing leaves the machine.
 */

/** @param array<string, mixed> $config */
function ftpFixture(array $config): FtpFixture
{
    return new FtpFixture($config);
}

function realFtp(int $maxBytes = 1_000_000): FileFetcher
{
    return new FileFetcher(
        new AddressPin(new DnsResolver, true),
        new CurlFtpServer,
        new CurlFtpServer,
        new FetchSettings(maxBytes: $maxBytes, connectTimeout: 3, timeout: 15),
    );
}

function ftpFailure(callable $fetch): FetchFailed
{
    try {
        $fetch();
    } catch (FetchFailed $failure) {
        return $failure;
    }

    throw new RuntimeException('expected the fetch to fail');
}

function ftpDestination(): string
{
    return ingestScratch().'/received-'.uniqid('', true);
}

afterEach(function (): void {
    FtpFixture::stopAll();
});

const FTP_FEED = "sku;title\r\nA-1;Hämmer\r\n";

it('downloads a file byte for byte, in binary mode, with its size and time', function (): void {
    $server = ftpFixture(['files' => ['/out/feed.csv' => FTP_FEED]]);
    $destination = ftpDestination();

    $result = realFtp()->fetch($server->url('/out/feed.csv'), $destination);

    expect(file_get_contents($destination))->toBe(FTP_FEED)
        ->and($result->bytes)->toBe(strlen(FTP_FEED))
        ->and($result->etag)->toBe(strlen(FTP_FEED).':'.gmmktime(10, 15, 0, 9, 29, 2026))
        ->and($server->commands())->toContain('type=I')->not->toContain('type=A');
});

it('sends the login to the server, and logs in anonymously without one', function (): void {
    $server = ftpFixture(['files' => ['/feed.csv' => FTP_FEED]]);
    realFtp()->fetch($server->url('/feed.csv'), ftpDestination(), credentials: Credentials::password('shop@host', 'pass word'));
    expect($server->commands())->toContain('USER shop@host')->toContain('PASS pass word');

    $anonymous = ftpFixture(['files' => ['/feed.csv' => FTP_FEED]]);
    realFtp()->fetch($anonymous->url('/feed.csv'), ftpDestination());
    expect($anonymous->commands())->toContain('USER anonymous');
});

it('tells a refused login from a missing one', function (): void {
    $server = ftpFixture(['files' => ['/feed.csv' => FTP_FEED], 'user' => 'shop', 'pass' => 'right']);

    expect(ftpFailure(fn (): FetchResult => realFtp()->fetch($server->url('/feed.csv'), ftpDestination(), credentials: Credentials::password('shop', 'wrong')))->reason)->toBe(FetchFailure::LoginRefused)
        ->and(ftpFailure(fn (): FetchResult => realFtp()->fetch($server->url('/feed.csv'), ftpDestination()))->reason)->toBe(FetchFailure::LoginNeeded);

    $destination = ftpDestination();
    realFtp()->fetch($server->url('/feed.csv'), $destination, credentials: Credentials::password('shop', 'right'));
    expect(file_get_contents($destination))->toBe(FTP_FEED);
});

it('never repeats the password in a failure', function (): void {
    $server = ftpFixture(['files' => ['/feed.csv' => FTP_FEED], 'user' => 'shop', 'pass' => 'right']);

    expect(ftpFailure(fn (): FetchResult => realFtp()->fetch($server->url('/feed.csv'), ftpDestination(), credentials: Credentials::password('shop', 'wrong-pass')))->getMessage())
        ->not->toContain('wrong-pass')->not->toContain('shop');
});

it('says when the file is not there', function (string $path): void {
    $server = ftpFixture(['files' => ['/out/feed.csv' => FTP_FEED]]);

    expect(ftpFailure(fn (): FetchResult => realFtp()->fetch($server->url($path), ftpDestination()))->reason)->toBe(FetchFailure::NotFound);
})->with(['/out/other.csv', '/missing/feed.csv']);

it('ignores the address in a passive reply: a server cannot send the data connection elsewhere', function (): void {
    // Reached at all, this address would hang until the connect timeout and fail the download.
    $server = ftpFixture(['files' => ['/feed.csv' => FTP_FEED], 'pasv_ip' => '10.255.255.1']);
    $destination = ftpDestination();

    realFtp()->fetch($server->url('/feed.csv'), $destination);

    expect(file_get_contents($destination))->toBe(FTP_FEED)
        ->and($server->commands())->toContain('PASV');
});

it('uses extended passive mode when the server offers it, and never an active connection', function (): void {
    $server = ftpFixture(['files' => ['/feed.csv' => FTP_FEED], 'epsv' => true]);

    realFtp()->fetch($server->url('/feed.csv'), ftpDestination());

    expect($server->commands())->toContain('EPSV')
        ->and(array_filter($server->commands(), static fn (string $command): bool => str_starts_with($command, 'PORT') || str_starts_with($command, 'EPRT')))->toBe([]);
});

it('never downloads a file with the same size and time again', function (): void {
    $server = ftpFixture(['files' => ['/feed.csv' => FTP_FEED]]);
    $first = realFtp()->fetch($server->url('/feed.csv'), ftpDestination());

    $second = realFtp()->fetch($server->url('/feed.csv'), ftpDestination(), etag: $first->etag);

    expect($second->notModified)->toBeTrue()
        ->and(array_filter($server->commands(), static fn (string $command): bool => str_starts_with($command, 'RETR')))->toHaveCount(1);
});

it('downloads every time from a server that does not say the time', function (): void {
    $server = ftpFixture(['files' => ['/feed.csv' => FTP_FEED], 'mdtm' => false]);

    $result = realFtp()->fetch($server->url('/feed.csv'), ftpDestination());

    expect($result->etag)->toBeNull()->and($result->bytes)->toBe(strlen(FTP_FEED));
});

it('refuses a file over the cap without fetching it', function (): void {
    $server = ftpFixture(['files' => ['/feed.csv' => str_repeat('x', 5000)]]);

    expect(ftpFailure(fn (): FetchResult => realFtp(1000)->fetch($server->url('/feed.csv'), ftpDestination()))->reason)->toBe(FetchFailure::TooLarge)
        ->and(array_filter($server->commands(), static fn (string $command): bool => str_starts_with($command, 'RETR')))->toBe([]);
});

it('stops at the cap when the server does not say the size', function (): void {
    $server = ftpFixture(['files' => ['/feed.csv' => str_repeat('x', 5000)], 'size' => false]);
    $destination = ftpDestination();

    expect(ftpFailure(fn (): FetchResult => realFtp(1000)->fetch($server->url('/feed.csv'), $destination))->reason)->toBe(FetchFailure::TooLarge)
        ->and($server->commands())->toContain('RETR feed.csv')
        ->and(filesize($destination))->toBeLessThanOrEqual(1000);
});

it('cannot be talked past the cap by a server that claims a small file', function (): void {
    $server = ftpFixture(['files' => ['/feed.csv' => str_repeat('x', 5000)], 'size_says' => 10]);
    $destination = ftpDestination();

    realFtp(1000)->fetch($server->url('/feed.csv'), $destination);

    expect(filesize($destination))->toBeLessThanOrEqual(1000);
});

it('notices a download cut short', function (): void {
    $server = ftpFixture(['files' => ['/feed.csv' => str_repeat('x', 5000)], 'send_only' => 1000]);

    expect(ftpFailure(fn (): FetchResult => realFtp()->fetch($server->url('/feed.csv'), ftpDestination()))->reason)->toBe(FetchFailure::Truncated);
});

it('sends no password to a server that does not offer TLS on an ftps address', function (): void {
    $server = ftpFixture(['files' => ['/feed.csv' => FTP_FEED]]);

    $failure = ftpFailure(fn (): FetchResult => realFtp()->fetch($server->url('/feed.csv', 'ftps'), ftpDestination(), credentials: Credentials::password('shop', 'secret')));

    expect($failure->reason)->toBe(FetchFailure::TlsFailed)
        ->and($failure->getMessage())->toContain('login was not sent')
        ->and($server->commands())->toContain('AUTH TLS')
        ->and(implode("\n", $server->commands()))->not->toContain('PASS')->not->toContain('USER');
});

it('reports a server that does not answer', function (): void {
    $server = ftpFixture(['files' => []]);
    $server->stop();

    expect(ftpFailure(fn (): FetchResult => realFtp()->fetch($server->url('/feed.csv'), ftpDestination()))->reason)->toBe(FetchFailure::ConnectFailed);
});
