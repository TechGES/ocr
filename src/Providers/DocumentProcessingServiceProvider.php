<?php

namespace Ges\Ocr\Providers;

use Ges\Ocr\Commands\InstallDocumentProcessingCommand;
use Ges\Ocr\Commands\HealthCheckDocumentProcessingCommand;
use Ges\Ocr\Contracts\LlmClient;
use Ges\Ocr\OllamaClient;
use Ges\Ocr\OpenAiClient;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class DocumentProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/ges-ocr.php', 'ges-ocr');

        $this->app->bind(LlmClient::class, function ($app) {
            return match ((string) $app['config']->get('ges-ocr.ai.provider', 'ollama')) {
                'ollama' => $app->make(OllamaClient::class),
                'openai' => $app->make(OpenAiClient::class),
                default => throw new InvalidArgumentException('Unsupported OCR AI provider ['.$app['config']->get('ges-ocr.ai.provider').'].'),
            };
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->commands([
            InstallDocumentProcessingCommand::class,
            HealthCheckDocumentProcessingCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../../config/ges-ocr.php' => config_path('ges-ocr.php'),
        ], 'ges-ocr-config');

        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'ges-ocr-migrations');
    }
}
