<?php

declare(strict_types=1);

namespace Ges\Ocr\Tests;

use Ges\Ocr\Providers\DocumentProcessingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DocumentProcessingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ges-ocr.ai.provider', 'ollama');
        $app['config']->set('ges-ocr.ai.classification_confidence_threshold', 0.75);
        $app['config']->set('ges-ocr.ai.max_pages', 0);
        $app['config']->set('ges-ocr.ollama.text_model', 'qwen2.5:7b');
        $app['config']->set('ges-ocr.ollama.vision_model', 'qwen2.5vl:7b');
        $app['config']->set('ges-ocr.ollama.timeout', 120);
        $app['config']->set('ges-ocr.openai.api_key', 'test-openai-key');
        $app['config']->set('ges-ocr.openai.text_model', 'gpt-4.1-mini');
        $app['config']->set('ges-ocr.openai.vision_model', 'gpt-4.1-mini');
    }
}
