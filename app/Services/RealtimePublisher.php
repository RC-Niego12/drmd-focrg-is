<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RealtimePublisher
{
    public function enabled(): bool
    {
        return (bool) config('realtime.enabled')
            && filled(config('realtime.public_url'))
            && filled(config('realtime.internal_url'))
            && filled(config('realtime.secret'));
    }

    public function connectionFor(?User $user): array
    {
        if (! $user || ! $this->enabled()) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'url' => config('realtime.public_url'),
            'auth_url' => route('realtime.auth'),
        ];
    }

    public function connectionToken(User $user): string
    {
        $payload = $this->base64UrlEncode(json_encode([
            'user_id' => (int) $user->id,
            'iat' => now()->timestamp,
            'exp' => now()->addSeconds((int) config('realtime.token_ttl_seconds', 90))->timestamp,
            'nonce' => bin2hex(random_bytes(16)),
        ], JSON_THROW_ON_ERROR));

        $signature = $this->base64UrlEncode(hash_hmac(
            'sha256',
            $payload,
            (string) config('realtime.secret'),
            true,
        ));

        return $payload.'.'.$signature;
    }

    public function usersChanged(iterable $userIds, string $event, array $payload = []): void
    {
        $rooms = collect($userIds)
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): string => 'user:'.(int) $id)
            ->unique()
            ->values()
            ->all();

        $this->publish($event, $rooms, $payload);
    }

    public function userChanged(int $userId, string $event, array $payload = []): void
    {
        $this->publish($event, ['user:'.$userId], $payload);
    }

    public function publish(string $event, array $rooms, array $payload = []): void
    {
        if (! $this->enabled() || $rooms === []) {
            return;
        }

        try {
            Http::asJson()
                ->withHeaders([
                    'X-Dromis-Realtime-Secret' => (string) config('realtime.secret'),
                ])
                ->timeout((float) config('realtime.publish_timeout_seconds', 1.5))
                ->post(rtrim((string) config('realtime.internal_url'), '/').'/publish', [
                    'rooms' => array_values(array_unique($rooms)),
                    'event' => $event,
                    'payload' => $payload,
                ])
                ->throw();
        } catch (Throwable $exception) {
            // Real-time delivery is an optimization. Never fail the underlying
            // workflow; disconnected browsers retain a low-frequency fallback.
            Log::debug('Socket.IO publish unavailable.', [
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
