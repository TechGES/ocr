<?php

declare(strict_types=1);

use Ges\Ocr\Contracts\LlmClient;
use Ges\Ocr\OpenAiClient;
use Illuminate\Support\Facades\Http;

it('sends bearer auth headers to the configured openai base url', function () {
    config()->set('ges-ocr.ai.provider', 'openai');
    config()->set('ges-ocr.openai.base_url', 'https://api.openai.local/v1');
    config()->set('ges-ocr.openai.api_key', 'sk-test-123');
    config()->set('ges-ocr.openai.text_model', 'gpt-test');
    config()->set('ges-ocr.openai.vision_model', 'gpt-test-vision');

    Http::fake([
        'https://api.openai.local/v1/models' => Http::response([
            'data' => [['id' => 'gpt-test']],
        ], 200),
    ]);

    $health = app(OpenAiClient::class)->healthCheck();

    expect($health['provider'])->toBe('openai')
        ->and($health['base_url'])->toBe('https://api.openai.local/v1');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.openai.local/v1/models'
            && $request->hasHeader('Authorization', 'Bearer sk-test-123');
    });
});

it('binds the llm client contract to openai when configured', function () {
    config()->set('ges-ocr.ai.provider', 'openai');

    expect(app(LlmClient::class))->toBeInstanceOf(OpenAiClient::class);
});
