<?php

declare(strict_types=1);

use Ges\Ocr\DocumentNormalizationService;
use Ges\Ocr\Models\DocumentProcessing;

it('parses compact residence permit mrz and splits multiline address', function () {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate(DocumentProcessing::BUSINESS_TYPE_TITRE_DE_SEJOUR, [
        'mrz' => 'IRFRA27KBIM1FC2<9924075293<<<<8904067M2803180MAR<<<<<<<<<<<<8EL<ARRIM<<WADIE<<<<<<<<<<<<<<<<<',
        'street_address' => "9 CHE DE BARENTIN\n95100 ARGENTEUIL",
        'first_name' => '',
        'last_name' => '',
        'date_of_birth' => '',
        'expiry_date' => '',
        'nationality' => '',
        'sex' => '',
        'place_of_birth' => 'RABAT (MAR)',
        'document_number' => '',
        'postal_code' => '',
        'city' => '',
    ]);

    expect($result['normalized'])->toMatchArray([
        'document_type' => DocumentProcessing::BUSINESS_TYPE_TITRE_DE_SEJOUR,
        'first_name' => 'WADIE',
        'last_name' => 'EL ARRIM',
        'date_of_birth' => '1989-04-06',
        'expiry_date' => '2028-03-18',
        'nationality' => 'MAR',
        'sex' => 'M',
        'document_number' => '27KBIM1FC',
        'street_address' => '9 CHE DE BARENTIN',
        'postal_code' => '95100',
        'city' => 'ARGENTEUIL',
    ])->and($result['normalized']['street_address'])->not->toContain("\n")
        ->and($result['needs_review'])->toBeFalse();
});

it('removes OCR noise before parsing compact residence permit mrz', function () {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate(DocumentProcessing::BUSINESS_TYPE_TITRE_DE_SEJOUR, [
        'mrz' => 'IR<FR*A27KBIM1FC<<9924075293<<<<8904067M2803180MAR<<<<<<<<<<<<8EL<ARRIM<<WADIE<<<<<<<<<<<<<<<<<',
        'street_address' => '',
        'first_name' => '',
        'last_name' => '',
        'date_of_birth' => '',
        'expiry_date' => '',
        'nationality' => '',
        'sex' => '',
        'place_of_birth' => '',
        'document_number' => '',
        'postal_code' => '',
        'city' => '',
    ]);

    expect($result['normalized'])->toMatchArray([
        'first_name' => 'WADIE',
        'last_name' => 'EL ARRIM',
        'date_of_birth' => '1989-04-06',
        'expiry_date' => '2028-03-18',
        'nationality' => 'MAR',
        'sex' => 'M',
        'document_number' => '27KBIM1FC',
    ]);
});

it('normalizes MSA parcel rows with carry-forward, padding, section normalization and deduplication', function () {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate(DocumentProcessing::BUSINESS_TYPE_MSA, [
        'msa_parcels' => [
            [
                'dept' => '1',
                'com' => '23',
                'prefixe' => '',
                'section' => 'b',
                'numero_plan' => '45',
            ],
            [
                'dept' => '',
                'com' => '',
                'prefixe' => '7',
                'section' => 'ZD',
                'numero_plan' => '9',
            ],
            [
                'dept' => '',
                'com' => '',
                'prefixe' => '007',
                'section' => 'zd',
                'numero_plan' => '0009',
            ],
        ],
    ]);

    expect($result['normalized'])->toMatchArray([
        'document_type' => DocumentProcessing::BUSINESS_TYPE_MSA,
        'msa_parcels' => [
            [
                'dept' => '01',
                'com' => '023',
                'prefixe' => '',
                'section' => '0B',
                'numero_plan' => '0045',
            ],
            [
                'dept' => '01',
                'com' => '023',
                'prefixe' => '007',
                'section' => 'ZD',
                'numero_plan' => '0009',
            ],
        ],
    ])->and($result['needs_review'])->toBeFalse();
});
