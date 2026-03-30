<?php

namespace Ges\Ocr;

use Ges\Ocr\Contracts\LlmClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaClient implements LlmClient
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function chatStructured(string $model, array $messages, array $schema): array
    {
        $response = $this->http()->post('/api/chat', [
            'model' => $model,
            'stream' => false,
            'format' => $schema,
            'options' => [
                'temperature' => 0,
            ],
            'messages' => $this->sanitizeForJson($messages),
        ]);

        if ($response->failed()) {
            throw new RuntimeException($response->json('error') ?? $response->body() ?: 'Ollama request failed.');
        }

        $content = $response->json('message.content');

        if (is_array($content)) {
            return $content;
        }

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Ollama returned an empty structured response.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Ollama returned invalid JSON content.');
        }

        return $decoded;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function chatText(string $model, array $messages): string
    {
        $response = $this->http()->post('/api/chat', [
            'model' => $model,
            'stream' => false,
            'options' => [
                'temperature' => 0,
            ],
            'messages' => $this->sanitizeForJson($messages),
        ]);

        if ($response->failed()) {
            throw new RuntimeException($response->json('error') ?? $response->body() ?: 'Ollama request failed.');
        }

        $content = $response->json('message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Ollama returned an empty text response.');
        }

        return trim($content);
    }

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        $response = $this->http()->get('/api/tags');

        if ($response->failed()) {
            throw new RuntimeException($response->json('error') ?? $response->body() ?: 'Unable to reach Ollama.');
        }

        return [
            'provider' => 'ollama',
            'base_url' => config('ges-ocr.ollama.base_url'),
            'text_model' => config('ges-ocr.ollama.text_model'),
            'vision_model' => config('ges-ocr.ollama.vision_model'),
            'available_models' => $response->json('models', []),
        ];
    }

    private function http(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('ges-ocr.ollama.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('ges-ocr.ollama.connect_timeout', 10))
            ->timeout((int) config('ges-ocr.ollama.timeout', 120))
            ->retry(
                (int) config('ges-ocr.ollama.retry_times', 2),
                (int) config('ges-ocr.ollama.retry_sleep_ms', 500)
            );

        if ((bool) config('ges-ocr.ollama.basic_auth.enabled', false)) {
            $request = $request->withBasicAuth(
                (string) config('ges-ocr.ollama.basic_auth.username', ''),
                (string) config('ges-ocr.ollama.basic_auth.password', '')
            );
        }

        return $request;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sanitizeForJson(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('data', $value)) {
            return $value['data'];
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizeForJson($item);
            }

            return $sanitized;
        }

        if (! is_string($value)) {
            return $value;
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return is_string($converted) ? $converted : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
