<?php

declare(strict_types=1);

use App\Domain\Ingest\Fetch\AddressPin;
use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Ingest\Fetch\Fetcher;
use App\Domain\Ingest\Fetch\FetchFailed;
use App\Domain\Ingest\Fetch\FetchFailure;
use App\Domain\Ingest\Fetch\FetchResult;
use App\Domain\Ingest\Fetch\FetchSettings;
use App\Domain\Ingest\Fetch\FileFetcher;
use App\Domain\Ingest\Fetch\HttpFetcher;
use App\Domain\Ingest\Fetch\SourceFetcher;
use Tests\Support\FakeFetchNetwork;

function fileFetcher(FakeFetchNetwork $network, int $maxBytes = 10_000): FileFetcher
{
    return new FileFetcher(new AddressPin($network, false), $network, new FetchSettings(maxBytes: $maxBytes));
}

function fileFailure(callable $fetch): FetchFailed
{
    try {
        $fetch();
    } catch (FetchFailed $failure) {
        return $failure;
    }

    throw new RuntimeException('expected the fetch to fail');
}

function fileDestination(): string
{
    return ingestScratch().'/file-'.uniqid('', true);
}

function filesExample(): FakeFetchNetwork
{
    return (new FakeFetchNetwork)->dns('files.example', ['93.184.215.14']);
}

const FTP_URL = 'ftp://files.example/out/feed.csv';

it('downloads a file, pinned to the address it checked, and remembers its size and time', function (): void {
    $network = filesExample()->file(FTP_URL, "sku\nA-1\n", modifiedAt: 1_790_000_000);
    $destination = fileDestination();

    $result = fileFetcher($network)->fetch(FTP_URL, $destination);

    expect(file_get_contents($destination))->toBe("sku\nA-1\n")
        ->and($result->notModified)->toBeFalse()
        ->and($result->bytes)->toBe(8)
        ->and($result->etag)->toBe('8:1790000000')
        ->and($network->lookups)->toBe(['files.example'])
        ->and(array_column($network->fileRequests, 'address'))->toBe(['93.184.215.14', '93.184.215.14']);
});

it('never connects when the host resolves to a private or metadata address', function (array $answer): void {
    $network = (new FakeFetchNetwork)->dns('evil.example', $answer)->file('ftp://evil.example/f.csv', 'sku');

    $failure = fileFailure(fn (): FetchResult => fileFetcher($network)->fetch('ftp://evil.example/f.csv', fileDestination()));

    expect($failure->reason)->toBe(FetchFailure::BlockedAddress)
        ->and($network->fileRequests)->toBe([]);
})->with([
    'loopback' => [['127.0.0.1']], 'metadata' => [['169.254.169.254']], 'private' => [['10.0.0.5']],
    'one public, one private answer' => [['93.184.215.14', '10.0.0.5']],
]);

it('refuses an IP-literal address into a private network', function (): void {
    $network = (new FakeFetchNetwork)->file('ftp://169.254.169.254/f.csv', 'sku');

    expect(fileFailure(fn (): FetchResult => fileFetcher($network)->fetch('ftp://169.254.169.254/f.csv', fileDestination()))->reason)->toBe(FetchFailure::BlockedAddress)
        ->and($network->fileRequests)->toBe([]);
});

it('is not fooled by DNS rebinding: one lookup, one address', function (): void {
    $network = (new FakeFetchNetwork)->dns('rebind.example', ['93.184.215.14'], ['127.0.0.1'])->file('ftp://rebind.example/f.csv', 'sku');

    fileFetcher($network)->fetch('ftp://rebind.example/f.csv', fileDestination());

    expect($network->lookups)->toBe(['rebind.example'])
        ->and(array_column($network->fileRequests, 'address'))->toBe(['93.184.215.14', '93.184.215.14']);
});

it('does not download a file with the same size and time again', function (): void {
    $network = filesExample()->file(FTP_URL, 'sku', modifiedAt: 1_790_000_000);

    $result = fileFetcher($network)->fetch(FTP_URL, fileDestination(), etag: '3:1790000000');

    expect($result->notModified)->toBeTrue()
        ->and($result->etag)->toBe('3:1790000000')
        ->and(array_column($network->fileRequests, 'call'))->toBe(['info']);
});

it('downloads again when the size or the time changed', function (string $etag): void {
    $network = filesExample()->file(FTP_URL, 'sku', modifiedAt: 1_790_000_000);

    expect(fileFetcher($network)->fetch(FTP_URL, fileDestination(), etag: $etag)->notModified)->toBeFalse();
})->with(['4:1790000000', '3:1790000001']);

