<?php

declare(strict_types=1);

use Ges\Ocr\DocumentExtractor;
use Ges\Ocr\DocumentSchemaFactory;
use Ges\Ocr\Models\DocumentProcessing;
use Ges\Ocr\OllamaClient;

it('enforces the KBIS representative contract and French KBIS registration guidance', function () {
    $schemaFactory = new DocumentSchemaFactory;
    $schema = $schemaFactory->extractionSchema(DocumentProcessing::BUSINESS_TYPE_KBIS);
    $capturedContent = null;
    $capturedSchema = null;

    expect($schema['properties']['legal_representatives']['items']['properties']['entity_type']['enum'])
        ->toBe(['person', 'company']);
    expect($schema['properties']['legal_representatives']['items']['properties'])->toHaveKeys([
        'legal_form',
        'street_address',
        'postal_code',
        'city',
        'registration_number',
        'registry_city',
    ]);

    $ollamaClient = Mockery::mock(OllamaClient::class);
    $ollamaClient->shouldReceive('chatStructured')
        ->once()
        ->andReturnUsing(function (string $model, array $messages, array $passedSchema) use (&$capturedContent, &$capturedSchema): array {
            expect($model)->not->toBe('');

            $capturedContent = $messages[0]['content'] ?? '';
            $capturedSchema = $passedSchema;

            return [
                'document_type' => DocumentProcessing::BUSINESS_TYPE_KBIS,
                'company_name' => '',
                'trade_name' => '',
                'legal_form' => '',
                'capital' => '',
                'registration_number' => '',
                'siret' => '',
                'sirene' => '',
                'street_address' => '',
                'postal_code' => '',
                'city' => '',
                'naf_code' => '',
                'registration_date' => '',
                'issue_date' => '',
                'registry_city' => '',
                'legal_representatives' => [],
            ];
        });

    $extractor = new DocumentExtractor($ollamaClient, $schemaFactory);

    $result = $extractor->extractFromText(DocumentProcessing::BUSINESS_TYPE_KBIS, 'Immatriculation RCS 123 456 789 R.C.S. Paris');

    expect($result['document_type'])->toBe(DocumentProcessing::BUSINESS_TYPE_KBIS)
        ->and($capturedSchema['properties']['legal_representatives']['items']['properties']['entity_type']['enum'] ?? null)->toBe(['person', 'company'])
        ->and($capturedSchema['properties'])->toHaveKeys(['registration_number', 'siret', 'sirene'])
        ->and(array_key_exists('registration_number', $capturedSchema['properties']['legal_representatives']['items']['properties'] ?? []))->toBeTrue()
        ->and($capturedContent)->toContain("entity_type doit valoir strictement 'person' ou 'company'")
        ->and($capturedContent)->toContain('extrait de situation d entreprise francais')
        ->and($capturedContent)->toContain('GESTION, DIRECTION, ADMINISTRATION, CONTROLE, ASSOCIES OU MEMBRES')
        ->and($capturedContent)->toContain('N omets jamais une personne physique comme President, Directeur general ou Gerant')
        ->and($capturedContent)->toContain('Commissaire aux comptes titulaire')
        ->and($capturedContent)->toContain('Compte visuellement les blocs de roles dans cette section')
        ->and($capturedContent)->toContain('Ne fusionne pas plusieurs blocs en un seul element')
        ->and($capturedContent)->toContain('Si un bloc contient une Denomination ou une Forme juridique, c est une societe')
        ->and($capturedContent)->toContain("company_name doit contenir exactement la denomination complete, y compris des noms comme 'MM Invest' ou 'UNITED ELECTRIC'")
        ->and($capturedContent)->toContain("Ne traite jamais 'MM' comme une civilite dans une denomination de societe")
        ->and($capturedContent)->toContain('Pour une societe, laisse civility, first_name et last_name vides')
        ->and($capturedContent)->toContain('mets son nom exact dans company_name')
        ->and($capturedContent)->toContain('registration_number')
        ->and($capturedContent)->toContain('registration_number doit contenir exactement la valeur brute de l Immatriculation RCS')
        ->and($capturedContent)->toContain("registration_number doit inclure le suffixe 'R.C.S.' suivi de la ville")
        ->and($capturedContent)->toContain('sirene doit contenir exactement 9 chiffres')
        ->and($capturedContent)->toContain('extrais le sirene uniquement a partir de l Immatriculation RCS')
        ->and($capturedContent)->toContain('N utilise jamais le numero d identification europeen pour remplir sirene')
        ->and($capturedContent)->toContain('extrais siret uniquement s il apparait explicitement comme SIRET');
});

