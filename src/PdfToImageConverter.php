<?php

namespace Ges\Ocr;

use RuntimeException;

class PdfToImageConverter
{
    /**
     * @return array<int, string>
     */
    public function convert(string $pdfPath, string $outputDirectory, int $maxPages = 0): array
    {
        if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true) && ! is_dir($outputDirectory)) {
            throw new RuntimeException('Unable to create the PDF conversion directory.');
        }

        $outputPrefix = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'page';
        $errorFile = tempnam(sys_get_temp_dir(), 'pdftoppm-error-');
        $pageLimitArgument = $maxPages > 0 ? '-f 1 -l '.(int) $maxPages.' ' : '';
        $command = sprintf(
            'pdftoppm -png %s%s %s 2> %s',
            $pageLimitArgument,
            escapeshellarg($pdfPath),
            escapeshellarg($outputPrefix),
            escapeshellarg($errorFile)
        );

        exec($command, $unusedOutput, $exitCode);

        $stderr = $errorFile !== false && is_file($errorFile)
            ? trim((string) file_get_contents($errorFile))
            : '';

        if ($errorFile !== false && is_file($errorFile)) {
            @unlink($errorFile);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException($stderr !== '' ? $stderr : 'pdftoppm failed to convert the PDF into page images.');
        }

        $pages = glob($outputPrefix.'-*.png');

        if ($pages === false || $pages === []) {
            throw new RuntimeException('No page images were generated from the PDF scan.');
        }

        usort($pages, static function (string $left, string $right): int {
            preg_match('/-(\d+)\.png$/', $left, $leftMatches);
            preg_match('/-(\d+)\.png$/', $right, $rightMatches);

            return ((int) ($leftMatches[1] ?? 0)) <=> ((int) ($rightMatches[1] ?? 0));
        });

        return array_values($pages);
    }
}
