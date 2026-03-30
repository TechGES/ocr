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
        $app['config']->set('ges-ocr.ollama.text_model', 'qwen2.5:7b');
        $app['config']->set('ges-ocr.ollama.vision_model', 'qwen2.5vl:7b');
        $app['config']->set('ges-ocr.ollama.timeout', 120);
        $app['config']->set('ges-ocr.ollama.classification_confidence_threshold', 0.75);
        $app['config']->set('ges-ocr.ollama.max_pages', 0);
    }
}
