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
            "Determine document_type parmi identity_card, residence_permit, passport, visa, crew_card, travel_document, other_identity_document, kbis, inpi, acte_de_situation, acte_propriete, msa ou autre.\n".
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
            "Pour les extraits societe de type KBIS, INPI ou acte de situation, registration_number doit reprendre la valeur brute de l Immatriculation RCS et sirene doit contenir exactement 9 chiffres.\n".
            "Pour les actes de propriete, owners contient uniquement les acquereurs et jamais les vendeurs.\n".
            "Pour les documents MSA de parcelles, retourne une ligne distincte dans msa_parcels pour chaque ligne de tableau visible.\n".
            "Pour les documents MSA, traite toutes les pages fournies et retourne toutes les lignes visibles du tableau, meme s il y en a plus de 200.\n".
            "Pour MSA, lis uniquement les colonnes DEPT, COM, PREFIXE, SECTION et NUMERO PLAN.\n".
            "Pour MSA, ignore strictement les colonnes intermediaires non demandees, meme si elles contiennent des lettres ou nombres comme L, M, B, C, O, 00160, 00193 ou 00143.\n".
            "Pour MSA, lis le couple SECTION + NUMERO PLAN uniquement dans le bloc d identification des parcelles, avant les colonnes CULT CAD, ANT, SUPERFICIE, R.C REEL, Euros et Faire Valoir.\n".
            "Pour MSA, les motifs de droite comme '02 T', '03 T', '02 P', '01 P', 'A 03 T' ou 'B 03 P' appartiennent aux colonnes de culture et ne doivent jamais etre utilises pour section ou numero_plan.\n".
            "Pour MSA, dept contient 2 chiffres, com 3 chiffres, prefixe exactement 3 chiffres ou une chaine vide, section 1 ou 2 caracteres, numero_plan 4 chiffres si lisibles.\n".
            "Pour MSA, les lettres L, M, B, C ou O lues dans des colonnes non demandees ou dans les marqueurs de pluri exploitation ne doivent jamais etre retournees comme prefixe.\n".
            "Pour MSA, une section doit etre alphabetique ou cadastrale et ne doit jamais etre purement numerique, donc 03, 00, 00160, 00193 ou 00143 sont invalides pour section.\n".
            "Pour MSA, numero_plan doit contenir 4 chiffres lisibles et 0000 est impossible.\n".
            "Pour MSA, si tu hesites entre une valeur numerique courte comme 03 et une section voisine alphabetique comme B, ZI ou ZD, retiens toujours la valeur alphabetique de la colonne SECTION.\n".
            "Exemple MSA: '85 006 L 00160 ... B 0357' donne dept=85, com=006, prefixe='', section='B', numero_plan='0357'.\n".
            "Exemple MSA: '85 055 B 00143 O ... ZI 0030' donne dept=85, com=055, prefixe='', section='ZI', numero_plan='0030'.\n".
            "Exemple MSA: '85 055 M 00042 ... ZD 0026 ... A 03 T' donne section='ZD' et numero_plan='0026'. Il ne faut jamais utiliser 'A 03 T' pour construire la parcelle.\n".
            "Pour MSA, quand plusieurs lignes sont empilees sous la meme tete de compte et que seules les paires comme 'ZD 0006', 'ZD 0007', 'ZD 0011', 'ZD 0016', 'ZD 0026', 'ZD 0041' changent, retourne une entree par paire visible.\n".
            "Avant de repondre pour MSA, verifie qu aucune ligne ne contient section='03' ou numero_plan='0000' sauf si le document montre exactement cette valeur dans la bonne colonne, ce qui est normalement impossible.\n".
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
