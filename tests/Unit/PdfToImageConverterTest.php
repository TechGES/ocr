<?php

declare(strict_types=1);

use Ges\Ocr\PdfToImageConverter;

function makeTestPdfConverter(): PdfToImageConverter
{
    return new class extends PdfToImageConverter
    {
        /** @var array<int, string> */
        public array $commands = [];

        public ?\Closure $afterExecute = null;

        protected function executeCommand(string $command): array
        {
            $this->commands[] = $command;

            if ($this->afterExecute instanceof \Closure) {
                ($this->afterExecute)();
            }

            return [
                'exit_code' => 0,
                'output' => [],
            ];
        }
    };
}

function removeTestDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (glob($directory.'/*') ?: [] as $path) {
        if (is_dir($path)) {
            removeTestDirectory($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}

it('builds ordered PDF page images with an explicit DPI', function () {
    $directory = sys_get_temp_dir()
        .'/ges-ocr-pdf-pages-'
        .uniqid('', true);

    mkdir($directory, 0777, true);

    $pdfPath = $directory.'/source.pdf';
    file_put_contents($pdfPath, 'fake-pdf');

    $outputDirectory = $directory.'/pages';

    $converter = makeTestPdfConverter();

    $converter->afterExecute = static function () use (
        $outputDirectory
    ): void {
        file_put_contents(
            $outputDirectory.'/page-2.png',
            'page-2'
        );

        file_put_contents(
            $outputDirectory.'/page-1.png',
            'page-1'
        );
    };

    try {
        $pages = $converter->convert(
            pdfPath: $pdfPath,
            outputDirectory: $outputDirectory,
            maxPages: 2,
            dpi: 300
        );

        expect($pages)->toBe([
            $outputDirectory.'/page-1.png',
            $outputDirectory.'/page-2.png',
        ]);

        $command = $converter->commands[0] ?? '';

        expect($command)
            ->toContain("'pdftoppm'")
            ->toContain("'-png'")
            ->toContain("'-r' '300'")
            ->toContain("'-f' '1'")
            ->toContain("'-l' '2'")
            ->toContain(escapeshellarg($pdfPath))
            ->toContain(
                escapeshellarg($outputDirectory.'/page')
            );
    } finally {
        removeTestDirectory($directory);
    }
});

it('builds a single cropped PDF page region', function () {
    $directory = sys_get_temp_dir()
        .'/ges-ocr-pdf-region-'
        .uniqid('', true);

    mkdir($directory, 0777, true);

    $pdfPath = $directory.'/source.pdf';
    file_put_contents($pdfPath, 'fake-pdf');

    $outputDirectory = $directory.'/regions';
    $outputPath = $outputDirectory.'/page-2-strip-01.png';

    $converter = makeTestPdfConverter();

    $converter->afterExecute = static function () use (
        $outputPath
    ): void {
        file_put_contents($outputPath, 'region');
    };

    try {
        $result = $converter->convertRegion(
            pdfPath: $pdfPath,
            outputDirectory: $outputDirectory,
            page: 2,
            dpi: 600,
            x: 1200,
            y: 2400,
            width: 2200,
            height: 1400,
            outputName: 'page-2-strip-01'
        );

        expect($result)->toBe($outputPath);

        $command = $converter->commands[0] ?? '';

        expect($command)
            ->toContain("'-singlefile'")
            ->toContain("'-f' '2'")
            ->toContain("'-l' '2'")
            ->toContain("'-r' '600'")
            ->toContain("'-x' '1200'")
            ->toContain("'-y' '2400'")
            ->toContain("'-W' '2200'")
            ->toContain("'-H' '1400'")
            ->toContain(escapeshellarg($pdfPath))
            ->toContain(
                escapeshellarg(
                    $outputDirectory.'/page-2-strip-01'
                )
            );
    } finally {
        removeTestDirectory($directory);
    }
});

it('rejects invalid PDF region coordinates', function () {
    $converter = makeTestPdfConverter();

    expect(
        fn () => $converter->convertRegion(
            pdfPath: '/tmp/source.pdf',
            outputDirectory: '/tmp/output',
            page: 0,
            dpi: 600,
            x: 0,
            y: 0,
            width: 2200,
            height: 1400,
            outputName: 'region'
        )
    )->toThrow(InvalidArgumentException::class);
});
