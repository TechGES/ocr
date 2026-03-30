<?php

declare(strict_types=1);

use Ges\Ocr\DocumentProcessor;
use Ges\Ocr\Models\DocumentProcessing;

it('processes KBIS fixtures with stable expected values', function (
    string $relativePath,
    string $expectedInputType,
    string $expectedCompanyName,
    string $expectedRegistrationNumber,
    string $expectedSirene,
    string $expectedRegistrationDate,
    string $expectedRegistryCity,
    string $expectedPostalCode,
    string $expectedCity,
    array $expectedRepresentativeNames
) {
    if (env('RUN_MANUAL_OCR_TESTS') !== '1') {
        $this->markTestSkipped('Manual OCR fixture test. Set RUN_MANUAL_OCR_TESTS=1 to run it.');
    }

    $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relativePath;
    $mimeType = mime_content_type($path) ?: 'application/octet-stream';

    $result = app(DocumentProcessor::class)->processFile($path, $mimeType, basename($path));
    $normalized = $result->normalizedJson;
    $representativeNames = array_values(array_filter(array_map(
        static fn (array $representative): string => $representative['company_name'] !== ''
            ? $representative['company_name']
            : trim($representative['first_name'].' '.$representative['last_name']),
        $normalized['legal_representatives'] ?? []
    )));

    expect($result->status)->toBe(DocumentProcessing::STATUS_DONE)
        ->and($result->inputType)->toBe($expectedInputType)
        ->and($result->documentType)->toBe(DocumentProcessing::BUSINESS_TYPE_KBIS)
        ->and($normalized)->toMatchArray([
            'document_type' => DocumentProcessing::BUSINESS_TYPE_KBIS,
            'company_name' => $expectedCompanyName,
            'registration_number' => $expectedRegistrationNumber,
            'sirene' => $expectedSirene,
            'registration_date' => $expectedRegistrationDate,
            'registry_city' => $expectedRegistryCity,
            'postal_code' => $expectedPostalCode,
            'city' => $expectedCity,
        ])
        ->and($representativeNames)->toEqualCanonicalizing($expectedRepresentativeNames);
})->with([
    'conibi pdf' => [
        'relativePath' => 'tests/Fixtures/documents/kbis/conibi.pdf',
        'expectedInputType' => DocumentProcessing::INPUT_TYPE_PDF_TEXT,
        'expectedCompanyName' => 'CONIBI',
        'expectedRegistrationNumber' => '429 225 683 R.C.S. Bobigny',
        'expectedSirene' => '429225683',
        'expectedRegistrationDate' => '2000-01-27',
        'expectedRegistryCity' => 'Bobigny',
        'expectedPostalCode' => '93420',
        'expectedCity' => 'Villepinte',
        'expectedRepresentativeNames' => ['Gabriel Simone Olivier', 'PLC CONSEIL'],
    ],
    'ges pdf' => [
        'relativePath' => 'tests/Fixtures/documents/kbis/ges_kbis.pdf',
        'expectedInputType' => DocumentProcessing::INPUT_TYPE_PDF_TEXT,
        'expectedCompanyName' => 'GREEN ENERGY SERVICE',
        'expectedRegistrationNumber' => '387 931 694 R.C.S. Paris',
        'expectedSirene' => '387931694',
        'expectedRegistrationDate' => '1992-07-02',
        'expectedRegistryCity' => 'Paris',
        'expectedPostalCode' => '75017',
        'expectedCity' => 'Paris',
        'expectedRepresentativeNames' => ['MM Invest', 'UNITED ELECTRIC', 'CABINET BERNARD RIVOIRE'],
    ],
    'infonet pdf' => [
        'relativePath' => 'tests/Fixtures/documents/kbis/infonet.pdf',
        'expectedInputType' => DocumentProcessing::INPUT_TYPE_PDF_SCAN,
        'expectedCompanyName' => 'INFONET',
        'expectedRegistrationNumber' => '123 456 789 R.C.S. Paris',
        'expectedSirene' => '123456789',
        'expectedRegistrationDate' => '2014-09-16',
        'expectedRegistryCity' => 'Paris',
        'expectedPostalCode' => '75008',
        'expectedCity' => 'Paris',
        'expectedRepresentativeNames' => ['JULIEN DUPÉ'],
    ],
]);
