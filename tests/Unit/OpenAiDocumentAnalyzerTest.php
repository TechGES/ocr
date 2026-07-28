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
