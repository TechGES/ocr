<?php

declare(strict_types=1);

use Ges\Ocr\Contracts\LlmClient;
use Ges\Ocr\DocumentSchemaFactory;
use Ges\Ocr\OpenAiDocumentAnalyzer;
use Ges\Ocr\Support\DocumentProcessingValues;

it('analyzes a document in one structured openai request', function () {
    config()->set('ges-ocr.ai.provider', 'openai');
    config()->set('ges-ocr.openai.text_model', 'gpt-4.1-mini');

    $schemaFactory = new DocumentSchemaFactory;
    $client = Mockery::mock(LlmClient::class);
    $client->shouldReceive('chatStructured')
        ->once()
        ->andReturnUsing(function (string $model, array $messages, array $schema): array {
            expect($model)->toBe('gpt-4.1-mini')
                ->and($messages[0]['content'] ?? '')->toContain('Fais en une seule passe la classification et l extraction structuree')
                ->and($messages[0]['content'] ?? '')->toContain('kbis, inpi, acte_de_situation')
                ->and($schema['properties'])->toHaveKey('extracted_data')
                ->and($schema['properties']['extracted_data']['properties'])->toHaveKeys([
                    'first_name',
                    'company_name',
                    'legal_representatives',
                    'cadastral_parcels',
                    'owners',
                    'msa_parcels',
                ]);

            return [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_KBIS,
                'confidence' => 0.93,
                'review_reason' => 'Mentions RCS et representants legaux visibles.',
                'extracted_data' => [
                    'document_type' => DocumentProcessingValues::BUSINESS_TYPE_KBIS,
                    'first_name' => '',
                    'last_name' => '',
                    'date_of_birth' => '',
                    'place_of_birth' => '',
                    'document_number' => '',
                    'expiry_date' => '',
                    'nationality' => '',
                    'sex' => '',
                    'mrz' => '',
                    'street_address' => '',
                    'postal_code' => '',
                    'city' => '',
                    'company_name' => 'GES',
                    'trade_name' => '',
                    'legal_form' => '',
                    'capital' => '',
                    'registration_number' => '123 456 789 R.C.S. Paris',
                    'siret' => '',
                    'sirene' => '123456789',
                    'naf_code' => '',
                    'registration_date' => '',
                    'issue_date' => '',
                    'registry_city' => '',
                    'legal_representatives' => [],
                    'cadastral_parcels' => [],
                    'owners' => [],
                    'msa_parcels' => [],
                ],
            ];
        });

    $analyzer = new OpenAiDocumentAnalyzer($client, $schemaFactory);
    $result = $analyzer->analyzeText('Extrait KBIS ...');

    expect($result['classification'])->toMatchArray([
        'document_type' => DocumentProcessingValues::BUSINESS_TYPE_KBIS,
        'confidence' => 0.93,
    ])
        ->and($result['extraction']['document_type'])->toBe(DocumentProcessingValues::BUSINESS_TYPE_KBIS)
        ->and($result['extraction']['registration_number'])->toBe('123 456 789 R.C.S. Paris');
});

it('extracts simple-letter MSA sections from text fallback', function () {
    $analyzer = new OpenAiDocumentAnalyzer(
        Mockery::mock(\Ges\Ocr\Contracts\LlmClient::class),
        new DocumentSchemaFactory
    );

    $parcels = $analyzer->extractMsaTextParcels(<<<'TEXT'
49 036 A 00014 0                      B 0146                      057
                                         B 0148                      047
                                         B 0150                      05P
49 036 C 00135                        C 0035                      037
TEXT);

    $parcelB0146 = array_values(array_filter(
        $parcels,
        static fn (array $parcel): bool =>
            ($parcel['dept'] ?? '') === '49'
            && ($parcel['com'] ?? '') === '036'
            && ($parcel['section'] ?? '') === '0B'
            && ($parcel['numero_plan'] ?? '') === '0146'
    ));

    $parcelC0035 = array_values(array_filter(
        $parcels,
        static fn (array $parcel): bool =>
            ($parcel['dept'] ?? '') === '49'
            && ($parcel['com'] ?? '') === '036'
            && ($parcel['section'] ?? '') === '0C'
            && ($parcel['numero_plan'] ?? '') === '0035'
    ));

    expect($parcelB0146)->not->toBeEmpty();
    expect($parcelC0035)->not->toBeEmpty();
});

