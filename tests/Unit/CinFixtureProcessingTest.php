<?php

declare(strict_types=1);

use Ges\Ocr\DocumentProcessor;
use Ges\Ocr\Models\DocumentProcessing;

it('processes CIN fixtures with stable expected values', function (
    string $relativePath,
    string $expectedFirstName,
    string $expectedLastName,
    string $expectedDocumentNumber,
    string $expectedDateOfBirth,
    string $expectedPostalCode,
    string $expectedCity
) {
    if (env('RUN_MANUAL_OCR_TESTS') !== '1') {
        $this->markTestSkipped('Manual OCR fixture test. Set RUN_MANUAL_OCR_TESTS=1 to run it.');
    }

    $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relativePath;
    $mimeType = mime_content_type($path) ?: 'application/octet-stream';

    $result = app(DocumentProcessor::class)->processFile($path, $mimeType, basename($path));

    expect($result->status)->toBe(DocumentProcessing::STATUS_DONE)
        ->and($result->documentType)->toBe(DocumentProcessing::BUSINESS_TYPE_CIN)
        ->and($result->normalizedJson)->toMatchArray([
            'document_type' => DocumentProcessing::BUSINESS_TYPE_CIN,
            'first_name' => $expectedFirstName,
            'last_name' => $expectedLastName,
            'document_number' => $expectedDocumentNumber,
            'date_of_birth' => $expectedDateOfBirth,
            'postal_code' => $expectedPostalCode,
            'city' => $expectedCity,
        ]);
})->with([
    'cin webp' => [
        'relativePath' => 'tests/Fixtures/documents/cin/cin.webp',
        'expectedFirstName' => 'Maëlis-Gaëlle, Marie',
        'expectedLastName' => 'Martin',
        'expectedDocumentNumber' => 'G3Y6ZDRC9',
        'expectedDateOfBirth' => '1990-07-13',
        'expectedPostalCode' => '',
        'expectedCity' => '',
    ],
    'cnie image' => [
        'relativePath' => 'tests/Fixtures/documents/cin/cnie.jpg',
        'expectedFirstName' => 'Maëlys-Gaëlle, Marie',
        'expectedLastName' => 'Martin',
        'expectedDocumentNumber' => 'X4RTBPFW4',
        'expectedDateOfBirth' => '1990-07-13',
        'expectedPostalCode' => '33000',
        'expectedCity' => 'Bordeaux',
    ],
    'cin thierry pdf' => [
        'relativePath' => 'tests/Fixtures/documents/cin/cin_Thierry Gaurenne.pdf',
        'expectedFirstName' => 'Thierry Claude François',
        'expectedLastName' => 'GAURENNE',
        'expectedDocumentNumber' => 'N9EB26TJ7',
        'expectedDateOfBirth' => '1964-09-14',
        'expectedPostalCode' => '66210',
        'expectedCity' => 'SAINT-PIERRE-DELS-FORCATS',
    ],
]);
