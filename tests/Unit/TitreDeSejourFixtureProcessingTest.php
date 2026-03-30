<?php

declare(strict_types=1);

use Ges\Ocr\DocumentProcessor;
use Ges\Ocr\Models\DocumentProcessing;

it('processes titre de sejour fixtures with stable expected values', function (
    string $relativePath,
    string $expectedFirstName,
    string $expectedLastName,
    string $expectedDocumentNumber,
    string $expectedDateOfBirth,
    string $expectedPostalCode,
    string $expectedCity,
    string $expectedNationality
) {
    if (env('RUN_MANUAL_OCR_TESTS') !== '1') {
        $this->markTestSkipped('Manual OCR fixture test. Set RUN_MANUAL_OCR_TESTS=1 to run it.');
    }

    $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relativePath;
    $mimeType = mime_content_type($path) ?: 'application/octet-stream';

    $result = app(DocumentProcessor::class)->processFile($path, $mimeType, basename($path));

    expect($result->status)->toBe(DocumentProcessing::STATUS_DONE)
        ->and($result->documentType)->toBe(DocumentProcessing::BUSINESS_TYPE_TITRE_DE_SEJOUR)
        ->and($result->normalizedJson)->toMatchArray([
            'document_type' => DocumentProcessing::BUSINESS_TYPE_TITRE_DE_SEJOUR,
            'first_name' => $expectedFirstName,
            'last_name' => $expectedLastName,
            'document_number' => $expectedDocumentNumber,
            'date_of_birth' => $expectedDateOfBirth,
            'postal_code' => $expectedPostalCode,
            'city' => $expectedCity,
            'nationality' => $expectedNationality,
        ]);
})->with([
    'titre de sejour pdf' => [
        'relativePath' => 'tests/Fixtures/documents/cin/cin_WE.pdf',
        'expectedFirstName' => 'Wadie',
        'expectedLastName' => 'EL ARRIM',
        'expectedDocumentNumber' => '27KBIM1FC',
        'expectedDateOfBirth' => '1989-04-06',
        'expectedPostalCode' => '95100',
        'expectedCity' => 'ARGENTEUIL',
        'expectedNationality' => 'MAR',
    ],
]);
