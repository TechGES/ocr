<?php

namespace Ges\Ocr\Contracts;

interface LlmClient
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function chatStructured(string $model, array $messages, array $schema): array;

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function chatText(string $model, array $messages): string;

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array;
}