it('extracts MSA rows with an owner marker before a visible prefix', function () {
    $analyzer = new OpenAiDocumentAnalyzer(
        Mockery::mock(LlmClient::class),
        new DocumentSchemaFactory
    );

    $parcels = $analyzer->extractMsaTextParcels(<<<'TEXT'
85 146 + 00439 O                  224 AE 0008                  02 P
85 146 + 01239 O                  224 AE 0101                  02 P
85 146 L 00503 O                  224      G 0414              02 T
TEXT);

    expect($parcels)->toContain([
        'dept' => '85',
        'com' => '146',
        'prefixe' => '224',
        'section' => 'AE',
        'numero_plan' => '0008',
    ]);

    expect($parcels)->toContain([
        'dept' => '85',
        'com' => '146',
        'prefixe' => '224',
        'section' => 'AE',
        'numero_plan' => '0101',
    ]);

    expect($parcels)->toContain([
        'dept' => '85',
        'com' => '146',
        'prefixe' => '224',
        'section' => '0G',
        'numero_plan' => '0414',
    ]);
});

it('prefers deterministic MSA text parcels over noisy OpenAI parcels', function () {
    config()->set('ges-ocr.ai.provider', 'openai');
    config()->set('ges-ocr.openai.text_model', 'gpt-4.1-mini');

    $client = Mockery::mock(LlmClient::class);

    $client->shouldReceive('chatStructured')
        ->once()
        ->andReturn([
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
            'confidence' => 0.99,
            'review_reason' => '',
            'extracted_data' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'msa_parcels' => [
                    [
                        'dept' => '90',
                        'com' => '104',
                        'prefixe' => '',
                        'section' => 'ZH',
                        'numero_plan' => '0055',
                    ],
                ],
            ],
        ]);

    $analyzer = new OpenAiDocumentAnalyzer(
        $client,
        new DocumentSchemaFactory
    );

    $result = $analyzer->analyzeText(
        "72 294 B 00269 ZH 0055 03 P\n"
    );

    expect($result['extraction']['msa_parcels'])->toBe([
        [
            'dept' => '72',
            'com' => '294',
            'prefixe' => '',
            'section' => 'ZH',
            'numero_plan' => '0055',
        ],
    ]);
});

it('merges trusted OpenAI MSA parcels missing from deterministic text in a known context', function () {
    config()->set('ges-ocr.ai.provider', 'openai');
    config()->set('ges-ocr.openai.text_model', 'gpt-4.1-mini');

    $client = Mockery::mock(LlmClient::class);

    $client->shouldReceive('chatStructured')
        ->once()
        ->andReturn([
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
            'confidence' => 0.99,
            'review_reason' => '',
            'extracted_data' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'msa_parcels' => [
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
                        'section' => 'ZC',
                        'numero_plan' => '0041',
                    ],
                    [
                        'dept' => '90',
                        'com' => '104',
                        'prefixe' => '',
                        'section' => 'ZK',
                        'numero_plan' => '0001',
                    ],
                ],
            ],
        ]);

    $analyzer = new OpenAiDocumentAnalyzer(
        $client,
        new DocumentSchemaFactory
    );

    $result = $analyzer->analyzeText(
        "72 294 B 00269 ZH 0055 03 P\n"
    );

    expect($result['extraction']['msa_parcels'])->toBe([
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
            'section' => 'ZC',
            'numero_plan' => '0041',
        ],
    ]);
});

it('keeps homonymous MSA parcels when their explicit prefixes differ', function () {
    config()->set('ges-ocr.ai.provider', 'openai');
    config()->set('ges-ocr.openai.text_model', 'gpt-4.1-mini');

    $client = Mockery::mock(LlmClient::class);

    $client->shouldReceive('chatStructured')
        ->once()
        ->andReturn([
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
            'confidence' => 0.99,
            'review_reason' => '',
            'extracted_data' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'msa_parcels' => [
                    [
                        'dept' => '49',
                        'com' => '367',
                        'prefixe' => '043',
                        'section' => 'ZI',
                        'numero_plan' => '1123',
                    ],
                ],
            ],
        ]);

    $analyzer = new OpenAiDocumentAnalyzer(
        $client,
        new DocumentSchemaFactory
    );

    $result = $analyzer->analyzeText(
        "49 367 C 00449 ZI 1123 02 T\n"
    );

    expect($result['extraction']['msa_parcels'])->toBe([
        [
            'dept' => '49',
            'com' => '367',
            'prefixe' => '',
            'section' => 'ZI',
            'numero_plan' => '1123',
        ],
        [
            'dept' => '49',
            'com' => '367',
            'prefixe' => '043',
            'section' => 'ZI',
            'numero_plan' => '1123',
        ],
    ]);
});

