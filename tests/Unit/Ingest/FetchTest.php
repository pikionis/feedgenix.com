<?php

declare(strict_types=1);

use App\Domain\Ingest\Fetch\AddressPolicy;
use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Ingest\Fetch\FetchFailed;
use App\Domain\Ingest\Fetch\FetchFailure;
use App\Domain\Ingest\Fetch\FetchResult;
use App\Domain\Ingest\Fetch\FetchSettings;
use App\Domain\Ingest\Fetch\HttpFetcher;
use App\Domain\Ingest\Fetch\Target;
use App\Domain\Ingest\Fetch\UrlGuard;
use Tests\Support\FakeFetchNetwork;

function fetcher(FakeFetchNetwork $network, array $settings = []): HttpFetcher
{
    return new HttpFetcher($network, $network, new FetchSettings(...array_merge(['maxBytes' => 10_000], $settings)));
}

function fetchFailure(callable $fetch): FetchFailed
{
    try {
        $fetch();
    } catch (FetchFailed $failure) {
        return $failure;
    }

    throw new RuntimeException('expected the fetch to fail');
}

function download(): string
{
    return ingestScratch().'/download-'.uniqid('', true);
}

describe('addresses', function (): void {
    it('allows public addresses', function (string $ip): void {
        expect(AddressPolicy::isPublic($ip))->toBeTrue();
    })->with(['93.184.215.14', '8.8.8.8', '2606:2800:21f:cb07:6820:80da:af6b:8b2c', '2a00:1450:4001:82a::200e']);

    it('refuses every address that is not public unicast', function (string $ip): void {
        expect(AddressPolicy::isPublic($ip))->toBeFalse();
    })->with([
        'loopback' => '127.0.0.1', 'loopback range' => '127.200.1.1', 'this network' => '0.0.0.0',
        'private 10' => '10.1.2.3', 'private 172.16' => '172.20.0.1', 'private 192.168' => '192.168.1.1',
        'carrier NAT' => '100.64.0.1', 'cloud metadata' => '169.254.169.254', 'multicast' => '224.0.0.1',
        'reserved' => '240.0.0.1', 'broadcast' => '255.255.255.255', 'documentation' => '203.0.113.9',
        'IPv6 loopback' => '::1', 'IPv6 unspecified' => '::', 'IPv6 unique local' => 'fd00::1',
        'IPv6 link-local' => 'fe80::1', 'IPv6 multicast' => 'ff02::1', 'IPv6 documentation' => '2001:db8::1',
        'IPv4-mapped loopback' => '::ffff:127.0.0.1', 'IPv4-mapped metadata' => '::ffff:169.254.169.254',
        'IPv4-compatible loopback' => '::127.0.0.1', 'NAT64 of metadata' => '64:ff9b::a9fe:a9fe',
        '6to4 of loopback' => '2002:7f00:1::1', 'Teredo of loopback' => '2001:0:4136:e378:8000:63bf:80ff:fffe',
        'not an address' => 'example.com',
    ]);
});

describe('URLs', function (): void {
    it('accepts ordinary feed addresses', function (string $url, string $host, int $port): void {
        $target = UrlGuard::check($url);

        expect([$target->host, $target->port])->toBe([$host, $port]);
    })->with([
        ['https://shop.example.com/feed.xml?token=abc', 'shop.example.com', 443],
        ['http://Shop.Example.com./export', 'shop.example.com', 80],
        ['https://feeds.example.com:8443/p.csv', 'feeds.example.com', 8443],
        ['https://[2606:2800:21f:cb07:6820:80da:af6b:8b2c]/f', '2606:2800:21f:cb07:6820:80da:af6b:8b2c', 443],
    ]);

    it('refuses what is not an http(s) feed address', function (string $url): void {
        expect(fetchFailure(fn (): Target => UrlGuard::check($url))->reason)->toBe(FetchFailure::InvalidUrl);
    })->with([
        'file' => 'file:///etc/passwd', 'ftp' => 'ftp://example.com/f.csv', 'gopher' => 'gopher://example.com/',
        'no scheme' => 'example.com/feed.csv', 'credentials' => 'https://user:pass@example.com/f', 'mail port' => 'http://example.com:25/',
        'ssh port' => 'http://example.com:22/', 'decimal IP' => 'http://2130706433/', 'hex IP' => 'http://0x7f000001/',
        'short IP' => 'http://127.1/', 'octal IP' => 'http://0177.0.0.1/', 'spaces' => 'https://exa mple.com/',
        'empty' => '', 'bad host' => 'https://-bad-.example/',
    ]);
});

