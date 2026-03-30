<?php

namespace Ges\Ocr;

class DocumentClassifier
{
    public function __construct(
        protected OllamaClient $ollamaClient,
        protected DocumentSchemaFactory $schemaFactory
    ) {}

    /**
     * @return array{document_type: string, confidence: float, review_reason: string}
     */
    public function classifyText(string $text): array
    {
        $payload = $this->ollamaClient->chatStructured(
            (string) config('ges-ocr.ollama.text_model'),
            [[
                'role' => 'user',
                'content' => $this->buildTextPrompt($text),
            ]],
            $this->schemaFactory->classificationSchema()
        );

        return $this->normalizeClassification($payload);
    }

    /**
     * @param  array<int, string>  $imagePaths
     * @return array{document_type: string, confidence: float, review_reason: string}
     */
    public function classifyImages(array $imagePaths): array
    {
        $payload = $this->ollamaClient->chatStructured(
            (string) config('ges-ocr.ollama.vision_model'),
            [[
                'role' => 'user',
                'content' => $this->classificationInstructions(),
                'images' => $this->encodeImages($imagePaths),
            ]],
            $this->schemaFactory->classificationSchema()
        );

        return $this->normalizeClassification($payload);
    }

    private function buildTextPrompt(string $text): string
    {
        return $this->classificationInstructions().
            "\n\n".
            $text;
    }

    private function classificationInstructions(): string
    {
        return "Analyse ce document et classe-le strictement en identity_card, residence_permit, passport, visa, crew_card, travel_document, other_identity_document, kbis, acte_propriete ou autre.\n".
            "Si le document est une carte d identite ou une carte nationale d identite francaise, le type doit etre identity_card.\n".
            "Si le document est un titre de sejour, une carte de sejour ou un titre de residence francais, le type doit etre residence_permit.\n".
            "Si le document est un passeport, le type doit etre passport.\n".
            "Si le document est un visa, le type doit etre visa.\n".
            "Si le document est une crew card, le type doit etre crew_card.\n".
            "Si le document est un document de voyage, le type doit etre travel_document.\n".
            "Si le document est un document MRZ d identite ou de voyage qui ne correspond pas aux categories precedentes, le type doit etre other_identity_document.\n".
            "Si le document ne correspond clairement a aucune categorie, le type doit etre autre.\n".
            "Si une MRZ est visible, lis d abord les 2 premiers caracteres.\n".
            "Le premier caractere indique la categorie: P=passeport, I=carte d identite, A=document de voyage, V=visa, C=document d equipage.\n".
            "Le second caractere precise le sous-type: P<=passport, ID=identity_card, IR=residence_permit, V<=visa, AC=crew_card, <=sous-type non precise.\n".
            "Determine aussi le format MRZ par le nombre de lignes et leur longueur: TD1=3x30, TD2=2x36, TD3=2x44, MRV-A/B=2 lignes.\n".
            "Lis les titres visibles du document en meme temps que la MRZ. Un document avec MRZ d identite ou de voyage ne doit pas etre classe comme kbis ou acte_propriete.\n".
            "La review_reason doit expliquer brievement le choix et rester coherente avec document_type.\n".
            "Retourne uniquement le JSON conforme au schema avec un score de confiance entre 0 et 1.\n".
            'N invente aucune information.';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{document_type: string, confidence: float, review_reason: string}
     */
    private function normalizeClassification(array $payload): array
    {
        return [
            'document_type' => (string) ($payload['document_type'] ?? ''),
            'confidence' => (float) ($payload['confidence'] ?? 0),
            'review_reason' => trim((string) ($payload['review_reason'] ?? '')),
        ];
    }

    /**
     * @param  array<int, string>  $imagePaths
     * @return array<int, string>
     */
    private function encodeImages(array $imagePaths): array
    {
        return array_map(
            static fn (string $imagePath): string => base64_encode((string) file_get_contents($imagePath)),
            $imagePaths
        );
    }
}
