<?php

namespace Ges\Ocr;

use Ges\Ocr\Contracts\LlmClient;
use Ges\Ocr\Support\DocumentProcessingValues;
use Ges\Ocr\Support\LlmConfig;

class OpenAiDocumentAnalyzer
{
    public function __construct(
        protected LlmClient $llmClient,
        protected DocumentSchemaFactory $schemaFactory
    ) {}

    /**
     * @return array{classification: array{document_type: string, confidence: float, review_reason: string}, extraction: array<string, mixed>}
     */
    public function analyzeText(string $text): array
    {
        $payload = $this->llmClient->chatStructured(
            LlmConfig::textModel(),
            [[
                'role' => 'user',
                'content' => $this->buildTextPrompt($text),
            ]],
            $this->schemaFactory->analysisSchema()
        );

        return $this->normalizeAnalysis($payload);
    }

    /**
     * @param  array<int, string>  $imagePaths
     * @return array{classification: array{document_type: string, confidence: float, review_reason: string}, extraction: array<string, mixed>}
     */
    public function analyzeImages(array $imagePaths): array
    {
        $payload = $this->llmClient->chatStructured(
            LlmConfig::visionModel(),
            [[
                'role' => 'user',
                'content' => $this->buildImagePrompt(),
                'images' => $this->encodeImages($imagePaths),
            ]],
            $this->schemaFactory->analysisSchema()
        );

        return $this->normalizeAnalysis($payload);
    }

    private function buildTextPrompt(string $text): string
    {
        return $this->sharedInstructions().
            "\n\n".
            "Analyse le texte OCR suivant et retourne le JSON demande.\n\n".
            $text;
    }

    private function buildImagePrompt(): string
    {
        return $this->sharedInstructions().
            "\n\n".
            'Analyse directement les images jointes du document et retourne le JSON demande.';
    }

    private function sharedInstructions(): string
    {
        return "Tu es un agent d analyse documentaire pour des documents francais d identite et d entreprise.\n".
            "Fais en une seule passe la classification et l extraction structuree.\n".
            "Determine document_type parmi identity_card, residence_permit, passport, visa, crew_card, travel_document, other_identity_document, kbis, acte_propriete ou autre.\n".
            "Retourne confidence entre 0 et 1 et review_reason bref et coherent.\n".
            "Remplis extracted_data avec toutes les cles du schema.\n".
            "Pour le type de document detecte, remplis les champs pertinents.\n".
            "Pour tous les champs non pertinents ou absents, retourne une chaine vide ou un tableau vide.\n".
            "Ne retourne jamais de texte hors JSON.\n".
            "Pour tous les champs de date, retourne YYYY-MM-DD si la date est lisible avec confiance, sinon une chaine vide.\n".
            "Pour les adresses, retourne street_address sans code postal ni ville, postal_code separe, city separee.\n".
            "Pour les documents d identite, first_name contient tous les prenoms et last_name uniquement le nom de famille.\n".
            "Si une MRZ est visible, utilise-la pour corriger ou completer les champs d identite et retourne-la brute dans mrz exactement caractere par caractere.\n".
            "Conserve strictement les caracteres '<' et les separateurs '<<' dans la MRZ.\n".
            "Pour les KBIS, registration_number doit reprendre la valeur brute de l Immatriculation RCS et sirene doit contenir exactement 9 chiffres.\n".
            "Pour les actes de propriete, owners contient uniquement les acquereurs et jamais les vendeurs.\n".
            "N invente aucune information.";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{classification: array{document_type: string, confidence: float, review_reason: string}, extraction: array<string, mixed>}
     */
    private function normalizeAnalysis(array $payload): array
    {
        $documentType = (string) ($payload['document_type'] ?? DocumentProcessingValues::BUSINESS_TYPE_AUTRE);

        $extraction = is_array($payload['extracted_data'] ?? null)
            ? $payload['extracted_data']
            : [];

        $extraction['document_type'] = $documentType;

        return [
            'classification' => [
                'document_type' => $documentType,
                'confidence' => (float) ($payload['confidence'] ?? 0),
                'review_reason' => trim((string) ($payload['review_reason'] ?? '')),
            ],
            'extraction' => $extraction,
        ];
    }

    /**
     * @param  array<int, string>  $imagePaths
     * @return array<int, array{data: string, mime_type: string}>
     */
    private function encodeImages(array $imagePaths): array
    {
        return array_map(
            static fn (string $imagePath): array => [
                'data' => base64_encode((string) file_get_contents($imagePath)),
                'mime_type' => mime_content_type($imagePath) ?: 'application/octet-stream',
            ],
            $imagePaths
        );
    }
}
