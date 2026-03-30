<?php

namespace Ges\Ocr;

use Ges\Ocr\Support\DocumentProcessingValues;
use InvalidArgumentException;

class DocumentSchemaFactory
{
    /**
     * @return array<string, mixed>
     */
    public function classificationSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document_type' => [
                    'type' => 'string',
                    'enum' => [
                        DocumentProcessingValues::BUSINESS_TYPE_IDENTITY_CARD,
                        DocumentProcessingValues::BUSINESS_TYPE_RESIDENCE_PERMIT,
                        DocumentProcessingValues::BUSINESS_TYPE_PASSPORT,
                        DocumentProcessingValues::BUSINESS_TYPE_VISA,
                        DocumentProcessingValues::BUSINESS_TYPE_CREW_CARD,
                        DocumentProcessingValues::BUSINESS_TYPE_TRAVEL_DOCUMENT,
                        DocumentProcessingValues::BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT,
                        DocumentProcessingValues::BUSINESS_TYPE_KBIS,
                        DocumentProcessingValues::BUSINESS_TYPE_ACTE_PROPRIETE,
                        DocumentProcessingValues::BUSINESS_TYPE_AUTRE,
                    ],
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'review_reason' => [
                    'type' => 'string',
                ],
            ],
            'required' => ['document_type', 'confidence', 'review_reason'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function extractionSchema(string $documentType): array
    {
        return match ($documentType) {
            DocumentProcessingValues::BUSINESS_TYPE_CIN => $this->identityDocumentSchema(DocumentProcessingValues::BUSINESS_TYPE_CIN),
            DocumentProcessingValues::BUSINESS_TYPE_TITRE_DE_SEJOUR => $this->identityDocumentSchema(DocumentProcessingValues::BUSINESS_TYPE_TITRE_DE_SEJOUR),
            DocumentProcessingValues::BUSINESS_TYPE_PASSPORT => $this->identityDocumentSchema(DocumentProcessingValues::BUSINESS_TYPE_PASSPORT),
            DocumentProcessingValues::BUSINESS_TYPE_VISA => $this->identityDocumentSchema(DocumentProcessingValues::BUSINESS_TYPE_VISA),
            DocumentProcessingValues::BUSINESS_TYPE_CREW_CARD => $this->identityDocumentSchema(DocumentProcessingValues::BUSINESS_TYPE_CREW_CARD),
            DocumentProcessingValues::BUSINESS_TYPE_TRAVEL_DOCUMENT => $this->identityDocumentSchema(DocumentProcessingValues::BUSINESS_TYPE_TRAVEL_DOCUMENT),
            DocumentProcessingValues::BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT => $this->identityDocumentSchema(DocumentProcessingValues::BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT),
            DocumentProcessingValues::BUSINESS_TYPE_KBIS => [
                'type' => 'object',
                'properties' => [
                    'document_type' => ['type' => 'string', 'enum' => [DocumentProcessingValues::BUSINESS_TYPE_KBIS]],
                    'company_name' => ['type' => 'string'],
                    'trade_name' => ['type' => 'string'],
                    'legal_form' => ['type' => 'string'],
                    'capital' => ['type' => 'string'],
                    'registration_number' => ['type' => 'string'],
                    'siret' => ['type' => 'string'],
                    'sirene' => ['type' => 'string'],
                    'street_address' => ['type' => 'string'],
                    'postal_code' => ['type' => 'string'],
                    'city' => ['type' => 'string'],
                    'naf_code' => ['type' => 'string'],
                    'registration_date' => ['type' => 'string'],
                    'registry_city' => ['type' => 'string'],
                    'legal_representatives' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'entity_type' => ['type' => 'string', 'enum' => ['person', 'company']],
                                'company_name' => ['type' => 'string'],
                                'legal_form' => ['type' => 'string'],
                                'civility' => ['type' => 'string'],
                                'first_name' => ['type' => 'string'],
                                'last_name' => ['type' => 'string'],
                                'street_address' => ['type' => 'string'],
                                'postal_code' => ['type' => 'string'],
                                'city' => ['type' => 'string'],
                                'registration_number' => ['type' => 'string'],
                                'registry_city' => ['type' => 'string'],
                                'role' => ['type' => 'string'],
                            ],
                            'required' => ['entity_type', 'company_name', 'legal_form', 'civility', 'first_name', 'last_name', 'street_address', 'postal_code', 'city', 'registration_number', 'registry_city', 'role'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['document_type', 'company_name', 'trade_name', 'legal_form', 'capital', 'registration_number', 'siret', 'sirene', 'street_address', 'postal_code', 'city', 'naf_code', 'registration_date', 'registry_city', 'legal_representatives'],
                'additionalProperties' => false,
            ],
            DocumentProcessingValues::BUSINESS_TYPE_ACTE_PROPRIETE => [
                'type' => 'object',
                'properties' => [
                    'document_type' => ['type' => 'string', 'enum' => [DocumentProcessingValues::BUSINESS_TYPE_ACTE_PROPRIETE]],
                    'cadastral_parcels' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'prefixe' => ['type' => 'string'],
                                'section' => ['type' => 'string'],
                                'numero' => ['type' => 'string'],
                                'street_address' => ['type' => 'string'],
                                'postal_code' => ['type' => 'string'],
                                'city' => ['type' => 'string'],
                            ],
                            'required' => ['prefixe', 'section', 'numero', 'street_address', 'postal_code', 'city'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'owners' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'entity_type' => ['type' => 'string', 'enum' => ['person', 'company']],
                                'company_name' => ['type' => 'string'],
                                'civility' => ['type' => 'string'],
                                'first_name' => ['type' => 'string'],
                                'last_name' => ['type' => 'string'],
                            ],
                            'required' => ['entity_type', 'company_name', 'civility', 'first_name', 'last_name'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['document_type', 'cadastral_parcels', 'owners'],
                'additionalProperties' => false,
            ],
            default => throw new InvalidArgumentException("Unsupported document type [{$documentType}] for extraction."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function identityDocumentSchema(string $documentType): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document_type' => ['type' => 'string', 'enum' => [$documentType]],
                'first_name' => ['type' => 'string'],
                'last_name' => ['type' => 'string'],
                'date_of_birth' => ['type' => 'string'],
                'place_of_birth' => ['type' => 'string'],
                'document_number' => ['type' => 'string'],
                'expiry_date' => ['type' => 'string'],
                'nationality' => ['type' => 'string'],
                'sex' => ['type' => 'string'],
                'mrz' => ['type' => 'string'],
                'street_address' => ['type' => 'string'],
                'postal_code' => ['type' => 'string'],
                'city' => ['type' => 'string'],
            ],
            'required' => ['document_type', 'first_name', 'last_name', 'date_of_birth', 'place_of_birth', 'document_number', 'expiry_date', 'nationality', 'sex', 'mrz', 'street_address', 'postal_code', 'city'],
            'additionalProperties' => false,
        ];
    }
}
