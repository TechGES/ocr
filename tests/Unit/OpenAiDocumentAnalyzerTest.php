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
                ->and($schema['properties'])->toHaveKey('extracted_data')
                ->and($schema['properties']['extracted_data']['properties'])->toHaveKeys([
                    'first_name',
                    'company_name',
                    'legal_representatives',
                    'cadastral_parcels',
                    'owners',
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
                    'registry_city' => '',
                    'legal_representatives' => [],
                    'cadastral_parcels' => [],
                    'owners' => [],
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
