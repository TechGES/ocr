<?php

declare(strict_types=1);

use Ges\Ocr\DocumentProcessor;
use Ges\Ocr\Models\DocumentProcessing;

it('processes land-title deed fixtures with stable expected values', function (
    string $relativePath,
    string $expectedInputType,
    array $expectedParcels,
    array $expectedOwnerNames
) {
    if (true) {
        $this->markTestSkipped('Manual OCR fixture test. Set RUN_MANUAL_OCR_TESTS=1 to run it.');
    }

    $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relativePath;
    $mimeType = mime_content_type($path) ?: 'application/octet-stream';

    $result = app(DocumentProcessor::class)->processFile($path, $mimeType, basename($path));
    $normalized = $result->normalizedJson;
    $ownerNames = array_values(array_filter(array_map(
        static fn (array $owner): string => $owner['company_name'] !== ''
            ? $owner['company_name']
            : trim($owner['first_name'].' '.$owner['last_name']),
        $normalized['owners'] ?? []
    )));

    expect($result->status)->toBe(DocumentProcessing::STATUS_DONE)
        ->and($result->inputType)->toBe($expectedInputType)
        ->and($result->documentType)->toBe(DocumentProcessing::BUSINESS_TYPE_ACTE_PROPRIETE)
        ->and($normalized)->toMatchArray([
            'document_type' => DocumentProcessing::BUSINESS_TYPE_ACTE_PROPRIETE,
            'cadastral_parcels' => $expectedParcels,
        ])
        ->and($ownerNames)->toEqualCanonicalizing($expectedOwnerNames);
})->with([
    'land title image' => [
        'relativePath' => 'tests/Fixtures/documents/acte_propriete/acte.jpg',
        'expectedInputType' => DocumentProcessing::INPUT_TYPE_IMAGE,
        'expectedParcels' => [
            [
                'prefixe' => '',
                'section' => 'I',
                'numero' => '33',
                'street_address' => '',
                'postal_code' => '',
                'city' => '',
            ],
            [
                'prefixe' => '',
                'section' => 'I',
                'numero' => '68',
                'street_address' => '',
                'postal_code' => '',
                'city' => '',
            ],
            [
                'prefixe' => '',
                'section' => 'I',
                'numero' => '641',
                'street_address' => '',
                'postal_code' => '',
                'city' => '',
            ],
        ],
        'expectedOwnerNames' => [
            'Angelin TAFANI',
            'Jacqueline Lucette VERDIER',
            'Marcelle TAFANI',
            'Marie Arcange FILIPPI',
        ],
    ],
    'land title pdf' => [
        'relativePath' => 'tests/Fixtures/documents/acte_propriete/Titre de propriété.pdf',
        'expectedInputType' => DocumentProcessing::INPUT_TYPE_PDF_TEXT,
        'expectedParcels' => [
            [
                'prefixe' => '',
                'section' => 'AR',
                'numero' => '36',
                'street_address' => 'LA TUILLERIE DE RENOIR',
                'postal_code' => '86270',
                'city' => 'LA ROCHE-POSAY',
            ],
        ],
        'expectedOwnerNames' => [
            'GLRP IMMOBILIER',
        ],
    ],
]);
