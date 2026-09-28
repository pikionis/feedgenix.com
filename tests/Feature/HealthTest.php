<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('reports ok when the database answers', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'database' => 'ok']);
});

it('reports degraded with 503 and leaks no error detail when the database is down', function (): void {
    DB::shouldReceive('select')->once()->andThrow(new RuntimeException('SQLSTATE[08006] could not connect to 10.0.0.5:5432'));

    $response = $this->getJson('/api/health');

    $response->assertStatus(503)->assertExactJson(['status' => 'degraded', 'database' => 'down']);
    expect($response->getContent())->not->toContain('10.0.0.5')->not->toContain('SQLSTATE');
});

it('starts no session and sets no cookie, so uptime pings write nothing', function (): void {
    $response = $this->getJson('/api/health')->assertOk();

    expect($response->headers->getCookies())->toBe([]);
});