it('preserves MSA plan numbers beginning with four in deterministic text', function () {
    $analyzer = new OpenAiDocumentAnalyzer(
        Mockery::mock(LlmClient::class),
        new DocumentSchemaFactory
    );

    $parcels = $analyzer->extractMsaTextParcels(<<<'TEXT'
49 367 + 00301 B 4130 03 P
B 4132 03 P
TEXT);

    expect($parcels)->toContain([
        'dept' => '49',
        'com' => '367',
        'prefixe' => '',
        'section' => '0B',
        'numero_plan' => '4130',
    ]);

    expect($parcels)->toContain([
        'dept' => '49',
        'com' => '367',
        'prefixe' => '',
        'section' => '0B',
        'numero_plan' => '4132',
    ]);
});

it('preserves MSA plan numbers beginning with four from OpenAI vision data', function () {
    config()->set('ges-ocr.ai.provider', 'openai');
    config()->set('ges-ocr.openai.text_model', 'gpt-4.1-mini');

    $client = Mockery::mock(LlmClient::class);

    $client->shouldReceive('chatStructured')
        ->once()
        ->andReturn([
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
            'confidence' => 0.99,
            'review_reason' => '',
            'extracted_data' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'msa_parcels' => [
                    [
                        'dept' => '49',
                        'com' => '367',
                        'prefixe' => '',
                        'section' => 'B',
                        'numero_plan' => '4130',
                    ],
                    [
                        'dept' => '49',
                        'com' => '367',
                        'prefixe' => '',
                        'section' => 'B',
                        'numero_plan' => '4132',
                    ],
                ],
            ],
        ]);

    $analyzer = new OpenAiDocumentAnalyzer(
        $client,
        new DocumentSchemaFactory
    );

    $result = $analyzer->analyzeText(
        'MSA sans ligne cadastrale textuelle exploitable'
    );

    expect($result['extraction']['msa_parcels'])->toBe([
        [
            'dept' => '49',
            'com' => '367',
            'prefixe' => '',
            'section' => '0B',
            'numero_plan' => '4130',
        ],
        [
            'dept' => '49',
            'com' => '367',
            'prefixe' => '',
            'section' => '0B',
            'numero_plan' => '4132',
        ],
    ]);
});

it('only adds vision MSA parcels corroborated by the source text', function () {
    config()->set('ges-ocr.ai.provider', 'openai');
    config()->set('ges-ocr.openai.vision_model', 'gpt-4.1');

    $client = Mockery::mock(LlmClient::class);

    $client->shouldReceive('chatStructured')
        ->once()
        ->andReturn([
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
            'confidence' => 0.99,
            'review_reason' => '',
            'extracted_data' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'msa_parcels' => [
                    // Ligne Vision correcte, absente du résultat déterministe,
                    // mais explicitement visible dans le texte source.
                    [
                        'dept' => '49',
                        'com' => '367',
                        'prefixe' => '043',
                        'section' => 'B',
                        'numero_plan' => '1123',
                    ],

                    // Faux préfixe : le texte contient ZB 0013 sans 043.
                    [
                        'dept' => '49',
                        'com' => '367',
                        'prefixe' => '043',
                        'section' => 'ZB',
                        'numero_plan' => '0013',
                    ],

                    // Mauvaise section : le texte contient YA 0091.
                    [
                        'dept' => '49',
                        'com' => '089',
                        'prefixe' => '',
                        'section' => 'VA',
                        'numero_plan' => '0091',
                    ],

                    // Valeur issue d’un total, absente des lignes cadastrales.
                    [
                        'dept' => '49',
                        'com' => '367',
                        'prefixe' => '',
                        'section' => 'B',
                        'numero_plan' => '1083',
                    ],
                ],
            ],
        ]);

    $analyzer = new OpenAiDocumentAnalyzer(
        $client,
        new DocumentSchemaFactory
    );

    $imagePath = tempnam(sys_get_temp_dir(), 'id3202-');

    if ($imagePath === false) {
        throw new RuntimeException('Unable to create temporary image fixture.');
    }

    file_put_contents($imagePath, 'fake-image-content');

    try {
        $result = $analyzer->analyzeMsaImagesPageByPage(
            [$imagePath],
            [
                [
                    'dept' => '49',
                    'com' => '367',
                    'prefixe' => '',
                    'section' => 'ZB',
                    'numero_plan' => '0013',
                ],
                [
                    'dept' => '49',
                    'com' => '089',
                    'prefixe' => '',
                    'section' => 'YA',
                    'numero_plan' => '0091',
                ],
            ],
            <<<'TEXT'
49 367 + 00318 043 B 1123 03 T
49 367 C 00385 ZB 0013 02 T
49 089 G 00106 O YA 0091 A 03 T
* TOTAL COMMUNE 1083354
TEXT
        );
    } finally {
        @unlink($imagePath);
    }

    expect($result['extraction']['msa_parcels'])->toBe([
        [
            'dept' => '49',
            'com' => '367',
            'prefixe' => '',
            'section' => 'ZB',
            'numero_plan' => '0013',
        ],
        [
            'dept' => '49',
            'com' => '089',
            'prefixe' => '',
            'section' => 'YA',
            'numero_plan' => '0091',
        ],
        [
            'dept' => '49',
            'com' => '367',
            'prefixe' => '043',
            'section' => '0B',
            'numero_plan' => '1123',
        ],
    ]);
});

