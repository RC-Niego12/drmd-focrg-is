<?php

use App\Models\User;
use App\Http\Middleware\HandleInertiaRequests;
use App\Services\RealtimePublisher;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'realtime.enabled' => true,
        'realtime.public_url' => 'http://localhost:6002',
        'realtime.internal_url' => 'http://127.0.0.1:6002',
        'realtime.secret' => 'test-realtime-secret',
        'realtime.token_ttl_seconds' => 90,
        'realtime.publish_timeout_seconds' => 1,
        'session.driver' => 'array',
        'cache.default' => 'array',
    ]);
});

it('creates a browser connection configuration without exposing a secret', function (): void {
    $user = new User(['name' => 'Realtime User', 'email' => 'realtime@example.test']);
    $user->id = 42;

    $connection = app(RealtimePublisher::class)->connectionFor($user);
    expect($connection['enabled'])->toBeTrue()
        ->and($connection['url'])->toBe('http://localhost:6002')
        ->and($connection['auth_url'])->toBe(route('realtime.auth'))
        ->and($connection)->not->toHaveKeys(['token', 'secret', 'channel']);
});

it('publishes server-compatible broadcasts to private user channels', function (): void {
    Http::fake(['http://127.0.0.1:6002/publish' => Http::response(['success' => true])]);

    app(RealtimePublisher::class)->usersChanged([9, 9, 12], 'message.changed', [
        'reason' => 'created',
    ]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:6002/publish'
        && $request->header('X-Dromis-Realtime-Secret')[0] === 'test-realtime-secret'
        && $request['rooms'] === ['user:9', 'user:12']
        && $request['event'] === 'message.changed'
        && $request['payload'] === ['reason' => 'created']
        && ! isset($request['socket_token']));
});

it('issues a short-lived user-scoped token without returning the broadcast secret', function (): void {
    $this->withoutMiddleware(HandleInertiaRequests::class);
    $user = new User(['name' => 'Realtime User', 'email' => 'realtime@example.test']);
    $user->id = 42;

    $response = $this->actingAs($user)->getJson(route('realtime.auth'));
    $response->assertOk()->assertJsonStructure(['token']);

    $token = $response->json('token');
    [$encodedPayload, $signature] = explode('.', $token);
    $payload = json_decode(base64_decode(strtr($encodedPayload, '-_', '+/')), true);

    expect($payload['user_id'])->toBe($user->id)
        ->and($payload['exp'] - $payload['iat'])->toBe(90)
        ->and($signature)->not->toBeEmpty()
        ->and($response->getContent())->not->toContain('test-realtime-secret');
});

it('does not issue realtime credentials to guests', function (): void {
    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->getJson(route('realtime.auth'))->assertUnauthorized();
});
