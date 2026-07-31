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

it('corrects isolated MSA context outliers surrounded by the same valid context', function () {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate(DocumentProcessing::BUSINESS_TYPE_MSA, [
        'msa_parcels' => [
            [
                'dept' => '72',
                'com' => '294',
                'prefixe' => '',
                'section' => 'ZD',
                'numero_plan' => '0100',
            ],
            [
                'dept' => '90',
                'com' => '104',
                'prefixe' => '',
                'section' => 'ZH',
                'numero_plan' => '0055',
            ],
            [
                'dept' => '72',
                'com' => '294',
                'prefixe' => '',
                'section' => 'ZD',
                'numero_plan' => '0099',
            ],
        ],
    ]);

    expect($result['normalized']['msa_parcels'])->toMatchArray([
        [
            'dept' => '72',
            'com' => '294',
            'prefixe' => '',
            'section' => 'ZD',
            'numero_plan' => '0100',
        ],
        [
            'dept' => '72',
            'com' => '294',
            'prefixe' => '',
            'section' => 'ZH',
            'numero_plan' => '0055',
        ],
        [
            'dept' => '72',
            'com' => '294',
            'prefixe' => '',
            'section' => 'ZD',
            'numero_plan' => '0099',
        ],
    ]);

    expect($result['needs_review'])->toBeFalse();
    expect($result['errors'])->toBe([]);
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

it('normalizes MSA parcel rows with carry-forward, padding, section normalization and preserved duplicates', function () {
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
                'prefixe' => '',
                'section' => 'ZD',
                'numero_plan' => '0009',
            ],
            [
                'dept' => '01',
                'com' => '023',
                'prefixe' => '007',
                'section' => 'ZD',
                'numero_plan' => '0009',
            ],
        ],
    ]);
    expect($result['needs_review'])->toBeFalse();
    expect($result['errors'])->toBe([]);
});

it('normalizes inpi and acte de situation like company extracts', function (string $documentType) {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate($documentType, [
        'company_name' => 'GES',
        'registration_number' => '123 456 789 R.C.S. Paris',
        'sirene' => '123456789',
        'siret' => '12345678900011',
        'postal_code' => '75001',
        'city' => 'PARIS',
        'legal_representatives' => [
            [
                'entity_type' => 'person',
                'civility' => 'M',
                'first_name' => 'Jean',
                'last_name' => 'Dupont',
                'company_name' => '',
                'legal_form' => '',
                'street_address' => '',
                'postal_code' => '',
                'city' => '',
                'registration_number' => '',
                'registry_city' => '',
                'role' => 'Gérant',
            ],
        ],
    ]);

    expect($result['normalized'])->toMatchArray([
        'document_type' => $documentType,
        'company_name' => 'GES',
        'sirene' => '123456789',
        'siret' => '12345678900011',
    ])->and($result['needs_review'])->toBeFalse();
})->with([
    'inpi' => DocumentProcessing::BUSINESS_TYPE_INPI,
    'acte_de_situation' => DocumentProcessing::BUSINESS_TYPE_ACTE_DE_SITUATION,
]);

it('keeps rare but valid MSA departments when another department is dominant', function () {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate(DocumentProcessing::BUSINESS_TYPE_MSA, [
        'msa_parcels' => [
            [
                'dept' => '61',
                'com' => '165',
                'prefixe' => '',
                'section' => 'ZC',
                'numero_plan' => '0031',
            ],
            [
                'dept' => '72',
                'com' => '141',
                'prefixe' => '',
                'section' => 'ZO',
                'numero_plan' => '0087',
            ],
            [
                'dept' => '72',
                'com' => '141',
                'prefixe' => '',
                'section' => 'ZO',
                'numero_plan' => '0041',
            ],
            [
                'dept' => '72',
                'com' => '294',
                'prefixe' => '',
                'section' => 'ZH',
                'numero_plan' => '0055',
            ],
        ],
    ]);

    expect($result['normalized']['msa_parcels'])->toMatchArray([
        [
            'dept' => '61',
            'com' => '165',
            'prefixe' => '',
            'section' => 'ZC',
            'numero_plan' => '0031',
        ],
        [
            'dept' => '72',
            'com' => '141',
            'prefixe' => '',
            'section' => 'ZO',
            'numero_plan' => '0087',
        ],
        [
            'dept' => '72',
            'com' => '141',
            'prefixe' => '',
            'section' => 'ZO',
            'numero_plan' => '0041',
        ],
        [
            'dept' => '72',
            'com' => '294',
            'prefixe' => '',
            'section' => 'ZH',
            'numero_plan' => '0055',
        ],
    ]);

    expect($result['needs_review'])->toBeFalse();
    expect($result['errors'])->toBe([]);
});