describe('fetching', function (): void {
    it('downloads a feed to disk, pinned to the address it checked', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])
            ->respond('https://shop.example/feed.csv', 200, "sku\nA-1\n", ['Content-Length' => '8', 'ETag' => '"v1"', 'Content-Type' => 'text/csv']);
        $destination = download();

        $result = fetcher($network)->fetch('https://shop.example/feed.csv', $destination);

        expect(file_get_contents($destination))->toBe("sku\nA-1\n")
            ->and($result->etag)->toBe('"v1"')
            ->and($result->bytes)->toBe(8)
            ->and($network->requests[0]['address'])->toBe('93.184.215.14')
            ->and($network->requests[0]['headers']['Accept-Encoding'])->toBe('gzip, identity');
    });

    it('never connects when a host resolves to a private or metadata address', function (array $answer): void {
        $network = (new FakeFetchNetwork)->dns('evil.example', $answer);

        expect(fetchFailure(fn (): FetchResult => fetcher($network)->fetch('https://evil.example/', download()))->reason)->toBe(FetchFailure::BlockedAddress)
            ->and($network->requests)->toBe([]);
    })->with([
        'loopback' => [['127.0.0.1']], 'metadata' => [['169.254.169.254']], 'private' => [['10.0.0.5']],
        'IPv6 loopback' => [['::1']], 'mapped IPv6' => [['::ffff:192.168.0.1']],
        'one public, one private answer' => [['93.184.215.14', '10.0.0.5']],
    ]);

    it('refuses IP-literal URLs into private networks', function (): void {
        $network = new FakeFetchNetwork;

        expect(fetchFailure(fn (): FetchResult => fetcher($network)->fetch('http://169.254.169.254/latest/meta-data/', download()))->reason)->toBe(FetchFailure::BlockedAddress)
            ->and(fetchFailure(fn (): FetchResult => fetcher($network)->fetch('http://[::1]:8080/', download()))->reason)->toBe(FetchFailure::BlockedAddress)
            ->and($network->requests)->toBe([]);
    });

    it('is not fooled by DNS rebinding: the checked address is the one used', function (): void {
        // First answer public, any later answer internal: the connection must stay on the first.
        $network = (new FakeFetchNetwork)->dns('rebind.example', ['93.184.215.14'], ['127.0.0.1'])
            ->respond('https://rebind.example/feed.csv', 200, 'sku');

        fetcher($network)->fetch('https://rebind.example/feed.csv', download());

        expect($network->lookups)->toBe(['rebind.example'])
            ->and($network->requests[0]['address'])->toBe('93.184.215.14');
    });

    it('checks every redirect hop again: a redirect into the metadata service is refused', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])
            ->respond('https://shop.example/feed', 302, '', ['Location' => 'http://169.254.169.254/latest/meta-data/iam']);

        expect(fetchFailure(fn (): FetchResult => fetcher($network)->fetch('https://shop.example/feed', download()))->reason)->toBe(FetchFailure::BlockedAddress)
            ->and($network->requests)->toHaveCount(1);
    });

    it('re-resolves a redirect to the same host, and refuses it when the answer turned internal', function (): void {
        $network = (new FakeFetchNetwork)->dns('rebind.example', ['93.184.215.14'], ['10.0.0.1'])
            ->respond('https://rebind.example/a', 301, '', ['Location' => '/b']);

        expect(fetchFailure(fn (): FetchResult => fetcher($network)->fetch('https://rebind.example/a', download()))->reason)->toBe(FetchFailure::BlockedAddress);
    });

    it('follows relative and protocol-relative redirects to the feed', function (): void {
        $network = (new FakeFetchNetwork)
            ->dns('shop.example', ['93.184.215.14'])->dns('cdn.example', ['93.184.215.15'])
            ->respond('https://shop.example/exports/latest', 302, '', ['Location' => 'v2/feed.csv'])
            ->respond('https://shop.example/exports/v2/feed.csv', 301, '', ['Location' => '//cdn.example/f.csv'])
            ->respond('https://cdn.example/f.csv', 200, 'sku');

        $result = fetcher($network)->fetch('https://shop.example/exports/latest', download());

        expect($result->finalUrl)->toBe('https://cdn.example/f.csv')
            ->and(array_column($network->requests, 'address'))->toBe(['93.184.215.14', '93.184.215.14', '93.184.215.15']);
    });

    it('stops after the redirect limit', function (): void {
        $network = (new FakeFetchNetwork)->dns('loop.example', ['93.184.215.14'])
            ->respond('https://loop.example/a', 302, '', ['Location' => '/a']);

        expect(fetchFailure(fn (): FetchResult => fetcher($network, ['maxRedirects' => 3])->fetch('https://loop.example/a', download()))->reason)
            ->toBe(FetchFailure::TooManyRedirects)
            ->and($network->requests)->toHaveCount(4);
    });

    it('treats a Content-Length the body does not reach as a cut-off download', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])
            ->respond('https://shop.example/f.csv', 200, 'sku', ['Content-Length' => '5000']);

        expect(fetchFailure(fn (): FetchResult => fetcher($network)->fetch('https://shop.example/f.csv', download()))->reason)->toBe(FetchFailure::Truncated);
    });

    it('passes on the size cap to the transport', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])
            ->respond('https://shop.example/f.csv', 200, str_repeat('x', 200));

        expect(fetchFailure(fn (): FetchResult => fetcher($network, ['maxBytes' => 100])->fetch('https://shop.example/f.csv', download()))->reason)->toBe(FetchFailure::TooLarge);
    });

    it('asks conditionally and reports an unchanged feed without a download', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/f.csv', 304);

        $result = fetcher($network)->fetch('https://shop.example/f.csv', download(), etag: '"v1"', lastModified: 'Tue, 29 Sep 2026 10:00:00 GMT');

        expect($result->notModified)->toBeTrue()
            ->and($network->requests[0]['headers'])->toMatchArray(['If-None-Match' => '"v1"', 'If-Modified-Since' => 'Tue, 29 Sep 2026 10:00:00 GMT']);
    });

    it('reads 403 and 429 as "try later", with the server’s Retry-After', function (int $status, array $headers, ?int $retry): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/f.csv', $status, '', $headers);
        $failure = fetchFailure(fn (): FetchResult => fetcher($network)->fetch('https://shop.example/f.csv', download()));

        expect($failure->reason)->toBe(FetchFailure::RateLimited)
            ->and($failure->status)->toBe($status)
            ->and($failure->retryAfterSeconds)->toBe($retry);
    })->with([
        [429, ['Retry-After' => '120'], 120],
        [429, ['Retry-After' => '999999'], 86_400],
        [403, [], null],
    ]);

    it('explains other HTTP failures', function (int $status, string $says): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/f.csv', $status);
        $failure = fetchFailure(fn (): FetchResult => fetcher($network)->fetch('https://shop.example/f.csv', download()));

        expect($failure->reason)->toBe(FetchFailure::HttpStatus)
            ->and($failure->getMessage())->toContain($says);
    })->with([[404, 'Nothing was found'], [410, 'Nothing was found'], [500, 'HTTP 500']]);

    it('asks for a login on a 401 when the feed has none', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/f.csv', 401);
        $failure = fetchFailure(fn (): FetchResult => fetcher($network)->fetch('https://shop.example/f.csv', download()));

        expect($failure->reason)->toBe(FetchFailure::LoginNeeded)
            ->and($failure->status)->toBe(401);
    });

    it('sends no login when the feed has none', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/f.csv', 200, 'sku');

        fetcher($network)->fetch('https://shop.example/f.csv', download());

        expect($network->requests[0]['headers'])->not->toHaveKey('Authorization');
    });

    it('reports a host that does not resolve', function (): void {
        expect(fetchFailure(fn (): FetchResult => fetcher(new FakeFetchNetwork)->fetch('https://nowhere.example/f', download()))->reason)->toBe(FetchFailure::DnsFailed);
    });

    it('lets development reach a local server only when the flag is on, and never in production', function (): void {
        $network = (new FakeFetchNetwork)->dns('localhost', ['127.0.0.1'])->respond('http://localhost:4173/feed.xml', 200, '<p/>');

        fetcher($network, ['allowPrivateNetworks' => true])->fetch('http://localhost:4173/feed.xml', download());
        expect($network->requests)->toHaveCount(1);

        config(['ingest.fetch.allow_private_networks' => true]);
        app()->detectEnvironment(fn (): string => 'production');

        expect(fn (): FetchSettings => FetchSettings::fromConfig())->toThrow(LogicException::class);
    });
});

