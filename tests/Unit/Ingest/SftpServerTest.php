<?php

declare(strict_types=1);

use App\Domain\Ingest\Fetch\AddressPin;
use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Ingest\Fetch\DnsResolver;
use App\Domain\Ingest\Fetch\FetchFailed;
use App\Domain\Ingest\Fetch\FetchFailure;
use App\Domain\Ingest\Fetch\FetchResult;
use App\Domain\Ingest\Fetch\FetchSettings;
use App\Domain\Ingest\Fetch\FileFetcher;
use App\Domain\Ingest\Fetch\FileInfo;
use App\Domain\Ingest\Fetch\PhpseclibSftpConnector;
use App\Domain\Ingest\Fetch\SftpServer;
use App\Domain\Ingest\Fetch\Target;
use App\Domain\Ingest\Fetch\UrlGuard;
use phpseclib4\Crypt\EC;
use Tests\Support\FakeSftp;

function sftpTarget(string $url = 'sftp://files.example/out/feed.csv'): Target
{
    return UrlGuard::check($url);
}

function sftpFailure(callable $call): FetchFailed
{
    try {
        $call();
    } catch (FetchFailed $failure) {
        return $failure;
    }

    throw new RuntimeException('expected the call to fail');
}

function sftpLogin(): Credentials
{
    return Credentials::password('someone', 'secret');
}

function sftpInfo(FakeSftp $fake, ?Credentials $login, ?string $pinned = null, string $url = 'sftp://files.example/out/feed.csv'): FileInfo
{
    return (new SftpServer($fake))->info(sftpTarget($url), '93.184.215.14', $login, $pinned, new FetchSettings(maxBytes: 1000));
}

describe('who answers', function (): void {
    it('prints a fingerprint the way ssh-keygen does', function (): void {
        // ssh-keygen -lf of this very key prints the same.
        $blob = base64_encode('the key bytes');
        $expected = 'SHA256:'.rtrim(base64_encode(hash('sha256', 'the key bytes', true)), '=');

        expect(SftpServer::fingerprint("ssh-ed25519 {$blob}"))->toBe($expected)
            ->and($expected)->toMatch(SftpServer::FINGERPRINT_PATTERN);
    });

    it('connects to the checked address and port, and nowhere else', function (): void {
        $fake = new FakeSftp;

        sftpInfo($fake, sftpLogin(), url: 'sftp://files.example:2222/out/feed.csv');

        expect($fake->connectedTo)->toBe('93.184.215.14:2222');
    });

    it('trusts the key of a server it has not met, and says which', function (): void {
        $fake = new FakeSftp;

        $info = sftpInfo($fake, sftpLogin());

        expect($info->hostKey)->toBe(SftpServer::fingerprint((string) $fake->publicKey))
            ->and($fake->told)->toContain('login');
    });

    it('goes on when the server shows the key it was trusted with', function (): void {
        $fake = new FakeSftp;

        sftpInfo($fake, sftpLogin(), SftpServer::fingerprint((string) $fake->publicKey));

        expect($fake->told)->toContain('login');
    });

    it('tells a server with another key nothing: no username, no password, no key', function (Credentials $login): void {
        $fake = new FakeSftp;
        $trusted = 'SHA256:'.str_repeat('A', 43);

        $failure = sftpFailure(fn (): FileInfo => sftpInfo($fake, $login, $trusted));

        expect($failure->reason)->toBe(FetchFailure::HostKeyChanged)
            ->and($failure->getMessage())->toContain($trusted)->toContain(SftpServer::fingerprint((string) $fake->publicKey))->toContain('login was not sent')
            ->and($fake->told)->toBe(['connect']);
    })->with([
        'a password' => [fn (): Credentials => Credentials::password('someone', 'secret')],
        'a private key' => [fn (): Credentials => Credentials::key('someone', EC::createKey('Ed25519')->toString('OpenSSH'))],
    ]);

    it('tells a server that cannot prove its key nothing either, with or without a trusted one', function (?string $pinned): void {
        $fake = new FakeSftp;
        $fake->publicKey = null;

        expect(sftpFailure(fn (): FileInfo => sftpInfo($fake, sftpLogin(), $pinned))->reason)->toBe(FetchFailure::HostKeyChanged)
            ->and($fake->told)->toBe(['connect']);
    })->with([null, 'SHA256:'.str_repeat('A', 43)]);

    it('checks the key on the connection that downloads too', function (): void {
        $fake = new FakeSftp;
        $sink = fopen('php://memory', 'wb+');

        $failure = sftpFailure(fn (): int => (new SftpServer($fake))->download(sftpTarget(), '93.184.215.14', sftpLogin(), 'SHA256:'.str_repeat('A', 43), $sink, 1000, new FetchSettings(maxBytes: 1000)));

        expect($failure->reason)->toBe(FetchFailure::HostKeyChanged)
            ->and($fake->told)->toBe(['connect']);
    });
});