it('does not pad short MSA prefix values', function () {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate(DocumentProcessing::BUSINESS_TYPE_MSA, [
        'msa_parcels' => [
            [
                'dept' => '85',
                'com' => '254',
                'prefixe' => '1',
                'section' => 'A',
                'numero_plan' => '0750',
            ],
            [
                'dept' => '85',
                'com' => '080',
                'prefixe' => '091',
                'section' => 'ZL',
                'numero_plan' => '0089',
            ],
            [
                'dept' => '49',
                'com' => '367',
                'prefixe' => '249',
                'section' => 'ZM',
                'numero_plan' => '0007',
            ],
        ],
    ]);

    expect($result['normalized']['msa_parcels'])->toMatchArray([
        [
            'dept' => '85',
            'com' => '254',
            'prefixe' => '',
            'section' => '0A',
            'numero_plan' => '0750',
        ],
        [
            'dept' => '85',
            'com' => '080',
            'prefixe' => '091',
            'section' => 'ZL',
            'numero_plan' => '0089',
        ],
        [
            'dept' => '49',
            'com' => '367',
            'prefixe' => '249',
            'section' => 'ZM',
            'numero_plan' => '0007',
        ],
    ]);

    expect($result['needs_review'])->toBeFalse();
    expect($result['errors'])->toBe([]);
});

it('removes suspicious default MSA prefix 001 on single simple-section blocks', function () {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate(DocumentProcessing::BUSINESS_TYPE_MSA, [
        'msa_parcels' => [
            ['dept' => '85', 'com' => '254', 'prefixe' => '001', 'section' => 'A', 'numero_plan' => '0750'],
            ['dept' => '85', 'com' => '254', 'prefixe' => '001', 'section' => 'A', 'numero_plan' => '0748'],
            ['dept' => '85', 'com' => '254', 'prefixe' => '001', 'section' => 'A', 'numero_plan' => '0512'],
            ['dept' => '85', 'com' => '254', 'prefixe' => '001', 'section' => 'A', 'numero_plan' => '0514'],
            ['dept' => '85', 'com' => '254', 'prefixe' => '001', 'section' => 'A', 'numero_plan' => '0516'],
        ],
    ]);

    expect($result['normalized']['msa_parcels'])->each(
        fn ($row) => $row->toHaveKey('prefixe', '')
    );

    expect($result['needs_review'])->toBeFalse();
    expect($result['errors'])->toBe([]);
});

it('restores truncated MSA plan numbers in known high-number contexts', function () {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate(DocumentProcessing::BUSINESS_TYPE_MSA, [
        'msa_parcels' => [
            ['dept' => '49', 'com' => '367', 'prefixe' => '', 'section' => 'B', 'numero_plan' => '3983'],
            ['dept' => '49', 'com' => '367', 'prefixe' => '', 'section' => 'B', 'numero_plan' => '0130'],
            ['dept' => '49', 'com' => '367', 'prefixe' => '', 'section' => 'B', 'numero_plan' => '0132'],
        ],
    ]);

    expect($result['normalized']['msa_parcels'])->toMatchArray([
        ['dept' => '49', 'com' => '367', 'prefixe' => '', 'section' => '0B', 'numero_plan' => '3983'],
        ['dept' => '49', 'com' => '367', 'prefixe' => '', 'section' => '0B', 'numero_plan' => '4130'],
        ['dept' => '49', 'com' => '367', 'prefixe' => '', 'section' => '0B', 'numero_plan' => '4132'],
    ]);

    expect($result['needs_review'])->toBeFalse();
    expect($result['errors'])->toBe([]);
});