describe('logins', function (): void {
    it('sends a password as Basic authentication, in UTF-8', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/f.csv', 200, 'sku');

        fetcher($network)->fetch('https://shop.example/f.csv', download(), credentials: Credentials::password('müller', 'pä:ss'));

        expect($network->requests[0]['headers']['Authorization'])->toBe('Basic '.base64_encode('müller:pä:ss'));
    });

    it('keeps the login on a redirect within the origin', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])
            ->respond('https://shop.example/a', 302, '', ['Location' => '/b'])
            ->respond('https://shop.example/b', 200, 'sku');

        fetcher($network)->fetch('https://shop.example/a', download(), credentials: Credentials::header('X-Key', 'k'));

        expect(array_column(array_column($network->requests, 'headers'), 'X-Key'))->toBe(['k', 'k']);
    });

    it('never carries a login across a redirect to another origin', function (string $location, string $url): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])
            ->dns('cdn.example', ['93.184.215.15'])->dns('sub.shop.example', ['93.184.215.16'])
            ->respond('https://shop.example/a', 302, '', ['Location' => $location])
            ->respond($url, 200, 'sku');

        fetcher($network)->fetch('https://shop.example/a', download(), credentials: Credentials::password('u', 'p'));

        expect($network->requests)->toHaveCount(2)
            ->and($network->requests[0]['headers'])->toHaveKey('Authorization')
            ->and($network->requests[1]['headers'])->not->toHaveKey('Authorization');
    })->with([
        'another host' => ['https://cdn.example/f.csv', 'https://cdn.example/f.csv'],
        'a subdomain' => ['https://sub.shop.example/f.csv', 'https://sub.shop.example/f.csv'],
        'another port' => ['https://shop.example:8443/f.csv', 'https://shop.example:8443/f.csv'],
        'a downgrade to http' => ['http://shop.example/f.csv', 'http://shop.example/f.csv'],
        'a protocol-relative host' => ['//cdn.example/f.csv', 'https://cdn.example/f.csv'],
    ]);

    it('sends a header login no further than the origin either', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])->dns('cdn.example', ['93.184.215.15'])
            ->respond('https://shop.example/a', 302, '', ['Location' => 'https://cdn.example/f.csv'])
            ->respond('https://cdn.example/f.csv', 200, 'sku');

        fetcher($network)->fetch('https://shop.example/a', download(), credentials: Credentials::header('X-Key', 'k'));

        expect($network->requests[1]['headers'])->not->toHaveKey('X-Key');
    });

    it('treats a 401 on the way past the origin as a plain failure, not as a wrong login', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])->dns('cdn.example', ['93.184.215.15'])
            ->respond('https://shop.example/a', 302, '', ['Location' => 'https://cdn.example/f.csv'])
            ->respond('https://cdn.example/f.csv', 401);

        $failure = fetchFailure(fn (): FetchResult => fetcher($network)->fetch('https://shop.example/a', download(), credentials: Credentials::password('u', 'p')));

        expect($failure->reason)->toBe(FetchFailure::HttpStatus)
            ->and($failure->getMessage())->toContain('another server');
    });

    it('fails closed when a login meets plain http, whatever put it there', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])->respond('http://shop.example/f.csv', 200, 'sku');

        $failure = fetchFailure(fn (): FetchResult => fetcher($network)->fetch('http://shop.example/f.csv', download(), credentials: Credentials::password('u', 'p')));

        expect($failure->reason)->toBe(FetchFailure::InvalidUrl)
            ->and($failure->getMessage())->toContain('https')
            ->and($network->requests)->toBe([]);
    });

    it('calls a 401 with a login a refused login', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/f.csv', 401);

        $failure = fetchFailure(fn (): FetchResult => fetcher($network)->fetch('https://shop.example/f.csv', download(), credentials: Credentials::password('u', 'p')));

        expect($failure->reason)->toBe(FetchFailure::LoginRefused)
            ->and($failure->status)->toBe(401)
            ->and($failure->getMessage())->toContain('refused the login');
    });

    it('names the login as a possible cause of a 403', function (): void {
        $network = (new FakeFetchNetwork)->dns('shop.example', ['93.184.215.14'])->respond('https://shop.example/f.csv', 403);

        $failure = fetchFailure(fn (): FetchResult => fetcher($network)->fetch('https://shop.example/f.csv', download(), credentials: Credentials::password('u', 'p')));

        expect($failure->reason)->toBe(FetchFailure::RateLimited)
            ->and($failure->getMessage())->toContain('login');
    });
});

