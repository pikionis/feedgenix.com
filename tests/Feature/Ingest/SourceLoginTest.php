<?php

declare(strict_types=1);

use App\Domain\Feeds\Models\FeedSource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeFetchNetwork;

beforeEach(function (): void {
    Storage::fake('local');
});

const LOGIN_CSV = "sku;title\nA-1;Hammer\n";
const LOGIN_URL = 'https://shop.example/private/feed.csv';
const PASSWORD = 'S3cret-pass-phrase';

/** @return array{0: Tenant, 1: User, 2: FakeFetchNetwork} */
function shopWithLogin(): array
{
    $network = network()->dns('shop.example', ['93.184.215.14'])->dns('evil.example', ['93.184.215.66'])
        ->respond(LOGIN_URL, 200, LOGIN_CSV, ['ETag' => '"v1"']);
    $tenant = Tenant::factory()->onPlan('pro')->create();

    return [$tenant, owner($tenant), $network];
}

/** @return array{type: string, username: string, password: string} */
function passwordLogin(string $username = 'shop-user', string $password = PASSWORD): array
{
    return ['type' => 'password', 'username' => $username, 'password' => $password];
}

function feedWithLogin(User $owner): int
{
    return (int) createFeed($owner, ['name' => 'Main', 'url' => LOGIN_URL, 'auth' => passwordLogin()])
        ->assertCreated()->json('feed.id');
}

function storedSource(Tenant $tenant): FeedSource
{
    return tenancy()->run($tenant->id, fn (): FeedSource => FeedSource::query()->firstOrFail());
}

it('fetches with the login, shows only its kind, and never the secret', function (): void {
    [, $owner, $network] = shopWithLogin();

    $response = createFeed($owner, ['name' => 'Main', 'url' => LOGIN_URL, 'auth' => passwordLogin()])
        ->assertCreated()
        ->assertJsonPath('feed.auth', 'password')
        ->assertJsonPath('feed.latest_import.status', 'profiled');
    $id = (int) $response->json('feed.id');

    expect($network->requests[0]['headers']['Authorization'])->toBe('Basic '.base64_encode('shop-user:'.PASSWORD));

    $bodies = [
        (string) $response->getContent(),
        (string) $this->actingAs($owner)->getJson("/api/v1/feeds/{$id}")->assertOk()->getContent(),
        (string) $this->actingAs($owner)->getJson('/api/v1/feeds')->assertOk()->getContent(),
    ];
    foreach ($bodies as $body) {
        expect($body)->not->toContain(PASSWORD)->not->toContain('shop-user')->not->toContain(base64_encode('shop-user:'.PASSWORD));
    }
});

it('fetches with a header login', function (): void {
    [, $owner, $network] = shopWithLogin();

    createFeed($owner, ['name' => 'Main', 'url' => LOGIN_URL, 'auth' => ['type' => 'header', 'name' => 'X-Api-Key', 'value' => 'key-123']])
        ->assertCreated()
        ->assertJsonPath('feed.auth', 'header');

    expect($network->requests[0]['headers']['X-Api-Key'])->toBe('key-123')
        ->and($network->requests[0]['headers'])->not->toHaveKey('Authorization');
});

it('stores the login encrypted and leaves its kind readable', function (): void {
    [$tenant, $owner] = shopWithLogin();
    feedWithLogin($owner);

    $raw = DB::table('feed_sources')->first(['credentials', 'auth_type']);

    expect($raw->auth_type)->toBe('password')
        ->and((string) $raw->credentials)->not->toContain(PASSWORD)->not->toContain('shop-user')
        ->and(storedSource($tenant)->login()?->httpHeaders())->toBe(['Authorization' => 'Basic '.base64_encode('shop-user:'.PASSWORD)]);
});

it('never lets the model serialise the URL or the login', function (): void {
    [$tenant, $owner] = shopWithLogin();
    feedWithLogin($owner);

    $json = storedSource($tenant)->toJson();

    expect($json)->not->toContain(PASSWORD)->not->toContain('credentials')->not->toContain('private/feed.csv');
});

