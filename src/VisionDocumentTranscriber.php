<?php

namespace Ges\Ocr;

class VisionDocumentTranscriber
{
    public function __construct(
        protected OllamaClient $ollamaClient
    ) {}

    /**
     * @param  array<int, string>  $imagePaths
     */
    public function transcribeImages(array $imagePaths): string
    {
        $pages = array_map(
            fn (string $imagePath, int $index): string => $this->transcribeImage($imagePath, $index + 1),
            $imagePaths,
            array_keys($imagePaths)
        );

        return implode("\f", array_filter($pages, static fn (string $page): bool => trim($page) !== ''));
    }

    /**
     * @param  array<int, string>  $imagePaths
     */
    public function extractMrzFromImages(array $imagePaths): string
    {
        $pages = array_map(
            fn (string $imagePath, int $index): string => $this->extractMrzFromImage($imagePath, $index + 1),
            $imagePaths,
            array_keys($imagePaths)
        );

        return implode("\n", array_filter($pages, static fn (string $page): bool => trim($page) !== ''));
    }

    private function transcribeImage(string $imagePath, int $pageNumber): string
    {
        return $this->ollamaClient->chatText(
            (string) config('ges-ocr.ollama.vision_model'),
            [[
                'role' => 'user',
                'content' => "Tu es un agent OCR specialise dans la lecture de cartes d identite, titres de sejour, passeports, visas et autres documents d identite.\n".
                    "Transcris exactement le texte visible sur cette page de document.\n".
                    "Il s agit d une page {$pageNumber}.\n".
                    "Retourne uniquement le texte transcrit.\n".
                    "Conserve l ordre de lecture, les retours a la ligne utiles et les valeurs visibles.\n".
                    "Fais particulierement attention aux champs d identite, aux numeros de document, aux dates, aux adresses et a la MRZ si elle est visible.\n".
                    "Si une MRZ est visible, transcris-la exactement caractere par caractere, sans la reformuler en texte humain.\n".
                    "Conserve strictement les caracteres '<' et les separateurs '<<', n insere pas d espaces et ne remplace jamais la MRZ par des noms lisibles.\n".
                    "Retourne toutes les lignes de la MRZ dans le bon ordre, sans en omettre une seule et sans les compacter en une seule phrase.\n".
                    "Exemple: si la MRZ contient 'EL<ARRIM<<WADIE', retourne exactement 'EL<ARRIM<<WADIE'.\n".
                    "N ajoute aucune explication, aucun resume et n invente rien.\n".
                    'Si un element est illisible, omets-le plutot que de l inventer.',
                'images' => [base64_encode((string) file_get_contents($imagePath))],
            ]]
        );
    }

    private function extractMrzFromImage(string $imagePath, int $pageNumber): string
    {
        return $this->ollamaClient->chatText(
            (string) config('ges-ocr.ollama.vision_model'),
            [[
                'role' => 'user',
                'content' => "Tu es un agent OCR specialise uniquement dans la lecture de MRZ sur des documents d identite.\n".
                    "Il s agit d une page {$pageNumber}.\n".
                    "Retourne uniquement la MRZ visible sur cette page.\n".
                    "Si aucune MRZ n est visible, retourne une chaine vide.\n".
                    "Transcris la MRZ exactement caractere par caractere.\n".
                    "Conserve strictement les caracteres '<' et les separateurs '<<'.\n".
                    "Conserve toutes les lignes MRZ dans le bon ordre, sans les reformuler, sans les compacter et sans ajouter d explication.\n".
                    "N ajoute aucun autre texte que la MRZ brute.",
                'images' => [base64_encode((string) file_get_contents($imagePath))],
            ]]
        );
    }
}