it('does not corroborate a vision prefix from an owner account number', function () {
    config()->set('ges-ocr.ai.provider', 'openai');
    config()->set('ges-ocr.openai.vision_model', 'gpt-4.1-mini');

    $client = Mockery::mock(LlmClient::class);

    $client->shouldReceive('chatStructured')
        ->once()
        ->andReturn([
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
            'confidence' => 0.99,
            'review_reason' => '',
            'extracted_data' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'msa_parcels' => [
                    [
                        'dept' => '85',
                        'com' => '151',
                        'prefixe' => '001',
                        'section' => 'A',
                        'numero_plan' => '0561',
                    ],
                ],
            ],
        ]);

    $analyzer = new OpenAiDocumentAnalyzer(
        $client,
        new DocumentSchemaFactory
    );

    $imagePath = tempnam(sys_get_temp_dir(), 'id3202-');

    if ($imagePath === false) {
        throw new RuntimeException('Unable to create temporary image fixture.');
    }

    file_put_contents($imagePath, 'fake-image-content');

    try {
        $result = $analyzer->analyzeMsaImagesPageByPage(
            [$imagePath],
            [
                [
                    'dept' => '85',
                    'com' => '151',
                    'prefixe' => '',
                    'section' => '0A',
                    'numero_plan' => '0561',
                ],
            ],
            <<<'TEXT'
85 151 + 00184 O A 0561 03 T
A 0562 02 P
TEXT
        );
    } finally {
        @unlink($imagePath);
    }

    expect($result['extraction']['msa_parcels'])->toBe([
        [
            'dept' => '85',
            'com' => '151',
            'prefixe' => '',
            'section' => '0A',
            'numero_plan' => '0561',
        ],
    ]);
});

it('rejects a vision MSA parcel corroborated under another cadastral context', function () {
    config()->set('ges-ocr.ai.provider', 'openai');
    config()->set('ges-ocr.openai.vision_model', 'gpt-4.1-mini');

    $client = Mockery::mock(LlmClient::class);

    $client->shouldReceive('chatStructured')
        ->once()
        ->andReturn([
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
            'confidence' => 0.99,
            'review_reason' => '',
            'extracted_data' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'msa_parcels' => [
                    [
                        'dept' => '85',
                        'com' => '198',
                        'prefixe' => '',
                        'section' => 'A',
                        'numero_plan' => '0562',
                    ],
                ],
            ],
        ]);

    $analyzer = new OpenAiDocumentAnalyzer(
        $client,
        new DocumentSchemaFactory
    );

    $imagePath = tempnam(sys_get_temp_dir(), 'id3202-');

    if ($imagePath === false) {
        throw new RuntimeException('Unable to create temporary image fixture.');
    }

    file_put_contents($imagePath, 'fake-image-content');

    try {
        $result = $analyzer->analyzeMsaImagesPageByPage(
            [$imagePath],
            [
                [
                    'dept' => '85',
                    'com' => '151',
                    'prefixe' => '',
                    'section' => '0A',
                    'numero_plan' => '0561',
                ],
                [
                    'dept' => '85',
                    'com' => '151',
                    'prefixe' => '',
                    'section' => '0A',
                    'numero_plan' => '0562',
                ],
                [
                    'dept' => '85',
                    'com' => '198',
                    'prefixe' => '',
                    'section' => '0B',
                    'numero_plan' => '1103',
                ],
            ],
            <<<'TEXT'
85 151 + 00184 O A 0561 03 T
A 0562 02 P

* TOTAL COMMUNE DE MORTAGNE SUR SEVRE

85 198 + 00005 O B 1103 03 T
TEXT
        );
    } finally {
        @unlink($imagePath);
    }

    expect($result['extraction']['msa_parcels'])->toBe([
        [
            'dept' => '85',
            'com' => '151',
            'prefixe' => '',
            'section' => '0A',
            'numero_plan' => '0561',
        ],
        [
            'dept' => '85',
            'com' => '151',
            'prefixe' => '',
            'section' => '0A',
            'numero_plan' => '0562',
        ],
        [
            'dept' => '85',
            'com' => '198',
            'prefixe' => '',
            'section' => '0B',
            'numero_plan' => '1103',
        ],
    ]);
});

