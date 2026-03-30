<?php

declare(strict_types=1);

use Ges\Ocr\OllamaClient;
use Illuminate\Support\Facades\Http;

it('sends basic auth headers to the configured ollama base url', function () {
    config()->set('ges-ocr.ollama.base_url', 'http://ollama.internal:11434');
    config()->set('ges-ocr.ollama.basic_auth.enabled', true);
    config()->set('ges-ocr.ollama.basic_auth.username', 'prod-user');
    config()->set('ges-ocr.ollama.basic_auth.password', 'secret-pass');

    Http::fake([
        'http://ollama.internal:11434/api/tags' => Http::response(['models' => []], 200),
    ]);

    $health = app(OllamaClient::class)->healthCheck();

    expect($health['base_url'])->toBe('http://ollama.internal:11434');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'http://ollama.internal:11434/api/tags'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('prod-user:secret-pass'));
    });
});