describe('the login and the file', function (): void {
    it('asks for a login rather than attempting an empty one', function (): void {
        $fake = new FakeSftp;

        expect(sftpFailure(fn (): FileInfo => sftpInfo($fake, null))->reason)->toBe(FetchFailure::LoginNeeded)
            ->and($fake->told)->toBe(['connect']);
    });

    it('reports a refused login, and asks nothing further', function (): void {
        $fake = new FakeSftp;
        $fake->acceptLogin = false;

        $failure = sftpFailure(fn (): FileInfo => sftpInfo($fake, sftpLogin()));

        expect($failure->reason)->toBe(FetchFailure::LoginRefused)
            ->and($failure->getMessage())->not->toContain('secret')->not->toContain('someone')
            ->and($fake->told)->toBe(['connect', 'login']);
    });

    it('takes the path decoded, and relative to the home after /~/', function (string $url, string $path): void {
        $fake = new FakeSftp;

        sftpInfo($fake, sftpLogin(), url: $url);

        expect($fake->told)->toContain("stat {$path}");
    })->with([
        ['sftp://files.example/out/feed.csv', '/out/feed.csv'],
        ['sftp://files.example/a%20file%23.csv', '/a file#.csv'],
        ['sftp://files.example/~/out/feed.csv', 'out/feed.csv'],
    ]);

    it('says the size and time of the file', function (): void {
        $info = sftpInfo(new FakeSftp, sftpLogin());

        expect($info->size)->toBe(3)->and($info->modifiedAt)->toBe(1_790_000_000);
    });

    it('refuses a folder', function (): void {
        $fake = new FakeSftp;
        $fake->stat = ['size' => 4096, 'modified_at' => 1, 'directory' => true];

        expect(sftpFailure(fn (): FileInfo => sftpInfo($fake, sftpLogin()))->reason)->toBe(FetchFailure::NotFound);
    });

    it('streams to the sink, asking the server for no more than the cap', function (): void {
        $fake = new FakeSftp;
        $fake->bytes = 'sku;title';
        $sink = fopen('php://memory', 'wb+');

        $bytes = (new SftpServer($fake))->download(sftpTarget(), '93.184.215.14', sftpLogin(), null, $sink, 100, new FetchSettings(maxBytes: 100));
        rewind($sink);

        expect($bytes)->toBe(9)
            ->and(stream_get_contents($sink))->toBe('sku;title')
            ->and($fake->told)->toContain('read /out/feed.csv up to 101');
    });

    it('stops at the cap whatever the server sends, keeping nothing past it', function (): void {
        $fake = new FakeSftp;
        $fake->bytes = str_repeat('x', 200);
        $sink = fopen('php://memory', 'wb+');

        $failure = sftpFailure(fn (): int => (new SftpServer($fake))->download(sftpTarget(), '93.184.215.14', sftpLogin(), null, $sink, 100, new FetchSettings(maxBytes: 100)));
        rewind($sink);

        expect($failure->reason)->toBe(FetchFailure::TooLarge)
            ->and(strlen((string) stream_get_contents($sink)))->toBeLessThanOrEqual(100);
    });
});