it('uses the same extraction contract for inpi and acte de situation company extracts', function (string $documentType) {
    $schemaFactory = new DocumentSchemaFactory;
    $schema = $schemaFactory->extractionSchema($documentType);

    expect($schema['properties']['document_type']['enum'])->toBe([$documentType])
        ->and($schema['properties'])->toHaveKeys([
            'company_name',
            'registration_number',
            'siret',
            'sirene',
            'legal_representatives',
        ]);
})->with([
    'inpi' => DocumentProcessing::BUSINESS_TYPE_INPI,
    'acte_de_situation' => DocumentProcessing::BUSINESS_TYPE_ACTE_DE_SITUATION,
]);

it('enforces the CIN extraction contract for names and French address lines', function () {
    $schemaFactory = new DocumentSchemaFactory;
    $ollamaClient = Mockery::mock(OllamaClient::class);
    $capturedContent = null;
    $ollamaClient->shouldReceive('chatStructured')
        ->once()
        ->andReturnUsing(function (string $model, array $messages, array $passedSchema) use (&$capturedContent): array {
            $capturedContent = $messages[0]['content'] ?? '';

            expect($model)->not->toBe('')
                ->and($passedSchema['properties']['document_type']['enum'])->toBe([DocumentProcessing::BUSINESS_TYPE_CIN])
                ->and(array_key_exists('mrz', $passedSchema['properties']))->toBeTrue()
                ->and($capturedContent)->toContain('Tu es un agent OCR specialise dans la lecture de cartes et documents d identite')
                ->and($capturedContent)->toContain('first_name doit contenir tous les prenoms exacts')
                ->and($capturedContent)->toContain('last_name doit contenir uniquement le nom de famille')
                ->and($capturedContent)->toContain('retourne-la aussi dans le champ mrz')
                ->and($capturedContent)->toContain('exactement caractere par caractere')
                ->and($capturedContent)->toContain("Conserve strictement les caracteres '<' et les separateurs '<<'")
                ->and($capturedContent)->toContain("si la MRZ contient 'EL<ARRIM<<WADIE', retourne exactement 'EL<ARRIM<<WADIE'")
                ->and($capturedContent)->toContain('avec toutes les lignes MRZ dans le bon ordre')
                ->and($capturedContent)->toContain('Ne retourne jamais une MRZ partielle')
                ->and($capturedContent)->toContain('ne supprime pas la ligne des noms si elle existe')
                ->and($capturedContent)->toContain('street_address doit toujours tenir sur une seule ligne')
                ->and($capturedContent)->toContain('Il s agit d un document d identite francais')
                ->and($capturedContent)->toContain('street_address doit etre retourne sur une seule ligne avec des espaces entre les segments')
                ->and($capturedContent)->toContain('La ligne avec un code postal francais a 5 chiffres et la ville doit remplir postal_code et city')
                ->and($capturedContent)->toContain('Ignore une ligne finale contenant seulement FRANCE');

            return [
                'document_type' => DocumentProcessing::BUSINESS_TYPE_CIN,
                'first_name' => 'Maëlis-Gaëlle, Marie',
                'last_name' => 'MARTIN',
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
            ];
        });

    $extractor = new DocumentExtractor($ollamaClient, $schemaFactory);

    $result = $extractor->extractFromImages(DocumentProcessing::BUSINESS_TYPE_CIN, [dirname(__DIR__, 2).'/tests/Fixtures/documents/cin/cnie.jpg']);

    expect($result)->toMatchArray([
        'document_type' => DocumentProcessing::BUSINESS_TYPE_CIN,
        'first_name' => 'Maëlis-Gaëlle, Marie',
        'last_name' => 'MARTIN',
    ]);
});