it('clears minor suspicious MSA prefixes when one explicit prefix dominates', function () {
    $service = new DocumentNormalizationService;

    $parcels = [];

    for ($i = 1; $i <= 30; $i++) {
        $parcels[] = [
            'dept' => '49',
            'com' => '367',
            'prefixe' => '043',
            'section' => 'B',
            'numero_plan' => str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
        ];
    }

    for ($i = 1; $i <= 20; $i++) {
        $parcels[] = [
            'dept' => '49',
            'com' => '367',
            'prefixe' => '',
            'section' => 'ZK',
            'numero_plan' => str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        ];
    }

    $parcels[] = ['dept' => '49', 'com' => '367', 'prefixe' => '001', 'section' => 'ZB', 'numero_plan' => '0017'];
    $parcels[] = ['dept' => '49', 'com' => '367', 'prefixe' => '040', 'section' => 'ZM', 'numero_plan' => '0039'];
    $parcels[] = ['dept' => '49', 'com' => '367', 'prefixe' => '056', 'section' => 'ZM', 'numero_plan' => '0017'];

    $result = $service->normalizeAndValidate(DocumentProcessing::BUSINESS_TYPE_MSA, [
        'msa_parcels' => $parcels,
    ]);

    $prefixCounts = [];
    foreach ($result['normalized']['msa_parcels'] as $row) {
        $prefix = $row['prefixe'] ?? '';
        $prefixCounts[$prefix] = ($prefixCounts[$prefix] ?? 0) + 1;
    }

    expect($prefixCounts['043'] ?? 0)->toBe(30);
    expect($prefixCounts['001'] ?? 0)->toBe(0);
    expect($prefixCounts['040'] ?? 0)->toBe(0);
    expect($prefixCounts['056'] ?? 0)->toBe(0);
    expect($prefixCounts[''] ?? 0)->toBe(23);

    expect($result['needs_review'])->toBeFalse();
    expect($result['errors'])->toBe([]);
});

it('keeps a rare MSA prefix when it is confirmed by the same cadastral context', function () {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate(
        DocumentProcessing::BUSINESS_TYPE_MSA,
        [
            'msa_parcels' => [
                [
                    'dept' => '49',
                    'com' => '018',
                    'prefixe' => '372',
                    'section' => 'ZL',
                    'numero_plan' => '0051',
                ],
                [
                    'dept' => '49',
                    'com' => '018',
                    'prefixe' => '372',
                    'section' => 'ZO',
                    'numero_plan' => '0016',
                ],
                [
                    'dept' => '49',
                    'com' => '018',
                    'prefixe' => '372',
                    'section' => 'ZI',
                    'numero_plan' => '0016',
                ],
                [
                    'dept' => '49',
                    'com' => '018',
                    'prefixe' => '',
                    'section' => 'YA',
                    'numero_plan' => '0001',
                ],
                [
                    'dept' => '49',
                    'com' => '018',
                    'prefixe' => '',
                    'section' => 'YB',
                    'numero_plan' => '0002',
                ],
                [
                    'dept' => '49',
                    'com' => '018',
                    'prefixe' => '',
                    'section' => 'YC',
                    'numero_plan' => '0003',
                ],
            ],
        ]
    );

    $targetRows = array_values(array_filter(
        $result['normalized']['msa_parcels'],
        static fn (array $row): bool =>
            ($row['dept'] ?? '') === '49'
            && ($row['com'] ?? '') === '018'
            && ($row['section'] ?? '') === 'ZI'
            && ($row['numero_plan'] ?? '') === '0016'
    ));

    expect($targetRows)->toBe([
        [
            'dept' => '49',
            'com' => '018',
            'prefixe' => '372',
            'section' => 'ZI',
            'numero_plan' => '0016',
        ],
    ]);

    expect($result['needs_review'])->toBeFalse();
    expect($result['errors'])->toBe([]);
});

