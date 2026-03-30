<?php

namespace Ges\Ocr\Commands;

use Ges\Ocr\OllamaClient;
use Illuminate\Console\Command;
use RuntimeException;

class HealthCheckDocumentProcessingCommand extends Command
{
    protected $signature = 'ocr:health';

    protected $description = 'Check OCR package dependencies, configuration, and Ollama connectivity.';

    public function __construct(
        protected OllamaClient $ollamaClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->components->info('Checking OCR package health...');

        $this->reportBinary('pdftotext');
        $this->reportBinary('pdftoppm');

        try {
            $health = $this->ollamaClient->healthCheck();
        } catch (RuntimeException $exception) {
            $this->components->error('Ollama check failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $availableModels = array_map(
            static fn (array $model): string => (string) ($model['name'] ?? ''),
            is_array($health['available_models']) ? $health['available_models'] : []
        );

        $this->components->info('Ollama base URL: '.$health['base_url']);
        $this->components->info('Text model: '.$health['text_model']);
        $this->components->info('Vision model: '.$health['vision_model']);
        $this->components->info('Available models: '.implode(', ', array_filter($availableModels)));

        return self::SUCCESS;
    }

    private function reportBinary(string $binary): void
    {
        $resolved = shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');

        if (is_string($resolved) && trim($resolved) !== '') {
            $this->components->info(sprintf('%s: %s', $binary, trim($resolved)));

            return;
        }

        $this->components->warn(sprintf('%s not found in PATH.', $binary));
    }
}
