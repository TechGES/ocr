<?php

namespace Ges\Ocr;

use InvalidArgumentException;
use RuntimeException;

class PdfToImageConverter
{
    /**
     * @return array<int, string>
     */
    public function convert(
        string $pdfPath,
        string $outputDirectory,
        int $maxPages = 0,
        ?int $dpi = null
    ): array {
        $this->ensureOutputDirectory($outputDirectory);

        $outputPrefix = rtrim(
            $outputDirectory,
            DIRECTORY_SEPARATOR
        ).DIRECTORY_SEPARATOR.'page';

        $resolvedDpi = $dpi
            ?? (int) config('ges-ocr.processing.pdf_dpi', 0);

        $arguments = [
            'pdftoppm',
            '-png',
        ];

        if ($resolvedDpi > 0) {
            $arguments[] = '-r';
            $arguments[] = (string) $resolvedDpi;
        }

        if ($maxPages > 0) {
            $arguments[] = '-f';
            $arguments[] = '1';
            $arguments[] = '-l';
            $arguments[] = (string) $maxPages;
        }

        $arguments[] = $pdfPath;
        $arguments[] = $outputPrefix;

        $this->runPdftoppm($arguments);

        $pages = glob($outputPrefix.'-*.png');

        if ($pages === false || $pages === []) {
            throw new RuntimeException(
                'No page images were generated from the PDF scan.'
            );
        }

        usort(
            $pages,
            static function (string $left, string $right): int {
                preg_match(
                    '/-(\d+)\.png$/',
                    $left,
                    $leftMatches
                );

                preg_match(
                    '/-(\d+)\.png$/',
                    $right,
                    $rightMatches
                );

                return ((int) ($leftMatches[1] ?? 0))
                    <=> ((int) ($rightMatches[1] ?? 0));
            }
        );

        return array_values($pages);
    }

    /**
     * Convertit une zone précise d'une page PDF.
     *
     * Les coordonnées et dimensions sont exprimées en pixels
     * dans le rendu produit au DPI demandé.
     */
    public function convertRegion(
        string $pdfPath,
        string $outputDirectory,
        int $page,
        int $dpi,
        int $x,
        int $y,
        int $width,
        int $height,
        string $outputName
    ): string {
        $this->validateRegion(
            page: $page,
            dpi: $dpi,
            x: $x,
            y: $y,
            width: $width,
            height: $height
        );

        $this->ensureOutputDirectory($outputDirectory);

        $safeOutputName = preg_replace(
            '/[^A-Za-z0-9._-]+/',
            '-',
            trim($outputName)
        ) ?? '';

        $safeOutputName = trim($safeOutputName, '.-');

        if ($safeOutputName === '') {
            throw new InvalidArgumentException(
                'The PDF region output name must not be empty.'
            );
        }

        $outputPrefix = rtrim(
            $outputDirectory,
            DIRECTORY_SEPARATOR
        ).DIRECTORY_SEPARATOR.$safeOutputName;

        $arguments = [
            'pdftoppm',
            '-png',
            '-singlefile',
            '-f',
            (string) $page,
            '-l',
            (string) $page,
            '-r',
            (string) $dpi,
            '-x',
            (string) $x,
            '-y',
            (string) $y,
            '-W',
            (string) $width,
            '-H',
            (string) $height,
            $pdfPath,
            $outputPrefix,
        ];

        $this->runPdftoppm($arguments);

        $outputPath = $outputPrefix.'.png';

        if (! is_file($outputPath)) {
            throw new RuntimeException(
                'No image was generated from the requested PDF region.'
            );
        }

        return $outputPath;
    }

    protected function ensureOutputDirectory(
        string $outputDirectory
    ): void {
        if (
            ! is_dir($outputDirectory)
            && ! mkdir($outputDirectory, 0777, true)
            && ! is_dir($outputDirectory)
        ) {
            throw new RuntimeException(
                'Unable to create the PDF conversion directory.'
            );
        }
    }

    protected function validateRegion(
        int $page,
        int $dpi,
        int $x,
        int $y,
        int $width,
        int $height
    ): void {
        if ($page < 1) {
            throw new InvalidArgumentException(
                'The PDF page number must be greater than zero.'
            );
        }

        if ($dpi < 1) {
            throw new InvalidArgumentException(
                'The PDF region DPI must be greater than zero.'
            );
        }

        if ($x < 0 || $y < 0) {
            throw new InvalidArgumentException(
                'PDF region coordinates must not be negative.'
            );
        }

        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException(
                'PDF region dimensions must be greater than zero.'
            );
        }
    }

    /**
     * @param array<int, string> $arguments
     */
    protected function runPdftoppm(array $arguments): void
    {
        $errorFile = tempnam(
            sys_get_temp_dir(),
            'pdftoppm-error-'
        );

        if ($errorFile === false) {
            throw new RuntimeException(
                'Unable to create the pdftoppm error file.'
            );
        }

        $command = implode(
            ' ',
            array_map(
                static fn (string $argument): string =>
                    escapeshellarg($argument),
                $arguments
            )
        );

        $command .= ' 2> '.escapeshellarg($errorFile);

        try {
            $result = $this->executeCommand($command);

            $stderr = is_file($errorFile)
                ? trim((string) file_get_contents($errorFile))
                : '';

            if (($result['exit_code'] ?? 1) !== 0) {
                throw new RuntimeException(
                    $stderr !== ''
                        ? $stderr
                        : 'pdftoppm failed to convert the PDF.'
                );
            }
        } finally {
            if (is_file($errorFile)) {
                @unlink($errorFile);
            }
        }
    }

    /**
     * @return array{
     *     exit_code: int,
     *     output: array<int, string>
     * }
     */
    protected function executeCommand(string $command): array
    {
        $output = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);

        return [
            'exit_code' => $exitCode,
            'output' => $output,
        ];
    }
}