it('keeps homonymous MSA parcels that differ only by prefix', function () {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate(
        DocumentProcessing::BUSINESS_TYPE_MSA,
        [
            'msa_parcels' => [
                [
                    'dept' => '49',
                    'com' => '367',
                    'prefixe' => '',
                    'section' => 'B',
                    'numero_plan' => '1123',
                ],
                [
                    'dept' => '49',
                    'com' => '367',
                    'prefixe' => '043',
                    'section' => 'B',
                    'numero_plan' => '1123',
                ],
                [
                    'dept' => '49',
                    'com' => '367',
                    'prefixe' => '043',
                    'section' => 'B',
                    'numero_plan' => '1101',
                ],
                [
                    'dept' => '49',
                    'com' => '367',
                    'prefixe' => '043',
                    'section' => 'B',
                    'numero_plan' => '1102',
                ],
                [
                    'dept' => '49',
                    'com' => '367',
                    'prefixe' => '',
                    'section' => 'ZK',
                    'numero_plan' => '0001',
                ],
            ],
        ]
    );

    $targetRows = array_values(array_filter(
        $result['normalized']['msa_parcels'],
        static fn (array $row): bool =>
            ($row['dept'] ?? '') === '49'
            && ($row['com'] ?? '') === '367'
            && ($row['section'] ?? '') === '0B'
            && ($row['numero_plan'] ?? '') === '1123'
    ));

    usort(
        $targetRows,
        static fn (array $left, array $right): int =>
            ($left['prefixe'] ?? '') <=> ($right['prefixe'] ?? '')
    );

    expect($targetRows)->toBe([
        [
            'dept' => '49',
            'com' => '367',
            'prefixe' => '',
            'section' => '0B',
            'numero_plan' => '1123',
        ],
        [
            'dept' => '49',
            'com' => '367',
            'prefixe' => '043',
            'section' => '0B',
            'numero_plan' => '1123',
        ],
    ]);

    expect($result['needs_review'])->toBeFalse();
    expect($result['errors'])->toBe([]);
});

it('keeps a valid isolated MSA commune confirmed by a distinct parcel occurrence', function () {
    $service = app(DocumentNormalizationService::class);

    $result = $service->normalizeAndValidate(
        DocumentProcessing::BUSINESS_TYPE_MSA,
        [
            'document_type' => DocumentProcessing::BUSINESS_TYPE_MSA,
            'msa_parcels' => [
                [
                    'dept' => '72',
                    'com' => '083',
                    'prefixe' => '',
                    'section' => 'ZS',
                    'numero_plan' => '0029',
                ],
                [
                    'dept' => '72',
                    'com' => '083',
                    'prefixe' => '',
                    'section' => 'ZR',
                    'numero_plan' => '0002',
                ],
                [
                    'dept' => '72',
                    'com' => '083',
                    'prefixe' => '',
                    'section' => 'ZR',
                    'numero_plan' => '0003',
                ],
                [
                    'dept' => '72',
                    'com' => '083',
                    'prefixe' => '',
                    'section' => 'ZS',
                    'numero_plan' => '0033',
                ],
                [
                    'dept' => '72',
                    'com' => '050',
                    'prefixe' => '',
                    'section' => 'ZX',
                    'numero_plan' => '0023',
                ],
                [
                    'dept' => '72',
                    'com' => '083',
                    'prefixe' => '',
                    'section' => 'ZZ',
                    'numero_plan' => '0004',
                ],
                [
                    'dept' => '72',
                    'com' => '083',
                    'prefixe' => '',
                    'section' => 'ZZ',
                    'numero_plan' => '0018',
                ],
                [
                    'dept' => '72',
                    'com' => '083',
                    'prefixe' => '',
                    'section' => 'ZS',
                    'numero_plan' => '0003',
                ],
            ],
        ]
    );

    $references = array_map(
        static fn (array $row): string => implode('/', [
            $row['dept'],
            $row['com'],
            $row['prefixe'] === '' ? '000' : $row['prefixe'],
            $row['section'],
            $row['numero_plan'],
        ]),
        $result['normalized']['msa_parcels']
    );

    expect($references)->toContain(
        '72/050/000/ZX/0023'
    );

    expect($references)->not->toContain(
        '72/083/000/ZX/0023'
    );
});

