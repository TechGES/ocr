<?php

namespace Ges\Ocr;

use RuntimeException;

class PdfTextExtractor
{
    public function extract(string $pdfPath): string
    {
        $errorFile = tempnam(sys_get_temp_dir(), 'pdftotext-error-');
        $command = sprintf(
            'pdftotext -layout %s - 2> %s',
            escapeshellarg($pdfPath),
            escapeshellarg($errorFile)
        );

        exec($command, $output, $exitCode);

        $stderr = $errorFile !== false && is_file($errorFile)
            ? trim((string) file_get_contents($errorFile))
            : '';

        if ($errorFile !== false && is_file($errorFile)) {
            @unlink($errorFile);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException($stderr !== '' ? $stderr : 'pdftotext failed to extract document text.');
        }

        return trim(implode(PHP_EOL, $output));
    }
}
