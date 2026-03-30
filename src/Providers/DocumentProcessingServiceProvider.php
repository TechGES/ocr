<?php

namespace Ges\Ocr\Providers;

use Ges\Ocr\Commands\InstallDocumentProcessingCommand;
use Ges\Ocr\Commands\HealthCheckDocumentProcessingCommand;
use Illuminate\Support\ServiceProvider;

class DocumentProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/ges-ocr.php', 'ges-ocr');
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