it('D16: saving a feed without the login keeps the stored one', function (array $body): void {
    [$tenant, $owner, $network] = shopWithLogin();
    $id = feedWithLogin($owner);

    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", $body)->assertOk()->assertJsonPath('feed.auth', 'password');

    expect(storedSource($tenant)->auth_type)->toBe('password');
    $this->actingAs($owner)->postJson("/api/v1/feeds/{$id}/fetch")->assertStatus(202);
    expect(end($network->requests)['headers']['Authorization'] ?? null)->toBe('Basic '.base64_encode('shop-user:'.PASSWORD));
})->with([
    'a new name' => [['name' => 'Renamed']],
    'new reading options' => [['parse_options' => ['delimiter' => ';']]],
    'the same address' => [['url' => LOGIN_URL]],
]);

it('keeps the login when the address changes on the same origin, forgetting the old validators', function (): void {
    [$tenant, $owner, $network] = shopWithLogin();
    $id = feedWithLogin($owner);
    $network->respond('https://shop.example/private/other.csv', 200, LOGIN_CSV);

    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", ['url' => 'https://shop.example/private/other.csv'])
        ->assertOk()->assertJsonPath('feed.auth', 'password');

    expect(storedSource($tenant)->etag)->toBeNull();
    $this->actingAs($owner)->postJson("/api/v1/feeds/{$id}/fetch")->assertStatus(202);
    expect(end($network->requests)['headers'])->toHaveKey('Authorization');
});

it('never lets a stored login follow the address to another origin', function (string $url): void {
    [$tenant, $owner, $network] = shopWithLogin();
    $id = feedWithLogin($owner);
    $network->dns('evil.shop.example', ['93.184.215.67'])->respond($url, 200, LOGIN_CSV);

    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", ['url' => $url])
        ->assertOk()->assertJsonPath('feed.auth', null);

    $source = storedSource($tenant);
    expect($source->auth_type)->toBeNull()->and($source->login())->toBeNull()
        ->and(DB::table('feed_sources')->value('credentials'))->toBeNull();

    $this->actingAs($owner)->postJson("/api/v1/feeds/{$id}/fetch")->assertStatus(202);
    expect(end($network->requests)['url'])->toBe($url)
        ->and(end($network->requests)['headers'])->not->toHaveKey('Authorization');
})->with([
    'another host' => ['https://evil.example/collect'],
    'a subdomain' => ['https://evil.shop.example/feed.csv'],
    'another port' => ['https://shop.example:8443/feed.csv'],
    'plain http' => ['http://shop.example/private/feed.csv'],
]);

it('takes a new address and a new login together', function (): void {
    [$tenant, $owner, $network] = shopWithLogin();
    $id = feedWithLogin($owner);
    $network->respond('https://evil.example/feed.csv', 200, LOGIN_CSV);

    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", ['url' => 'https://evil.example/feed.csv', 'auth' => passwordLogin('other', 'other-pass')])
        ->assertOk()->assertJsonPath('feed.auth', 'password');

    expect(storedSource($tenant)->login()?->httpHeaders()['Authorization'])->toBe('Basic '.base64_encode('other:other-pass'));
});

it('removes a login only when asked, forgetting the old validators', function (): void {
    [$tenant, $owner, $network] = shopWithLogin();
    $id = feedWithLogin($owner);
    expect(storedSource($tenant)->etag)->toBe('"v1"');

    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", ['auth' => null])->assertOk()->assertJsonPath('feed.auth', null);

    expect(storedSource($tenant)->etag)->toBeNull();
    $this->actingAs($owner)->postJson("/api/v1/feeds/{$id}/fetch")->assertStatus(202);
    expect(end($network->requests)['headers'])->not->toHaveKey('Authorization')->not->toHaveKey('If-None-Match');
});

it('asks for the whole file again with a changed login: the old ETag belonged to the old one', function (): void {
    [$tenant, $owner, $network] = shopWithLogin();
    $id = feedWithLogin($owner);

    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", ['auth' => passwordLogin('new-user', 'new-pass')])->assertOk();
    $this->actingAs($owner)->postJson("/api/v1/feeds/{$id}/fetch")->assertStatus(202);

    expect(end($network->requests)['headers'])->not->toHaveKey('If-None-Match')
        ->and(end($network->requests)['headers']['Authorization'])->toBe('Basic '.base64_encode('new-user:new-pass'));
});