it('uses secondary MSA text context for reliable vision completion', function () {
    config()->set('ges-ocr.ai.provider', 'openai');
    config()->set('ges-ocr.openai.vision_model', 'gpt-4.1-mini');

    $client = Mockery::mock(LlmClient::class);

    $client->shouldReceive('chatStructured')
        ->once()
        ->andReturn([
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
            'confidence' => 0.99,
            'review_reason' => '',
            'extracted_data' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'msa_parcels' => [
                    // Références communes aux sources.
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0029'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZR', 'numero_plan' => '0002'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZR', 'numero_plan' => '0003'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0033'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0035'],

                    // Compléments Vision absents des couches texte exploitables.
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0003'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZZ', 'numero_plan' => '0004'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZZ', 'numero_plan' => '0018'],

                    // Mauvais contexte Vision pour une occurrence connue.
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZX', 'numero_plan' => '0023'],

                    // Faux préfixe Vision sur une occurrence connue sans préfixe.
                    ['dept' => '72', 'com' => '083', 'prefixe' => '363', 'section' => 'ZS', 'numero_plan' => '0029'],
                ],
            ],
        ]);

    $analyzer = new OpenAiDocumentAnalyzer(
        $client,
        new DocumentSchemaFactory
    );

    $imagePath = tempnam(sys_get_temp_dir(), 'id3202-');

    if ($imagePath === false) {
        throw new RuntimeException('Unable to create temporary image fixture.');
    }

    file_put_contents($imagePath, 'fake-image-content');

    $primaryParcels = [
        ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0029'],
        ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZR', 'numero_plan' => '0002'],
        ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZR', 'numero_plan' => '0003'],
        ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0033'],
        ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0035'],
    ];

    $secondaryParcels = [
        ['dept' => '72', 'com' => '050', 'prefixe' => '', 'section' => 'ZX', 'numero_plan' => '0023'],
        ...$primaryParcels,
    ];

    try {
        $result = $analyzer->analyzeMsaImagesPageByPage(
            [$imagePath],
            $primaryParcels,
            <<<'TEXT'
72 083 C 00100 ZS 0029
ZR 0002
ZR 0003
ZS 0033
ZS 0035
TEXT,
            $secondaryParcels
        );
    } finally {
        @unlink($imagePath);
    }

    $references = array_map(
        static fn (array $row): string => implode('/', [
            $row['dept'],
            $row['com'],
            $row['prefixe'] === '' ? '000' : $row['prefixe'],
            $row['section'],
            $row['numero_plan'],
        ]),
        $result['extraction']['msa_parcels']
    );

    sort($references);

    $expected = [
        '72/050/000/ZX/0023',
        '72/083/000/ZR/0002',
        '72/083/000/ZR/0003',
        '72/083/000/ZS/0003',
        '72/083/000/ZS/0029',
        '72/083/000/ZS/0033',
        '72/083/000/ZS/0035',
        '72/083/000/ZZ/0004',
        '72/083/000/ZZ/0018',
    ];

    sort($expected);

    expect($references)->toBe($expected);
});

