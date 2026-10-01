<?php

use App\Services\GroqChatService;
use Illuminate\Http\Client\Response;
use GuzzleHttp\Psr7\Response as Psr7Response;

it('strips qwen think tags from groq message text', function (): void {
    $service = app(GroqChatService::class);
    $response = new Response(new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode([
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => "<think>\nHidden reasoning\n</think>\n\nThe LGU continues monitoring the affected barangays.",
            ],
        ]],
    ], JSON_THROW_ON_ERROR)));

    expect($service->messageText($response))->toBe('The LGU continues monitoring the affected barangays.');
});

it('explains dns failures in unreachable messages', function (): void {
    $service = app(GroqChatService::class);

    expect($service->unreachableMessage(new RuntimeException('cURL error 6: Could not resolve host: api.groq.com')))
        ->toContain('could not resolve api.groq.com');
});