describe('the login itself', function (): void {
    it('refuses what could break out of a header', function (callable $make): void {
        expect($make)->toThrow(InvalidArgumentException::class);
    })->with([
        'line break in a username' => [fn (): Credentials => Credentials::password("a\nb", 'p')],
        'line break ending a username' => [fn (): Credentials => Credentials::password("a\n", 'p')],
        'colon in a username' => [fn (): Credentials => Credentials::password('a:b', 'p')],
        'empty username' => [fn (): Credentials => Credentials::password('', 'p')],
        'line break ending a password' => [fn (): Credentials => Credentials::password('a', "p\n")],
        'invalid UTF-8 in a password' => [fn (): Credentials => Credentials::password('a', "p\xFF")],
        'empty password' => [fn (): Credentials => Credentials::password('a', '')],
        'injection in a header value' => [fn (): Credentials => Credentials::header('X-Key', "v\r\nHost: x")],
        'line break ending a header value' => [fn (): Credentials => Credentials::header('X-Key', "v\n")],
        'a bad header name' => [fn (): Credentials => Credentials::header('X Key', 'v')],
        'a line break ending a header name' => [fn (): Credentials => Credentials::header("X-Key\n", 'v')],
        'a reserved header' => [fn (): Credentials => Credentials::header('HOST', 'v')],
        'a conditional header' => [fn (): Credentials => Credentials::header('if-range', 'v')],
        'a proxy header' => [fn (): Credentials => Credentials::header('proxy-authorization', 'v')],
        'a stored login of an unknown kind' => [fn (): Credentials => Credentials::fromArray(['type' => 'magic'])],
        'a stored login cut short' => [fn (): Credentials => Credentials::fromArray(['type' => 'password', 'username' => 'a'])],
    ]);

    it('comes back from storage the same', function (Credentials $login): void {
        $again = Credentials::fromArray($login->toArray());

        expect($again->type)->toBe($login->type)->and($again->httpHeaders())->toBe($login->httpHeaders());
    })->with([
        'password' => [fn (): Credentials => Credentials::password('a', 'b')],
        'header' => [fn (): Credentials => Credentials::header('X-Key', 'v')],
    ]);

    it('hides its secret from dumps and stack traces', function (): void {
        $login = Credentials::password('someone', 'top-secret');

        expect(print_r($login, true))->not->toContain('top-secret')->not->toContain('someone');

        // On, this setting strips every argument from traces, which would hide a missing guard.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            Credentials::password('someone', "top-secret\n");
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $refused) {
            $frames = print_r($refused->getTrace(), true);

            expect($refused->getMessage())->not->toContain('top-secret')
                ->and($refused->getTraceAsString())->not->toContain('top-secret')
                ->and($frames)->toContain('SensitiveParameterValue')->not->toContain('top-secret')->not->toContain('someone');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    });
});
