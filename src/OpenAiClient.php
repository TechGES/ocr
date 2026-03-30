<?php

namespace Ges\Ocr;

use Ges\Ocr\Contracts\LlmClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiClient implements LlmClient
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function chatStructured(string $model, array $messages, array $schema): array
    {
        $response = $this->http()->post('/chat/completions', [
            'model' => $model,
            'temperature' => 0,
            'messages' => $this->messages($messages),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'ocr_response',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message') ?? $response->body() ?: 'OpenAI request failed.');
        }

        $content = $response->json('choices.0.message.content');

        if (is_string($content) && trim($content) !== '') {
            $decoded = json_decode($content, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (is_array($content) && $content !== []) {
            foreach ($content as $part) {
                $text = $part['text'] ?? null;

                if (is_string($text) && trim($text) !== '') {
                    $decoded = json_decode($text, true);

                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        throw new RuntimeException('OpenAI returned an empty or invalid structured response.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function chatText(string $model, array $messages): string
    {
        $response = $this->http()->post('/chat/completions', [
            'model' => $model,
            'temperature' => 0,
            'messages' => $this->messages($messages),
        ]);

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message') ?? $response->body() ?: 'OpenAI request failed.');
        }

        $content = $response->json('choices.0.message.content');

        if (is_string($content) && trim($content) !== '') {
            return trim($content);
        }

        if (is_array($content) && $content !== []) {
            $parts = [];

            foreach ($content as $part) {
                $text = $part['text'] ?? null;

                if (is_string($text) && trim($text) !== '') {
                    $parts[] = trim($text);
                }
            }

            if ($parts !== []) {
                return trim(implode("\n", $parts));
            }
        }

        throw new RuntimeException('OpenAI returned an empty text response.');
    }

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        $response = $this->http()->get('/models');

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message') ?? $response->body() ?: 'Unable to reach OpenAI.');
        }

        return [
            'provider' => 'openai',
            'base_url' => config('ges-ocr.openai.base_url'),
            'text_model' => config('ges-ocr.openai.text_model'),
            'vision_model' => config('ges-ocr.openai.vision_model'),
            'available_models' => $response->json('data', []),
        ];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('ges-ocr.openai.base_url', 'https://api.openai.com/v1'), '/'))
            ->acceptJson()
            ->asJson()
            ->withToken((string) config('ges-ocr.openai.api_key'))
            ->connectTimeout((int) config('ges-ocr.openai.connect_timeout', 10))
            ->timeout((int) config('ges-ocr.openai.timeout', 120))
            ->retry(
                (int) config('ges-ocr.openai.retry_times', 2),
                (int) config('ges-ocr.openai.retry_sleep_ms', 500)
            );
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function messages(array $messages): array
    {
        return array_map(function (array $message): array {
            $content = $message['content'] ?? '';
            $images = $message['images'] ?? [];

            if (! is_array($images) || $images === []) {
                return [
                    'role' => $message['role'] ?? 'user',
                    'content' => $content,
                ];
            }

            $parts = [[
                'type' => 'text',
                'text' => (string) $content,
            ]];

            foreach ($images as $image) {
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $this->imageDataUri($image),
                    ],
                ];
            }

            return [
                'role' => $message['role'] ?? 'user',
                'content' => $parts,
            ];
        }, $messages);
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private function imageDataUri(array $image): string
    {
        $mimeType = (string) ($image['mime_type'] ?? 'image/png');
        $data = (string) ($image['data'] ?? '');

        return sprintf('data:%s;base64,%s', $mimeType, $data);
    }
}