it('reconciles a unique secondary MSA context even when vision completion is unreliable', function () {
    config()->set('ges-ocr.ai.provider', 'openai');
    config()->set('ges-ocr.openai.vision_model', 'gpt-4.1-mini');

    $client = Mockery::mock(LlmClient::class);

    $client->shouldReceive('chatStructured')
        ->once()
        ->andReturn([
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
            'confidence' => 0.99,
            'review_reason' => '',
            'extracted_data' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'msa_parcels' => [
                    // Cinq lignes confirmant le texte principal.
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0029'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZR', 'numero_plan' => '0002'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZR', 'numero_plan' => '0003'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0033'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0035'],

                    // Bonne occurrence, mauvais contexte Vision.
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZX', 'numero_plan' => '0023'],

                    // Bruit destiné à rendre la complétion globale non fiable.
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZA', 'numero_plan' => '1001'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZA', 'numero_plan' => '1002'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZA', 'numero_plan' => '1003'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZA', 'numero_plan' => '1004'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZA', 'numero_plan' => '1005'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZA', 'numero_plan' => '1006'],
                    ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZA', 'numero_plan' => '1007'],
                ],
            ],
        ]);

    $analyzer = new OpenAiDocumentAnalyzer(
        $client,
        new DocumentSchemaFactory
    );

    $imagePath = tempnam(sys_get_temp_dir(), 'id3202-');

    if ($imagePath === false) {
        throw new RuntimeException('Unable to create temporary image fixture.');
    }

    file_put_contents($imagePath, 'fake-image-content');

    $primaryParcels = [
        ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0029'],
        ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZR', 'numero_plan' => '0002'],
        ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZR', 'numero_plan' => '0003'],
        ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0033'],
        ['dept' => '72', 'com' => '083', 'prefixe' => '', 'section' => 'ZS', 'numero_plan' => '0035'],
    ];

    $secondaryParcels = [
        ['dept' => '72', 'com' => '050', 'prefixe' => '', 'section' => 'ZX', 'numero_plan' => '0023'],
        ...$primaryParcels,
    ];

    try {
        $result = $analyzer->analyzeMsaImagesPageByPage(
            [$imagePath],
            $primaryParcels,
            <<<'TEXT'
72 083 C 00100 ZS 0029
ZR 0002
ZR 0003
ZS 0033
ZS 0035
TEXT,
            $secondaryParcels
        );
    } finally {
        @unlink($imagePath);
    }

    $references = array_map(
        static fn (array $row): string => implode('/', [
            $row['dept'],
            $row['com'],
            $row['prefixe'] === '' ? '000' : $row['prefixe'],
            $row['section'],
            $row['numero_plan'],
        ]),
        $result['extraction']['msa_parcels']
    );

    sort($references);

    $expected = [
        '72/050/000/ZX/0023',
        '72/083/000/ZR/0002',
        '72/083/000/ZR/0003',
        '72/083/000/ZS/0029',
        '72/083/000/ZS/0033',
        '72/083/000/ZS/0035',
    ];

    sort($expected);

    expect($references)->toBe($expected);
});

it('updates MSA text context when the commune changes in the middle of a page', function () {
    $analyzer = new OpenAiDocumentAnalyzer(
        Mockery::mock(LlmClient::class),
        new DocumentSchemaFactory,
    );

    $parcels =
        $analyzer->extractMsaTextParcels(
            <<<'TEXT'
72 083 S 00027 ZS 0030 J 02 T
ZR 0002 02 P
72 050 C 00140 ZX 0023 03 T
ZX 0024 03 T
TEXT
        );

    expect($parcels)->toBe([
        [
            'dept' => '72',
            'com' => '083',
            'prefixe' => '',
            'section' => 'ZS',
            'numero_plan' => '0030',
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
            'com' => '050',
            'prefixe' => '',
            'section' => 'ZX',
            'numero_plan' => '0023',
        ],
        [
            'dept' => '72',
            'com' => '050',
            'prefixe' => '',
            'section' => 'ZX',
            'numero_plan' => '0024',
        ],
    ]);
});

it('keeps repeated MSA parcels followed by the cultural classification K 03 T', function () {
    $analyzer = new OpenAiDocumentAnalyzer(
        Mockery::mock(LlmClient::class),
        new DocumentSchemaFactory,
    );

    $parcels =
        $analyzer->extractMsaTextParcels(
            <<<'TEXT'
72 083 S 00027 ZS 0030 J 02 T
ZS 0030 K 03 T
TEXT
        );

    expect($parcels)->toBe([
        [
            'dept' => '72',
            'com' => '083',
            'prefixe' => '',
            'section' => 'ZS',
            'numero_plan' => '0030',
        ],
        [
            'dept' => '72',
            'com' => '083',
            'prefixe' => '',
            'section' => 'ZS',
            'numero_plan' => '0030',
        ],
    ]);
});
