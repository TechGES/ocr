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


it('sends temperature zero for structured requests using gpt 4 models', function () {
    config()->set('ges-ocr.openai.base_url', 'https://api.openai.local/v1');
    config()->set('ges-ocr.openai.api_key', 'sk-test');

    Http::fake([
        'https://api.openai.local/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => '{"valid":true}',
                ],
            ]],
        ], 200),
    ]);

    $result = app(OpenAiClient::class)->chatStructured(
        'gpt-4.1-mini',
        [['role' => 'user', 'content' => 'Extract data']],
        [
            'type' => 'object',
            'properties' => [
                'valid' => ['type' => 'boolean'],
            ],
            'required' => ['valid'],
            'additionalProperties' => false,
        ],
    );

    expect($result)->toBe(['valid' => true]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.openai.local/v1/chat/completions'
            && $request['model'] === 'gpt-4.1-mini'
            && $request['temperature'] === 0;
    });
});

it('omits temperature for structured requests using gpt 5 models', function () {
    config()->set('ges-ocr.openai.base_url', 'https://api.openai.local/v1');
    config()->set('ges-ocr.openai.api_key', 'sk-test');

    Http::fake([
        'https://api.openai.local/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => '{"valid":true}',
                ],
            ]],
        ], 200),
    ]);

    $result = app(OpenAiClient::class)->chatStructured(
        'gpt-5-mini',
        [['role' => 'user', 'content' => 'Extract data']],
        [
            'type' => 'object',
            'properties' => [
                'valid' => ['type' => 'boolean'],
            ],
            'required' => ['valid'],
            'additionalProperties' => false,
        ],
    );

    expect($result)->toBe(['valid' => true]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.openai.local/v1/chat/completions'
            && $request['model'] === 'gpt-5-mini'
            && ! array_key_exists('temperature', $request->data());
    });
});

it('sends temperature zero for text requests using gpt 4 models', function () {
    config()->set('ges-ocr.openai.base_url', 'https://api.openai.local/v1');
    config()->set('ges-ocr.openai.api_key', 'sk-test');

    Http::fake([
        'https://api.openai.local/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => 'MSA',
                ],
            ]],
        ], 200),
    ]);

    $result = app(OpenAiClient::class)->chatText(
        'gpt-4.1-mini',
        [['role' => 'user', 'content' => 'Classify document']],
    );

    expect($result)->toBe('MSA');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.openai.local/v1/chat/completions'
            && $request['model'] === 'gpt-4.1-mini'
            && $request['temperature'] === 0;
    });
});

it('omits temperature for text requests using gpt 5 models', function () {
    config()->set('ges-ocr.openai.base_url', 'https://api.openai.local/v1');
    config()->set('ges-ocr.openai.api_key', 'sk-test');

    Http::fake([
        'https://api.openai.local/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => 'MSA',
                ],
            ]],
        ], 200),
    ]);

    $result = app(OpenAiClient::class)->chatText(
        'gpt-5-mini',
        [['role' => 'user', 'content' => 'Classify document']],
    );

    expect($result)->toBe('MSA');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.openai.local/v1/chat/completions'
            && $request['model'] === 'gpt-5-mini'
            && ! array_key_exists('temperature', $request->data());
    });
});