describe('a real server', function (): void {
    /** @return array{host: string, port: int} */
    $fixture = static function (): array {
        [$host, $port] = explode(':', (string) getenv('FEEDGENIX_TEST_SFTP'));

        return ['host' => $host, 'port' => (int) $port];
    };

    $real = static fn (int $maxBytes = 1_000_000): FileFetcher => new FileFetcher(
        new AddressPin(new DnsResolver, true),
        new SftpServer(new PhpseclibSftpConnector),
        new SftpServer(new PhpseclibSftpConnector),
        new FetchSettings(maxBytes: $maxBytes, connectTimeout: 5, timeout: 30),
    );

    $url = static function (string $path) use ($fixture): string {
        $server = $fixture();

        return "sftp://{$server['host']}:{$server['port']}{$path}";
    };

    $password = static fn (): Credentials => Credentials::password('feedtest', (string) getenv('FEEDGENIX_TEST_SFTP_PASSWORD'));
    $destination = static fn (): string => ingestScratch().'/sftp-'.uniqid('', true);
    $fails = (static fn (callable $call): FetchFailed => sftpFailure($call));

    it('downloads a file byte for byte with a password, and learns the server’s key', function () use ($real, $url, $password, $destination): void {
        $file = $destination();

        $result = $real()->fetch($url('/~/out/feed.csv'), $file, credentials: $password());

        expect(file_get_contents($file))->toBe("sku;title\r\nA-1;H\u{00E4}mmer\r\n")
            ->and($result->hostKey)->toBe(getenv('FEEDGENIX_TEST_SFTP_HOST_KEY'))
            ->and($result->etag)->toMatch('/^\d+:\d+$/');
    });

    it('downloads with a private key', function () use ($real, $url, $destination): void {
        $file = $destination();
        $login = Credentials::key('feedtest', (string) file_get_contents((string) getenv('FEEDGENIX_TEST_SFTP_KEY')));

        $real()->fetch($url('/~/out/feed.csv'), $file, credentials: $login);

        expect(filesize($file))->toBeGreaterThan(0);
    });

    it('goes on with the right key, and refuses another before any login', function () use ($real, $url, $password, $destination, $fails): void {
        $pinned = (string) getenv('FEEDGENIX_TEST_SFTP_HOST_KEY');
        $real()->fetch($url('/~/out/feed.csv'), $destination(), credentials: $password(), hostKey: $pinned);

        $failure = $fails(fn (): FetchResult => $real()->fetch($url('/~/out/feed.csv'), $destination(), credentials: Credentials::password('nobody', 'must-not-arrive'), hostKey: 'SHA256:'.str_repeat('A', 43)));

        expect($failure->reason)->toBe(FetchFailure::HostKeyChanged);
    });

    it('reports a refused login', function () use ($real, $url, $destination, $fails): void {
        $failure = $fails(fn (): FetchResult => $real()->fetch($url('/~/out/feed.csv'), $destination(), credentials: Credentials::password('feedtest', 'wrong')));

        expect($failure->reason)->toBe(FetchFailure::LoginRefused);
    });

    it('reports a missing file and a folder', function (string $path) use ($real, $url, $password, $destination, $fails): void {
        $failure = $fails(fn (): FetchResult => $real()->fetch($url($path), $destination(), credentials: $password()));

        expect($failure->reason)->toBe(FetchFailure::NotFound);
    })->with(['/~/out/missing.csv', '/~/out']);

    it('does not download an unchanged file again', function () use ($real, $url, $password, $destination): void {
        $first = $real()->fetch($url('/~/out/feed.csv'), $destination(), credentials: $password());

        $second = $real()->fetch($url('/~/out/feed.csv'), $destination(), etag: $first->etag, credentials: $password());

        expect($second->notModified)->toBeTrue();
    });

    it('stops at the cap', function () use ($real, $url, $password, $destination, $fails): void {
        $failure = $fails(fn (): FetchResult => $real(1000)->fetch($url('/~/out/large.csv'), $destination(), credentials: $password()));

        expect($failure->reason)->toBe(FetchFailure::TooLarge);
    });

    it('reports a server that does not answer', function () use ($real, $password, $destination, $fails): void {
        $failure = $fails(fn (): FetchResult => $real()->fetch('sftp://127.0.0.1:2223/f.csv', $destination(), credentials: $password()));

        expect($failure->reason)->toBe(FetchFailure::ConnectFailed);
    });
})->skip(fn (): bool => getenv('FEEDGENIX_TEST_SFTP') === false, 'needs the SFTP server CI starts (tests/Support/fixtures/start-sftp.sh)');