it('keeps an isolated explicit MSA prefix in a distinct cadastral context', function () {
    $service = new DocumentNormalizationService;

    $parcels = [];

    /*
     * Préfixe dominant dans un autre contexte cadastral.
     */
    for ($index = 1; $index <= 20; $index++) {
        $parcels[] = [
            'dept' => '72',
            'com' => '025',
            'prefixe' => '108',
            'section' => 'ZC',
            'numero_plan' => str_pad(
                (string) $index,
                4,
                '0',
                STR_PAD_LEFT
            ),
        ];
    }

    /*
     * Préfixe minoritaire, mais unique et explicite dans son propre
     * contexte DEPT/COM. Il ne doit pas être effacé uniquement parce
     * qu'un autre préfixe domine le document.
     */
    $parcels[] = [
        'dept' => '49',
        'com' => '018',
        'prefixe' => '143',
        'section' => 'ZE',
        'numero_plan' => '0114',
    ];

    /*
     * Présence d'une ligne sans préfixe afin d'activer le nettoyage
     * des préfixes minoritaires.
     */
    $parcels[] = [
        'dept' => '72',
        'com' => '154',
        'prefixe' => '',
        'section' => 'YT',
        'numero_plan' => '0001',
    ];

    $result = $service->normalizeAndValidate(
        DocumentProcessing::BUSINESS_TYPE_MSA,
        [
            'msa_parcels' => $parcels,
        ]
    );

    $targetRows = array_values(array_filter(
        $result['normalized']['msa_parcels'],
        static fn (array $row): bool =>
            ($row['dept'] ?? '') === '49'
            && ($row['com'] ?? '') === '018'
            && ($row['section'] ?? '') === 'ZE'
            && ($row['numero_plan'] ?? '') === '0114'
    ));

    expect($targetRows)->toBe([
        [
            'dept' => '49',
            'com' => '018',
            'prefixe' => '143',
            'section' => 'ZE',
            'numero_plan' => '0114',
        ],
    ]);

    expect($result['needs_review'])->toBeFalse();
    expect($result['errors'])->toBe([]);
});

it('drops a prefix-equals-commune duplicate when the exact unprefixed parcel exists', function () {
    $service = new DocumentNormalizationService;

    $result = $service->normalizeAndValidate(
        DocumentProcessing::BUSINESS_TYPE_MSA,
        [
            'msa_parcels' => [
                [
                    'dept' => '49',
                    'com' => '099',
                    'prefixe' => '',
                    'section' => 'CO',
                    'numero_plan' => '0216',
                ],
                [
                    'dept' => '49',
                    'com' => '099',
                    'prefixe' => '099',
                    'section' => 'CO',
                    'numero_plan' => '0216',
                ],
                [
                    'dept' => '49',
                    'com' => '099',
                    'prefixe' => '',
                    'section' => 'EM',
                    'numero_plan' => '0126',
                ],
                [
                    'dept' => '49',
                    'com' => '099',
                    'prefixe' => '',
                    'section' => 'ZD',
                    'numero_plan' => '0044',
                ],
                [
                    'dept' => '49',
                    'com' => '099',
                    'prefixe' => '',
                    'section' => 'ZA',
                    'numero_plan' => '0001',
                ],
            ],
        ]
    );

    $targetRows = array_values(array_filter(
        $result['normalized']['msa_parcels'],
        static fn (array $row): bool =>
            ($row['dept'] ?? '') === '49'
            && ($row['com'] ?? '') === '099'
            && ($row['section'] ?? '') === 'CO'
            && ($row['numero_plan'] ?? '') === '0216'
    ));

    expect($targetRows)->toBe([
        [
            'dept' => '49',
            'com' => '099',
            'prefixe' => '',
            'section' => 'CO',
            'numero_plan' => '0216',
        ],
    ]);

    expect($result['needs_review'])->toBeFalse();
    expect($result['errors'])->toBe([]);
});