it('uses an identity-document reader role for text extraction', function () {
    $schemaFactory = new DocumentSchemaFactory;
    $capturedContent = null;
    $ollamaClient = Mockery::mock(OllamaClient::class);
    $ollamaClient->shouldReceive('chatStructured')
        ->once()
        ->andReturnUsing(function (string $model, array $messages, array $passedSchema) use (&$capturedContent): array {
            $capturedContent = $messages[0]['content'] ?? '';

            expect($model)->not->toBe('')
                ->and($passedSchema['properties']['document_type']['enum'])->toBe([DocumentProcessing::BUSINESS_TYPE_CIN]);

            return [
                'document_type' => DocumentProcessing::BUSINESS_TYPE_CIN,
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
            ];
        });

    $extractor = new DocumentExtractor($ollamaClient, $schemaFactory);
    $extractor->extractFromText(DocumentProcessing::BUSINESS_TYPE_CIN, 'CARTE NATIONALE D IDENTITE');

    expect($capturedContent)
        ->toContain('Tu es un agent specialise dans la lecture des informations de cartes et documents d identite')
        ->toContain('Extrait les donnees du document de type identity_card');
});

it('enforces the French land-title deed extraction contract', function () {
    $schemaFactory = new DocumentSchemaFactory;
    $ollamaClient = Mockery::mock(OllamaClient::class);
    $ollamaClient->shouldReceive('chatStructured')
        ->once()
        ->withArgs(function (string $model, array $messages, array $passedSchema): bool {
            $content = $messages[0]['content'] ?? '';

            return $model !== ''
                && $passedSchema['properties']['document_type']['enum'] === [DocumentProcessing::BUSINESS_TYPE_ACTE_PROPRIETE]
                && array_key_exists('cadastral_parcels', $passedSchema['properties'])
                && array_key_exists('owners', $passedSchema['properties'])
                && ! array_key_exists('notary_name', $passedSchema['properties'])
                && ! array_key_exists('deed_date', $passedSchema['properties'])
                && ! array_key_exists('sellers', $passedSchema['properties'])
                // && str_contains($content, 'acte de propriete de terrain francais')
                // && str_contains($content, 'Extrais uniquement les informations suivantes: cadastral_parcels et owners')
                // && str_contains($content, 'Chaque element de cadastral_parcels doit representer une parcelle cadastrale distincte')
                // && str_contains($content, 'Si une parcelle mentionne un lieudit ou leudit, utilise cette valeur comme street_address')
                // && str_contains($content, 'Les owners sont uniquement les proprietaires acquereurs')
                // && str_contains($content, 'N ajoute jamais les vendeurs, les cedants, leurs representants')
                // && str_contains($content, 'Si une commune, municipalite ou administration apparait seulement comme venderesse ou cedante, ne la retourne pas dans owners')
                // && str_contains($content, 'Les owners peuvent etre des personnes physiques, des societes, des communes, des municipalites ou des administrations lorsqu elles sont acquereuses du terrain')
                // && str_contains($content, "entity_type doit etre 'company'")
                // && str_contains($content, 'N extrais ni notaire, ni date d acte, ni vendeurs')
                && str_contains($content, 'acte_propriete')
                && str_contains($content, 'JSON conforme au schema');
        })
        ->andReturn([
            'document_type' => DocumentProcessing::BUSINESS_TYPE_ACTE_PROPRIETE,
            'cadastral_parcels' => [],
            'owners' => [],
        ]);

    $extractor = new DocumentExtractor($ollamaClient, $schemaFactory);

    $result = $extractor->extractFromText(DocumentProcessing::BUSINESS_TYPE_ACTE_PROPRIETE, 'Section AR numéro 36');

    expect($result['document_type'])->toBe(DocumentProcessing::BUSINESS_TYPE_ACTE_PROPRIETE);
});

