<?php

namespace Ges\Ocr\Commands;

use Illuminate\Console\Command;

class InstallDocumentProcessingCommand extends Command
{
    protected $signature = 'ocr:install
        {--migrate : Run the document processing migration after publishing}
        {--force : Overwrite any existing published files}
        {--no-config : Skip publishing the package configuration file}
        {--no-migrations : Skip publishing the package migrations}
        {--check : Run the OCR health check after installation}';

    protected $description = 'Install the document processing package configuration and migrations.';

    public function handle(): int
    {
        if (! $this->option('no-config')) {
            $this->call('vendor:publish', [
                '--tag' => 'ges-ocr-config',
                '--force' => $this->option('force'),
            ]);
        }

        if (! $this->option('no-migrations')) {
            $this->call('vendor:publish', [
                '--tag' => 'ges-ocr-migrations',
                '--force' => $this->option('force'),
            ]);
        }

        if ($this->option('migrate')) {
            $this->call('migrate');
        } else {
            $this->components->info('OCR package installation completed.');

            if (! $this->option('no-migrations')) {
                $this->components->info('Run `php artisan migrate` when you are ready to create the table.');
            }
        }

        if ($this->option('check')) {
            $this->call('ocr:health');
        }

        return self::SUCCESS;
    }
}