it('downloads every time when the server does not say both the size and the time', function (?int $size, ?int $modifiedAt): void {
    $network = filesExample()->file(FTP_URL, 'sku', size: $size, modifiedAt: $modifiedAt);

    $result = fileFetcher($network)->fetch(FTP_URL, fileDestination(), etag: ':');

    expect($result->notModified)->toBeFalse()->and($result->etag)->toBeNull();
})->with([[null, 1_790_000_000], [3, null], [null, null]]);

it('refuses a file the server says is too large before downloading any of it', function (): void {
    $network = filesExample()->file(FTP_URL, str_repeat('x', 200));

    expect(fileFailure(fn (): FetchResult => fileFetcher($network, 100)->fetch(FTP_URL, fileDestination()))->reason)->toBe(FetchFailure::TooLarge)
        ->and(array_column($network->fileRequests, 'call'))->toBe(['info']);
});

it('keeps to the plan’s limit when it is below the server’s', function (): void {
    $network = filesExample()->file(FTP_URL, str_repeat('x', 200));

    expect(fileFailure(fn (): FetchResult => fileFetcher($network)->fetch(FTP_URL, fileDestination(), maxBytes: 100))->reason)->toBe(FetchFailure::TooLarge);
});

it('still stops at the cap when the server does not say the size', function (): void {
    $network = filesExample()->file(FTP_URL, str_repeat('x', 200), size: null);

    expect(fileFailure(fn (): FetchResult => fileFetcher($network, 100)->fetch(FTP_URL, fileDestination()))->reason)->toBe(FetchFailure::TooLarge)
        ->and(array_column($network->fileRequests, 'call'))->toBe(['info', 'download']);
});

it('treats a body short of the announced size as a cut-off download', function (): void {
    $network = filesExample()->file(FTP_URL, 'sku', size: 5000);

    expect(fileFailure(fn (): FetchResult => fileFetcher($network)->fetch(FTP_URL, fileDestination()))->reason)->toBe(FetchFailure::Truncated);
});

it('hands the login to the server, and refuses a header, which FTP has no place for', function (): void {
    $network = filesExample()->file(FTP_URL, 'sku');
    $login = Credentials::password('someone', 'secret');

    fileFetcher($network)->fetch(FTP_URL, fileDestination(), credentials: $login);
    expect($network->fileRequests[0]['login'])->toBe($login)
        ->and($network->fileRequests[1]['login'])->toBe($login);

    $failure = fileFailure(fn (): FetchResult => fileFetcher($network)->fetch(FTP_URL, fileDestination(), credentials: Credentials::header('X-Key', 'v')));
    expect($failure->reason)->toBe(FetchFailure::InvalidUrl)
        ->and($failure->getMessage())->toContain('https://')
        ->and($network->fileRequests)->toHaveCount(2);
});

it('passes on what the server says, and stops', function (FetchFailure $reason): void {
    $network = filesExample()->failFile(FTP_URL, $reason);

    expect(fileFailure(fn (): FetchResult => fileFetcher($network)->fetch(FTP_URL, fileDestination()))->reason)->toBe($reason)
        ->and(array_column($network->fileRequests, 'call'))->toBe(['info']);
})->with([FetchFailure::LoginRefused, FetchFailure::NotFound, FetchFailure::TlsFailed, FetchFailure::ConnectFailed]);

it('is what the app fetches with', function (): void {
    expect(resolve(Fetcher::class))->toBeInstanceOf(SourceFetcher::class);
});

it('sends each address to the fetcher of its kind', function (): void {
    $network = filesExample()->dns('shop.example', ['93.184.215.15'])
        ->file(FTP_URL, 'from ftp')->respond('https://shop.example/f.csv', 200, 'from the web');
    $settings = new FetchSettings(maxBytes: 10_000);
    $router = new SourceFetcher(new HttpFetcher($network, $network, $settings), fileFetcher($network));

    $ftp = fileDestination();
    $web = fileDestination();
    $router->fetch(FTP_URL, $ftp);
    $router->fetch('https://shop.example/f.csv', $web);

    expect(file_get_contents($ftp))->toBe('from ftp')
        ->and(file_get_contents($web))->toBe('from the web')
        ->and($network->requests)->toHaveCount(1)
        ->and($network->fileRequests)->toHaveCount(2);
});