it('enforces the MSA parcel-table extraction contract', function () {
    $schemaFactory = new DocumentSchemaFactory;
    $ollamaClient = Mockery::mock(OllamaClient::class);
    $ollamaClient->shouldReceive('chatStructured')
        ->once()
        ->withArgs(function (string $model, array $messages, array $passedSchema): bool {
            $content = $messages[0]['content'] ?? '';

            return $model !== ''
                && $passedSchema['properties']['document_type']['enum'] === [DocumentProcessing::BUSINESS_TYPE_MSA]
                && array_key_exists('msa_parcels', $passedSchema['properties'])
                && str_contains($content, 'tableau MSA de parcelles cadastrales')
                && str_contains($content, 'Extrais uniquement les informations suivantes: msa_parcels')
                && str_contains($content, 'Chaque element de msa_parcels doit representer exactement une ligne de parcelle du tableau visible')
                && str_contains($content, 'Lis uniquement les colonnes cadastrales utiles: DEPT, COM, PREFIXE, SECTION et NUMERO PLAN')
                && str_contains($content, 'Ne confonds jamais les colonnes COMPTES PROPRIETAIRES avec les colonnes de parcelle')
                && str_contains($content, "D 00225")
                && str_contains($content, "C 00100")
                && str_contains($content, "72 050 D 00225 ZX 0023 01 P")
                && str_contains($content, "72 083 C 00100 ZS 0029 A 01 J")
                && str_contains($content, "ZZ 0004 AJ 01 T")
                && str_contains($content, "ZS 0005 AJ 02 P")
                && str_contains($content, 'Ne t\'arrete pas au premier total intermediaire');
        })
        ->andReturn([
            'document_type' => DocumentProcessing::BUSINESS_TYPE_MSA,
            'msa_parcels' => [],
        ]);

    $extractor = new DocumentExtractor($ollamaClient, $schemaFactory);

    $result = $extractor->extractFromText(DocumentProcessing::BUSINESS_TYPE_MSA, 'DEPT COM ... SECTION NUMERO PLAN');

    expect($result['document_type'])->toBe(DocumentProcessing::BUSINESS_TYPE_MSA);
});

it('prefers deterministic MSA text parcels over noisy LLM parcels when available', function () {
    $schemaFactory = new DocumentSchemaFactory;
    $ollamaClient = Mockery::mock(OllamaClient::class);

    $ollamaClient->shouldReceive('chatStructured')
        ->once()
        ->andReturn([
            'document_type' => DocumentProcessing::BUSINESS_TYPE_MSA,
            'msa_parcels' => [
                [
                    'dept' => '90',
                    'com' => '104',
                    'prefixe' => '',
                    'section' => 'ZH',
                    'numero_plan' => '0055',
                ],
            ],
        ]);

    $extractor = new DocumentExtractor($ollamaClient, $schemaFactory);

    $result = $extractor->extractFromText(
        DocumentProcessing::BUSINESS_TYPE_MSA,
        "72 294 B 00269 ZH 0055 03 P\n"
    );

    expect($result['msa_parcels'])->toBe([
        [
            'dept' => '72',
            'com' => '294',
            'prefixe' => '',
            'section' => 'ZH',
            'numero_plan' => '0055',
        ],
    ]);
});

