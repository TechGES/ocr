<?php

namespace Ges\Ocr;

use Ges\Ocr\Support\DocumentProcessingValues;

class DocumentInputDetector
{
    public function __construct(
        protected PdfTextExtractor $pdfTextExtractor
    ) {}

    /**
     * @return array{input_type: string, pages_count: int|null, extracted_text: string|null}
     */
    public function detect(string $documentPath, string $mimeType): array
    {
        if (str_starts_with($mimeType, 'image/')) {
            return [
                'input_type' => DocumentProcessingValues::INPUT_TYPE_IMAGE,
                'pages_count' => 1,
                'extracted_text' => null,
            ];
        }

        $extractedText = $this->pdfTextExtractor->extract($documentPath);
        $normalizedText = preg_replace('/\s+/', ' ', $extractedText);
        if (! is_string($normalizedText)) {
            $normalizedText = str_replace(["\r", "\n", "\t", "\f"], ' ', $extractedText);
        }

        $normalizedText = trim($normalizedText);
        $isTextPdf = mb_strlen(trim($normalizedText)) >= 80;

        return [
            'input_type' => $isTextPdf ? DocumentProcessingValues::INPUT_TYPE_PDF_TEXT : DocumentProcessingValues::INPUT_TYPE_PDF_SCAN,
            'pages_count' => $this->countPagesFromExtractedText($extractedText),
            'extracted_text' => $isTextPdf ? $extractedText : null,
        ];
    }

    private function countPagesFromExtractedText(string $text): ?int
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return null;
        }

        return max(substr_count($text, "\f") + 1, 1);
    }
}
