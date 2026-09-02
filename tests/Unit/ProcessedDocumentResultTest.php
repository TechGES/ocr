<?php

declare(strict_types=1);

use Ges\Ocr\Data\ProcessedDocumentResult;
use Ges\Ocr\Support\OcrVersion;

it('exposes the OCR version in the public response without changing persistence attributes', function () {
    $result = new ProcessedDocumentResult(
        originalName: 'msa.pdf',
        mimeType: 'application/pdf',
        path: '/tmp/msa.pdf',
        inputType: 'pdf_text',
        documentType: 'msa',
        status: 'done',
        pagesCount: 2,
        rawClassificationJson: [
            'document_type' => 'msa',
            'confidence' => 0.99,
        ],
        rawExtractionJson: [
            'document_type' => 'msa',
            'msa_parcels' => [],
        ],
        normalizedJson: [
            'document_type' => 'msa',
            'msa_parcels' => [],
        ],
        errorMessage: null,
    );

    expect($result->ocrVersion)
        ->toBe(OcrVersion::CURRENT)
        ->and($result->ocrVersion)
        ->toBe('0.4.1');

    expect($result->toArray())->toBe([
        'ocr_version' => '0.4.1',
        'input_type' => 'pdf_text',
        'document_type' => 'msa',
        'status' => 'done',
        'pages_count' => 2,
        'normalized' => [
            'document_type' => 'msa',
            'msa_parcels' => [],
        ],
        'raw_classification' => [
            'document_type' => 'msa',
            'confidence' => 0.99,
        ],
        'raw_extraction' => [
            'document_type' => 'msa',
            'msa_parcels' => [],
        ],
        'error' => null,
    ]);

    expect(array_key_exists(
        'ocr_version',
        $result->toProcessingAttributes()
    ))->toBeFalse();
});

it('allows an explicit OCR version for replaying archived results', function () {
    $result = new ProcessedDocumentResult(
        originalName: 'msa.pdf',
        mimeType: 'application/pdf',
        path: '/tmp/msa.pdf',
        inputType: 'pdf_text',
        documentType: 'msa',
        status: 'done',
        pagesCount: 1,
        rawClassificationJson: null,
        rawExtractionJson: null,
        normalizedJson: null,
        errorMessage: null,
        ocrVersion: '0.3.9',
    );

    expect($result->ocrVersion)->toBe('0.3.9')
        ->and($result->toArray()['ocr_version'])
        ->toBe('0.3.9');
});
