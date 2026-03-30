<?php

namespace Ges\Ocr\Support;

use InvalidArgumentException;

class LlmConfig
{
    public static function provider(): string
    {
        return (string) config('ges-ocr.ai.provider', 'ollama');
    }

    public static function textModel(): string
    {
        return match (self::provider()) {
            'ollama' => (string) config('ges-ocr.ollama.text_model'),
            'openai' => (string) config('ges-ocr.openai.text_model'),
            default => throw new InvalidArgumentException('Unsupported OCR AI provider ['.self::provider().'].'),
        };
    }

    public static function visionModel(): string
    {
        return match (self::provider()) {
            'ollama' => (string) config('ges-ocr.ollama.vision_model'),
            'openai' => (string) config('ges-ocr.openai.vision_model'),
            default => throw new InvalidArgumentException('Unsupported OCR AI provider ['.self::provider().'].'),
        };
    }

    public static function classificationConfidenceThreshold(): float
    {
        return (float) config('ges-ocr.ai.classification_confidence_threshold', 0.75);
    }

    public static function maxPages(): int
    {
        return max((int) config('ges-ocr.ai.max_pages', 0), 0);
    }
}
