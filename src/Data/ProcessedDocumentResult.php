<?php

namespace Ges\Ocr\Data;

use Ges\Ocr\Support\OcrVersion;

class ProcessedDocumentResult
{
    /**
     * @param  array<string, mixed>|null  $rawClassificationJson
     * @param  array<string, mixed>|null  $rawExtractionJson
     * @param  array<string, mixed>|null  $normalizedJson
     */
    public function __construct(
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly string $path,
        public readonly ?string $inputType,
        public readonly ?string $documentType,
        public readonly string $status,
        public readonly ?int $pagesCount,
        public readonly ?array $rawClassificationJson,
        public readonly ?array $rawExtractionJson,
        public readonly ?array $normalizedJson,
        public readonly ?string $errorMessage,
        public readonly ?int $processingId = null,
        public readonly string $ocrVersion = OcrVersion::CURRENT,
    ) {}

    /**
     * Public OCR response returned to package consumers such as the CRM.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ocr_version' => $this->ocrVersion,
            'input_type' => $this->inputType,
            'document_type' => $this->documentType,
            'status' => $this->status,
            'pages_count' => $this->pagesCount,
            'normalized' => $this->normalizedJson,
            'raw_classification' => $this->rawClassificationJson,
            'raw_extraction' => $this->rawExtractionJson,
            'error' => $this->errorMessage,
        ];
    }

    /**
     * Persistence attributes for the document_processings table.
     *
     * OCR version is intentionally excluded because the current table
     * does not contain an ocr_version column.
     *
     * @return array<string, mixed>
     */
    public function toProcessingAttributes(): array
    {
        return [
            'input_type' => $this->inputType,
            'document_type' => $this->documentType,
            'status' => $this->status,
            'pages_count' => $this->pagesCount,
            'raw_classification_json' => $this->rawClassificationJson,
            'raw_extraction_json' => $this->rawExtractionJson,
            'normalized_json' => $this->normalizedJson,
            'error_message' => $this->errorMessage,
        ];
    }
}