it('never sends a login over plain http: refused when created, nothing is made', function (): void {
    [$tenant, $owner, $network] = shopWithLogin();

    createFeed($owner, ['name' => 'Main', 'url' => 'http://shop.example/feed.csv', 'auth' => passwordLogin()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['auth' => 'https']);

    expect(tenancy()->run($tenant->id, fn (): int => FeedSource::query()->count()))->toBe(0)
        ->and($network->requests)->toBe([]);
});

it('never sends a login over plain http: refused on a stored http address', function (): void {
    [$tenant, $owner, $network] = shopWithLogin();
    $network->respond('http://shop.example/feed.csv', 200, LOGIN_CSV);
    $id = (int) createFeed($owner, ['name' => 'Main', 'url' => 'http://shop.example/feed.csv'])->assertCreated()->json('feed.id');

    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", ['auth' => passwordLogin()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['auth' => 'https']);

    expect(storedSource($tenant)->auth_type)->toBeNull();
});

it('changes nothing when a new http address comes with a login', function (): void {
    [$tenant, $owner] = shopWithLogin();
    $id = feedWithLogin($owner);

    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", ['name' => 'Renamed', 'url' => 'http://shop.example/feed.csv', 'auth' => passwordLogin('a', 'b')])
        ->assertUnprocessable();

    $source = storedSource($tenant);
    expect($source->url)->toBe(LOGIN_URL)
        ->and($source->login()?->httpHeaders()['Authorization'])->toBe('Basic '.base64_encode('shop-user:'.PASSWORD))
        ->and($this->actingAs($owner)->getJson("/api/v1/feeds/{$id}")->json('feed.name'))->toBe('Main');
});

it('refuses a login on a feed fed by uploads', function (): void {
    [, $owner] = shopWithLogin();

    createFeed($owner, ['name' => 'Uploads', 'auth' => passwordLogin()])->assertUnprocessable()->assertJsonValidationErrors('url');

    $id = (int) createFeed($owner, ['name' => 'Uploads'])->assertCreated()->json('feed.id');
    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", ['auth' => passwordLogin()])
        ->assertUnprocessable()->assertJsonValidationErrors('auth');
});

it('refuses logins that could not be sent safely, and keeps the stored one', function (array $auth, string $field): void {
    [$tenant, $owner] = shopWithLogin();
    $id = feedWithLogin($owner);

    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", ['auth' => $auth])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(storedSource($tenant)->login()?->httpHeaders()['Authorization'])->toBe('Basic '.base64_encode('shop-user:'.PASSWORD));
})->with([
    'an empty object must not clear it' => [[], 'auth'],
    'an unknown kind' => [['type' => 'oauth'], 'auth.type'],
    'no kind' => [['username' => 'a', 'password' => 'b'], 'auth'],
    'an unknown key' => [['type' => 'password', 'username' => 'a', 'password' => 'b', 'surprise' => 'c'], 'auth'],
    'an empty form must not clear it' => [['type' => 'password', 'username' => '', 'password' => ''], 'auth.username'],
    'no password' => [['type' => 'password', 'username' => 'a'], 'auth.password'],
    'a colon in the username' => [['type' => 'password', 'username' => 'a:b', 'password' => 'c'], 'auth.username'],
    'a line break in the username' => [['type' => 'password', 'username' => "a\r\nX-Evil: 1", 'password' => 'c'], 'auth.username'],
    'a line break ending the password' => [['type' => 'password', 'username' => 'a', 'password' => "c\n"], 'auth.password'],
    'a NUL in the password' => [['type' => 'password', 'username' => 'a', 'password' => "c\0d"], 'auth.password'],
    'a very long password' => [['type' => 'password', 'username' => 'a', 'password' => str_repeat('x', 1025)], 'auth.password'],
    'header injection in the value' => [['type' => 'header', 'name' => 'X-Key', 'value' => "v\r\nHost: evil.example"], 'auth.value'],
    'a line break ending the value' => [['type' => 'header', 'name' => 'X-Key', 'value' => "v\n"], 'auth.value'],
    'a space in the header name' => [['type' => 'header', 'name' => 'X Key', 'value' => 'v'], 'auth.name'],
    'a colon in the header name' => [['type' => 'header', 'name' => 'X-Key:', 'value' => 'v'], 'auth.name'],
    'no header value' => [['type' => 'header', 'name' => 'X-Key'], 'auth.value'],
    'Host' => [['type' => 'header', 'name' => 'hOsT', 'value' => 'evil.example'], 'auth.name'],
    'Transfer-Encoding' => [['type' => 'header', 'name' => 'Transfer-Encoding', 'value' => 'chunked'], 'auth.name'],
    'Accept-Encoding' => [['type' => 'header', 'name' => 'Accept-Encoding', 'value' => 'br'], 'auth.name'],
    'If-None-Match' => [['type' => 'header', 'name' => 'If-None-Match', 'value' => '*'], 'auth.name'],
    'a proxy header' => [['type' => 'header', 'name' => 'Proxy-Authorization', 'value' => 'x'], 'auth.name'],
]);

it('sends a password and a username exactly as typed, spaces included', function (): void {
    [, $owner, $network] = shopWithLogin();

    createFeed($owner, ['name' => 'Main', 'url' => LOGIN_URL, 'auth' => passwordLogin(' spaced user ', '  pass  phrase  ')])->assertCreated();

    expect($network->requests[0]['headers']['Authorization'])->toBe('Basic '.base64_encode(' spaced user :  pass  phrase  '));
});

it('sends a header value exactly as typed, spaces included', function (): void {
    [, $owner, $network] = shopWithLogin();

    createFeed($owner, ['name' => 'Main', 'url' => LOGIN_URL, 'auth' => ['type' => 'header', 'name' => 'X-Key', 'value' => ' v ']])->assertCreated();

    expect($network->requests[0]['headers']['X-Key'])->toBe(' v ');
});

it('tells a refused login from a missing one, without repeating the secret', function (): void {
    [, $owner, $network] = shopWithLogin();
    $network->dns('locked.example', ['93.184.215.14'])->respond('https://locked.example/feed.csv', 401);

    $refused = createFeed($owner, ['name' => 'With', 'url' => 'https://locked.example/feed.csv', 'auth' => passwordLogin()])
        ->assertCreated()
        ->assertJsonPath('feed.latest_import.status', 'failed')
        ->assertJsonPath('feed.latest_import.failure.reason', 'login_refused');
    expect((string) $refused->getContent())->not->toContain(PASSWORD)->not->toContain('shop-user');

    createFeed($owner, ['name' => 'Without', 'url' => 'https://locked.example/feed.csv'])
        ->assertCreated()
        ->assertJsonPath('feed.latest_import.failure.reason', 'login_needed');
});

it('lets only owners and admins change a login, and only in their workspace', function (): void {
    [$tenant, $owner] = shopWithLogin();
    $id = feedWithLogin($owner);

    $this->actingAs(memberOf($tenant, 'member'))->patchJson("/api/v1/feeds/{$id}", ['auth' => null])->assertForbidden();
    $this->actingAs(owner(Tenant::factory()->onPlan('pro')->create()))->patchJson("/api/v1/feeds/{$id}", ['auth' => null])->assertNotFound();
    expect(storedSource($tenant)->auth_type)->toBe('password');

    $this->actingAs(memberOf($tenant, 'admin'))->patchJson("/api/v1/feeds/{$id}", ['auth' => null])->assertOk();
    expect(storedSource($tenant)->auth_type)->toBeNull();
});

it('reads a feed from an FTP server with a login', function (): void {
    [$tenant, $owner, $network] = shopWithLogin();
    $network->dns('files.example', ['93.184.215.77'])->file('ftp://files.example/out/feed.csv', LOGIN_CSV);

    $response = createFeed($owner, ['name' => 'FTP', 'url' => 'ftp://files.example/out/feed.csv', 'auth' => passwordLogin()])
        ->assertCreated()
        ->assertJsonPath('feed.source', 'url')
        ->assertJsonPath('feed.host', 'files.example')
        ->assertJsonPath('feed.auth', 'password')
        ->assertJsonPath('feed.latest_import.status', 'profiled')
        ->assertJsonPath('feed.latest_import.row_count', 1);

    expect((string) $response->getContent())->not->toContain('out/feed.csv')->not->toContain(PASSWORD)
        ->and($network->fileRequests[0]['login']?->usernameAndPassword())->toBe(['shop-user', PASSWORD])
        ->and(storedSource($tenant)->etag)->toBe(strlen(LOGIN_CSV).':1790000000');
});

it('does not read an unchanged FTP file again', function (): void {
    [, $owner, $network] = shopWithLogin();
    $network->dns('files.example', ['93.184.215.77'])->file('ftp://files.example/feed.csv', LOGIN_CSV);
    $id = (int) createFeed($owner, ['name' => 'FTP', 'url' => 'ftp://files.example/feed.csv'])->assertCreated()->json('feed.id');

    $this->actingAs($owner)->postJson("/api/v1/feeds/{$id}/fetch")
        ->assertStatus(202)
        ->assertJsonPath('import.status', 'unchanged');

    expect(array_column($network->fileRequests, 'call'))->toBe(['info', 'download', 'info']);
});

it('refuses a header login on an FTP address', function (): void {
    [, $owner, $network] = shopWithLogin();

    createFeed($owner, ['name' => 'FTP', 'url' => 'ftp://files.example/feed.csv', 'auth' => ['type' => 'header', 'name' => 'X-Key', 'value' => 'v']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['auth' => 'https://']);

    expect($network->fileRequests)->toBe([]);
});

it('never lets a stored login move between web and FTP, or from FTP to plain FTP', function (string $from, string $to): void {
    [$tenant, $owner, $network] = shopWithLogin();
    $network->dns('files.example', ['93.184.215.77'])->file($from, LOGIN_CSV)->file($to, LOGIN_CSV)->respond($from, 200, LOGIN_CSV);
    $id = (int) createFeed($owner, ['name' => 'Moves', 'url' => $from, 'auth' => passwordLogin()])->assertCreated()->json('feed.id');

    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", ['url' => $to])->assertOk()->assertJsonPath('feed.auth', null);

    expect(storedSource($tenant)->auth_type)->toBeNull();
})->with([
    'https to ftp on one host' => ['https://shop.example/feed.csv', 'ftp://shop.example/feed.csv'],
    'ftps down to ftp' => ['ftps://files.example/feed.csv', 'ftp://files.example/feed.csv'],
    'one FTP server to another' => ['ftp://files.example/feed.csv', 'ftp://shop.example/feed.csv'],
    'another FTP port' => ['ftp://files.example/feed.csv', 'ftp://files.example:2121/feed.csv'],
]);

it('keeps the login when the file changes on the same FTP server', function (): void {
    [$tenant, $owner, $network] = shopWithLogin();
    $network->dns('files.example', ['93.184.215.77'])->file('ftp://files.example/a.csv', LOGIN_CSV);
    $id = (int) createFeed($owner, ['name' => 'FTP', 'url' => 'ftp://files.example/a.csv', 'auth' => passwordLogin()])->assertCreated()->json('feed.id');

    $this->actingAs($owner)->patchJson("/api/v1/feeds/{$id}", ['url' => 'ftp://files.example/b.csv'])->assertOk()->assertJsonPath('feed.auth', 'password');

    expect(storedSource($tenant)->etag)->toBeNull();
});

it('has a database rule: no login without its kind, no kind without its login', function (): void {
    [$tenant, $owner] = shopWithLogin();
    feedWithLogin($owner);

    tenancy()->run($tenant->id, function (): void {
        expect(fn () => DB::transaction(fn () => DB::table('feed_sources')->update(['credentials' => null])))->toThrow(Exception::class);
        expect(fn () => DB::transaction(fn () => DB::table('feed_sources')->update(['auth_type' => null])))->toThrow(Exception::class);
        expect(fn () => DB::transaction(fn () => DB::table('feed_sources')->update(['auth_type' => 'magic'])))->toThrow(Exception::class);
    });
});
