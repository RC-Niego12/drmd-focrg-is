<?php

namespace App\Services;

use App\Support\AssessmentNarrative;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GroqChatService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{response: Response, model: string}
     */
    public function complete(array $payload, int $timeout = 45): array
    {
        $models = $this->modelsFor($payload['model'] ?? null);
        $lastModel = $models[0];
        $lastResponse = null;

        foreach ($models as $model) {
            $lastModel = $model;
            $body = $payload;
            $body['model'] = $model;

            if (str_starts_with($model, 'openai/gpt-oss')) {
                $body['include_reasoning'] = false;
            }

            if (str_starts_with($model, 'qwen/')) {
                $body['reasoning_effort'] = $body['reasoning_effort'] ?? 'none';
            }

            $lastResponse = Http::timeout($timeout)
                ->connectTimeout(12)
                ->retry(3, 800, fn (Throwable $exception): bool => $exception instanceof ConnectionException)
                ->withToken((string) config('services.groq.api_key'))
                ->acceptJson()
                ->post($this->endpoint(), $body);

            if ($lastResponse->successful()) {
                if ($this->messageText($lastResponse) !== '' || ! $this->shouldRetryEmpty($model, $models, $lastModel)) {
                    return ['response' => $lastResponse, 'model' => $model];
                }

                continue;
            }

            if (! $this->isMissingModel($lastResponse)) {
                return ['response' => $lastResponse, 'model' => $model];
            }
        }

        return ['response' => $lastResponse, 'model' => $lastModel];
    }

    public function messageText(Response $response): string
    {
        $content = data_get($response->json(), 'choices.0.message.content');

        if (is_array($content)) {
            $content = collect($content)
                ->map(fn ($part) => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part)
                ->implode('');
        }

        $text = (string) $content;
        $text = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $text) ?? $text;
        $text = preg_replace('/<think\b[^>]*>.*$/is', '', $text) ?? $text;
        $text = preg_replace('/<\/?think\b[^>]*>/i', '', $text) ?? $text;

        return AssessmentNarrative::sanitize($text);
    }

    public function errorMessage(Response $response, string $fallback): string
    {
        $detail = trim((string) data_get($response->json(), 'error.message'));

        if ($detail === '') {
            return $fallback;
        }

        if (str_contains($detail, 'does not exist') || data_get($response->json(), 'error.code') === 'model_not_found') {
            return 'Groq AI could not process the narrative because the configured model is no longer available. Update GROQ_MODEL, then clear the configuration cache.';
        }

        if (data_get($response->json(), 'error.code') === 'rate_limit_exceeded') {
            return 'Groq AI is rate-limited right now. Wait a moment and try Auto-generate again.';
        }

        return $fallback;
    }

    public function unreachableMessage(Throwable $exception): string
    {
        $raw = Str::lower($exception->getMessage());

        if (str_contains($raw, 'could not resolve host') || str_contains($raw, 'curl error 6')) {
            return 'Groq AI could not be reached because this computer could not resolve api.groq.com. Check DNS or internet, then try Auto-generate again.';
        }

        if (str_contains($raw, 'timed out') || str_contains($raw, 'curl error 28')) {
            return 'Groq AI timed out. Check your internet connection and try Auto-generate again.';
        }

        return 'Groq AI could not be reached. Check your internet connection and try Auto-generate again.';
    }

    /**
     * @return list<string>
     */
    private function modelsFor(?string $requested): array
    {
        $fallbacks = config('services.groq.fallback_models', []);
        if (! is_array($fallbacks)) {
            $fallbacks = preg_split('/\s*,\s*/', (string) $fallbacks) ?: [];
        }

        return array_values(array_unique(array_filter([
            $requested ?: (string) config('services.groq.model'),
            ...$fallbacks,
        ], fn ($model) => filled($model))));
    }

    private function endpoint(): string
    {
        return rtrim((string) config('services.groq.base_url'), '/').'/chat/completions';
    }

    private function isMissingModel(Response $response): bool
    {
        if ($response->status() !== 404) {
            return false;
        }

        $code = (string) data_get($response->json(), 'error.code');
        $message = Str::lower((string) data_get($response->json(), 'error.message'));

        return $code === 'model_not_found' || str_contains($message, 'does not exist');
    }

    /**
     * @param  list<string>  $models
     */
    private function shouldRetryEmpty(string $model, array $models, string $currentModel): bool
    {
        $remaining = array_slice($models, array_search($currentModel, $models, true) + 1);

        return $remaining !== [] && (
            str_starts_with($model, 'openai/gpt-oss')
            || str_starts_with($model, 'qwen/')
        );
    }
}