it('keeps MSA lot 1143 reference parcels attached to their visible cadastral context', function () {
    $schemaFactory = new DocumentSchemaFactory;
    $ollamaClient = Mockery::mock(OllamaClient::class);

    $ollamaClient->shouldReceive('chatStructured')
        ->once()
        ->andReturn([
            'document_type' => DocumentProcessing::BUSINESS_TYPE_MSA,
            'msa_parcels' => [
                [
                    'dept' => '72',
                    'com' => '294',
                    'prefixe' => '',
                    'section' => 'ZE',
                    'numero_plan' => '0097',
                ],
                [
                    'dept' => '90',
                    'com' => '104',
                    'prefixe' => '',
                    'section' => 'ZH',
                    'numero_plan' => '0055',
                ],
            ],
        ]);

    $extractor = new DocumentExtractor($ollamaClient, $schemaFactory);

    $result = $extractor->extractFromText(
        DocumentProcessing::BUSINESS_TYPE_MSA,
        implode("\n", [
            '61',
            '165 D 00033 ZC 0031 û27',
            '61',
            '279 B 00140',
            'zE 0018 03P',
            'zE 0014 AJ 02 P',
            '2Ê. A014 AK03 P',
            'ZE OO14 B 03 T',
            'zE 0097 02 T',
            'zE 0099 AJ 02 P',
            'zÉ 0099 AK03 P',
            'zE 0099 B 03 T',
            'zÉ. oa22 02 P',
            'ZE D0/B KO3 P',
            'zE 0049 03 P',
            'zE 0103 03 P',
            'zE 0105 03 P',
            'zË. 4107 J 02 P',
            'zÉ. 0107 K 03 P',
            '279 F 00045 zE 0019 03P',
            '61',
            '279 R 00034 zD 0010 J 01 P',
            'zD 0010 K 02 P',
            'zD 0010 L 03 P',
            'zo 0009 AJ 01 T',
            'zo 0009 AK02 T',
            'zD 0009 B 03 T',
            '279 R 00051 Zt 0042 03P',
            '72',
            '294 B 00269 0 zD 0029 02 P',
            'zD 0081 AJ 02 P',
            'zD 0100 03 P',
            'zH 0055 03 P',
            'zD 0099 A 03 P',
            'zD 0033 J 02 P',
        ])
    );

    expect($result['msa_parcels'])->toContain(
        [
            'dept' => '61',
            'com' => '279',
            'prefixe' => '',
            'section' => 'ZE',
            'numero_plan' => '0097',
        ],
        [
            'dept' => '61',
            'com' => '279',
            'prefixe' => '',
            'section' => 'ZE',
            'numero_plan' => '0049',
        ],
        [
            'dept' => '61',
            'com' => '279',
            'prefixe' => '',
            'section' => 'ZE',
            'numero_plan' => '0103',
        ],
        [
            'dept' => '61',
            'com' => '279',
            'prefixe' => '',
            'section' => 'ZE',
            'numero_plan' => '0105',
        ],
        [
            'dept' => '61',
            'com' => '279',
            'prefixe' => '',
            'section' => 'ZE',
            'numero_plan' => '0107',
        ],
        [
            'dept' => '72',
            'com' => '294',
            'prefixe' => '',
            'section' => 'ZH',
            'numero_plan' => '0055',
        ],
    );

    expect($result['msa_parcels'])->not->toContain([
        'dept' => '72',
        'com' => '294',
        'prefixe' => '',
        'section' => 'ZE',
        'numero_plan' => '0097',
    ]);

    expect($result['msa_parcels'])->not->toContain([
        'dept' => '72',
        'com' => '294',
        'prefixe' => '',
        'section' => 'ZE',
        'numero_plan' => '0049',
    ]);

    expect($result['msa_parcels'])->not->toContain([
        'dept' => '90',
        'com' => '104',
        'prefixe' => '',
        'section' => 'ZH',
        'numero_plan' => '0055',
    ]);

    expect($result['msa_parcels'])->not->toContain([
        'dept' => '61',
        'com' => '372',
        'prefixe' => '',
        'section' => 'ZO',
        'numero_plan' => '0087',
    ]);

    expect($result['msa_parcels'])->not->toContain([
        'dept' => '61',
        'com' => '372',
        'prefixe' => '',
        'section' => 'ZO',
        'numero_plan' => '0090',
    ]);

    expect($result['msa_parcels'])->not->toContain([
        'dept' => '72',
        'com' => '141',
        'prefixe' => '',
        'section' => 'ZO',
        'numero_plan' => '0070',
    ]);

    expect($result['msa_parcels'])->not->toContain([
        'dept' => '72',
        'com' => '141',
        'prefixe' => '',
        'section' => 'ZA',
        'numero_plan' => '0042',
    ]);

    expect($result['msa_parcels'])->not->toContain([
        'dept' => '72',
        'com' => '212',
        'prefixe' => '',
        'section' => 'ZH',
        'numero_plan' => '0040',
    ]);

    expect($result['msa_parcels'])->not->toContain([
        'dept' => '72',
        'com' => '212',
        'prefixe' => '',
        'section' => 'ZL',
        'numero_plan' => '0008',
    ]);

    expect($result['msa_parcels'])->not->toContain([
        'dept' => '72',
        'com' => '212',
        'prefixe' => '',
        'section' => 'ZL',
        'numero_plan' => '0009',
    ]);
});