it('clears MSA prefixes copied from plan numbers when the anomaly repeats in one cadastral context', function () {
    $service = new \Ges\Ocr\DocumentNormalizationService;

    $result = $service->normalizeAndValidate(
        \Ges\Ocr\Support\DocumentProcessingValues::BUSINESS_TYPE_MSA,
        [
            'msa_parcels' => [
                [
                    'dept' => '85',
                    'com' => '289',
                    'prefixe' => '185',
                    'section' => '0B',
                    'numero_plan' => '0185',
                ],
                [
                    'dept' => '85',
                    'com' => '289',
                    'prefixe' => '186',
                    'section' => '0B',
                    'numero_plan' => '0186',
                ],
                [
                    'dept' => '85',
                    'com' => '289',
                    'prefixe' => '114',
                    'section' => '0B',
                    'numero_plan' => '1145',
                ],
            ],
        ]
    );

    expect($result['normalized']['msa_parcels'])->toBe([
        [
            'dept' => '85',
            'com' => '289',
            'prefixe' => '',
            'section' => '0B',
            'numero_plan' => '0185',
        ],
        [
            'dept' => '85',
            'com' => '289',
            'prefixe' => '',
            'section' => '0B',
            'numero_plan' => '0186',
        ],
        [
            'dept' => '85',
            'com' => '289',
            'prefixe' => '',
            'section' => '0B',
            'numero_plan' => '1145',
        ],
    ]);
});

it('keeps an isolated explicit MSA prefix that happens to match part of its plan number', function () {
    $service = new \Ges\Ocr\DocumentNormalizationService;

    $result = $service->normalizeAndValidate(
        \Ges\Ocr\Support\DocumentProcessingValues::BUSINESS_TYPE_MSA,
        [
            'msa_parcels' => [
                [
                    'dept' => '49',
                    'com' => '183',
                    'prefixe' => '038',
                    'section' => '0F',
                    'numero_plan' => '0385',
                ],
            ],
        ]
    );

    expect($result['normalized']['msa_parcels'])->toBe([
        [
            'dept' => '49',
            'com' => '183',
            'prefixe' => '038',
            'section' => '0F',
            'numero_plan' => '0385',
        ],
    ]);
});

it('does not combine number-derived MSA prefix evidence across cadastral contexts', function () {
    $service = new \Ges\Ocr\DocumentNormalizationService;

    $result = $service->normalizeAndValidate(
        \Ges\Ocr\Support\DocumentProcessingValues::BUSINESS_TYPE_MSA,
        [
            'msa_parcels' => [
                [
                    'dept' => '49',
                    'com' => '183',
                    'prefixe' => '038',
                    'section' => '0F',
                    'numero_plan' => '0385',
                ],
                [
                    'dept' => '49',
                    'com' => '183',
                    'prefixe' => '052',
                    'section' => '0F',
                    'numero_plan' => '0525',
                ],
                [
                    'dept' => '85',
                    'com' => '289',
                    'prefixe' => '185',
                    'section' => '0B',
                    'numero_plan' => '0185',
                ],
            ],
        ]
    );

    expect(array_map(
        static fn (array $row): string =>
            (string) $row['prefixe'],
        $result['normalized']['msa_parcels']
    ))->toBe([
        '038',
        '052',
        '185',
    ]);
});
