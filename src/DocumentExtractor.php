<?php

namespace Ges\Ocr;

use Ges\Ocr\Support\DocumentProcessingValues;

class DocumentExtractor
{
    public function __construct(
        protected OllamaClient $ollamaClient,
        protected DocumentSchemaFactory $schemaFactory
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extractFromText(string $documentType, string $text): array
    {
        return $this->ollamaClient->chatStructured(
            (string) config('ges-ocr.ollama.text_model'),
            [[
                'role' => 'user',
                'content' => $this->buildTextPrompt($documentType, $text),
            ]],
            $this->schemaFactory->extractionSchema($documentType)
        );
    }

    /**
     * @param  array<int, string>  $imagePaths
     * @return array<string, mixed>
     */
    public function extractFromImages(string $documentType, array $imagePaths): array
    {
        return $this->ollamaClient->chatStructured(
            (string) config('ges-ocr.ollama.vision_model'),
            [[
                'role' => 'user',
                'content' => $this->buildImagePrompt($documentType),
                'images' => $this->encodeImages($imagePaths),
            ]],
            $this->schemaFactory->extractionSchema($documentType)
        );
    }

    private function buildTextPrompt(string $documentType, string $text): string
    {
        return $this->documentRoleIntro($documentType, false).
            "Extrait les donnees du document de type {$documentType}.\n".
            $this->documentSpecificInstructions($documentType).
            "Retourne uniquement le JSON conforme au schema.\n".
            "Pour tous les champs de date, retourne le format YYYY-MM-DD si la date est lisible avec confiance.\n".
            "Si une date est absente, partielle ou incertaine, retourne une chaine vide.\n".
            "Pour les adresses, retourne street_address sans code postal ni ville.\n".
            "street_address doit toujours tenir sur une seule ligne: remplace les retours a la ligne par des espaces.\n".
            "Retourne postal_code avec uniquement le code postal.\n".
            "Retourne city avec uniquement la ville.\n".
            "Si un code postal est visible dans l adresse, retourne-le obligatoirement dans postal_code.\n".
            "N invente aucune valeur. Si une valeur est absente ou illisible, retourne une chaine vide ou un tableau vide.\n\n".
            $text;
    }

    private function buildImagePrompt(string $documentType): string
    {
        return $this->documentRoleIntro($documentType, true).
            "Extrait les donnees du document de type {$documentType} a partir des images jointes.\n".
            $this->documentSpecificInstructions($documentType).
            "Retourne uniquement le JSON conforme au schema.\n".
            "Pour tous les champs de date, retourne le format YYYY-MM-DD si la date est lisible avec confiance.\n".
            "Si une date est absente, partielle ou incertaine, retourne une chaine vide.\n".
            "Pour les adresses, retourne street_address sans code postal ni ville.\n".
            "street_address doit toujours tenir sur une seule ligne: remplace les retours a la ligne par des espaces.\n".
            "Retourne postal_code avec uniquement le code postal.\n".
            "Retourne city avec uniquement la ville.\n".
            "Si un code postal est visible dans l adresse, retourne-le obligatoirement dans postal_code.\n".
            'N invente aucune valeur. Si une valeur est absente ou illisible, retourne une chaine vide ou un tableau vide.';
    }

    private function documentRoleIntro(string $documentType, bool $fromImages): string
    {
        if (in_array($documentType, DocumentProcessingValues::identityBusinessTypes(), true)) {
            return $fromImages
                ? "Tu es un agent OCR specialise dans la lecture de cartes et documents d identite.\n"
                : "Tu es un agent specialise dans la lecture des informations de cartes et documents d identite.\n";
        }

        return '';
    }

    private function documentSpecificInstructions(string $documentType): string
    {
        return match ($documentType) {
            DocumentProcessingValues::BUSINESS_TYPE_CIN,
            DocumentProcessingValues::BUSINESS_TYPE_TITRE_DE_SEJOUR,
            DocumentProcessingValues::BUSINESS_TYPE_PASSPORT,
            DocumentProcessingValues::BUSINESS_TYPE_VISA,
            DocumentProcessingValues::BUSINESS_TYPE_CREW_CARD,
            DocumentProcessingValues::BUSINESS_TYPE_TRAVEL_DOCUMENT,
            DocumentProcessingValues::BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT => "Pour les documents d identite, first_name doit contenir tous les prenoms exacts dans l ordre du document.\n".
                "Pour les documents d identite, last_name doit contenir uniquement le nom de famille exact, sans prenom supplementaire.\n".
                "Les champs first_name et last_name sont obligatoirement separes: ne laisse jamais last_name vide si le nom du titulaire est visible.\n".
                "Ne deplace jamais une partie du nom de famille dans first_name et ne deplace jamais un prenom dans last_name.\n".
                "Si plusieurs prenoms sont presents, y compris sur plusieurs lignes, retourne-les tous dans first_name dans l ordre exact du document.\n".
                "Ignore les caracteres OCR parasites comme '*', '|', '_' ou autres separateurs visuels dans les noms.\n".
                "Si un bloc de nom melange des mots entierement en majuscules et des mots simplement capitalises, les mots entierement en majuscules correspondent au last_name et les autres au first_name.\n".
                "Exemple: 'EL ARRIM* Wadie' doit donner last_name='EL ARRIM' et first_name='Wadie'.\n".
                "Ne retourne jamais le nom complet dans first_name si last_name peut etre deduit de mots en majuscules.\n".
                "Si une zone NOM/Prénoms, SURNAMES/FORENAMES ou MRZ montre un nom de famille en majuscules suivi d un prenom sur la ligne suivante, mets les majuscules dans last_name et le prenom dans first_name.\n".
                "Si une date de naissance est visible sur le document, retourne-la obligatoirement dans date_of_birth.\n".
                "Pour les titres de sejour, dans une ligne comme 'M MAR 06 04 1989', 'M' correspond au sexe, 'MAR' a la nationalite marocaine, et la date de naissance est '06 04 1989', donc retourne 1989-04-06.\n".
                "Pour les titres de sejour francais, nationality est la nationalite du titulaire, pas le pays emetteur FRA.\n".
                "Si la MRZ ou la ligne d etat civil indique 'MAR', retourne nationality='MAR', pas 'FRA'.\n".
                "Ne traite jamais un code de nationalite comme un mois.\n".
                "Si la date est ecrite en chiffres separes comme '06 04 1989', interprete-la comme jour mois annee.\n".
                "Si une MRZ est visible, utilise-la pour completer ou corriger date_of_birth, expiry_date, first_name, last_name, sex et nationality lorsque ces champs sont lisibles.\n".
                "Si une MRZ est visible, retourne-la aussi dans le champ mrz exactement caractere par caractere, sans la reformuler en texte humain.\n".
                "Conserve strictement les caracteres '<' et les separateurs '<<', n insere pas d espaces a leur place et ne remplace jamais la MRZ par le nom lisible du document.\n".
                "Exemple: si la MRZ contient 'EL<ARRIM<<WADIE', retourne exactement 'EL<ARRIM<<WADIE' dans mrz, pas 'EL ARRIM Wadie'.\n".
                "Le champ mrz doit contenir uniquement la zone MRZ brute telle qu elle apparait visuellement, avec toutes les lignes MRZ dans le bon ordre et les sauts de ligne utiles.\n".
                "Ne retourne jamais une MRZ partielle: si la zone MRZ comporte 2 ou 3 lignes, retourne les 2 ou 3 lignes completes, sans en omettre une seule.\n".
                "Ne compacte pas plusieurs lignes en une seule phrase et ne supprime pas la ligne des noms si elle existe.\n".
                "Lis la MRZ en commencant par les 2 premiers caracteres: P<=passport, ID=identity_card, IR=residence_permit, V<=visa, AC=crew_card, A<=travel_document.\n".
                "Determine ensuite le format MRZ a partir du nombre de lignes et de leur longueur: TD1=3x30, TD2=2x36, TD3=2x44, MRV-A/B=2 lignes.\n".
                "Pour une MRZ de document d identite francais, la date de naissance est encodee en YYMMDD et doit etre convertie en YYYY-MM-DD.\n".
                "Exemple MRZ: '6409144M3204267FRA' implique date_of_birth='1964-09-14', sex='M', expiry_date='2032-04-26' et nationality='FRA'.\n".
                "N ignore pas une date de naissance lisible dans la MRZ si le champ principal du document est partiellement coupe.\n".
                "Il s agit d un document d identite francais: si une adresse est presente, suis le format adresse francaise.\n".
                "Les lignes d adresse avant la ligne du code postal appartiennent a street_address, mais street_address doit etre retourne sur une seule ligne avec des espaces entre les segments.\n".
                "La ligne avec un code postal francais a 5 chiffres et la ville doit remplir postal_code et city.\n".
                "Ignore une ligne finale contenant seulement FRANCE.\n".
                "Si un code postal est visible dans l adresse du document, retourne-le obligatoirement dans postal_code.\n",
            DocumentProcessingValues::BUSINESS_TYPE_KBIS => "Pour les representants legaux, entity_type doit valoir strictement 'person' ou 'company'.\n".
                "Il s agit toujours d un KBIS francais.\n".
                "Extrais toutes les entrees presentes dans la section 'GESTION, DIRECTION, ADMINISTRATION, CONTROLE, ASSOCIES OU MEMBRES'.\n".
                "N omets jamais une personne physique comme President, Directeur general ou Gerant si elle apparait dans cette section.\n".
                "N omets pas les roles comme Commissaire aux comptes titulaire, Commissaire aux comptes suppleant, associe, membre ou tout autre role liste dans cette section.\n".
                "Chaque bloc liste dans cette section doit produire un element distinct dans legal_representatives.\n".
                "Compte visuellement les blocs de roles dans cette section et retourne un element legal_representatives pour chaque bloc visible.\n".
                "Ne fusionne pas plusieurs blocs en un seul element et ne garde pas uniquement les societes si des personnes physiques sont aussi presentes.\n".
                "Si un bloc contient une Denomination ou une Forme juridique, c est une societe: entity_type doit etre 'company'.\n".
                "Dans ce cas, company_name doit contenir exactement la denomination complete, y compris des noms comme 'MM Invest' ou 'UNITED ELECTRIC'.\n".
                "Ne traite jamais 'MM' comme une civilite dans une denomination de societe.\n".
                "Pour une societe, laisse civility, first_name et last_name vides.\n".
                "Si le representant est une societe, mets son nom exact dans company_name, pas dans entity_type.\n".
                "Ne mets jamais un nom de societe ou de personne dans entity_type.\n".
                "Pour un representant societe, extrais aussi si presents: legal_form, street_address, postal_code, city, registration_number et registry_city.\n".
                "Pour les KBIS, registration_number doit contenir exactement la valeur brute de l Immatriculation RCS, par exemple '387 931 694 R.C.S. Paris'.\n".
                "Si la ligne R.C.S. est visible, registration_number doit inclure le suffixe 'R.C.S.' suivi de la ville, et pas seulement les 9 chiffres.\n".
                "Pour les KBIS, sirene doit contenir exactement 9 chiffres.\n".
                "Pour les KBIS, extrais le sirene uniquement a partir de l Immatriculation RCS.\n".
                "Le sirene correspond aux 9 chiffres de la ligne R.C.S., avant 'R.C.S.' et avant la ville.\n".
                "N utilise jamais le numero d identification europeen pour remplir sirene.\n".
                "N utilise jamais une autre suite de chiffres voisine pour remplir sirene.\n".
                "Pour les KBIS, extrais siret uniquement s il apparait explicitement comme SIRET sur le document. N utilise jamais l Immatriculation RCS pour remplir siret.\n",
            DocumentProcessingValues::BUSINESS_TYPE_ACTE_PROPRIETE => "Il s agit toujours d un acte de propriete de terrain francais, pas d un acte de propriete generique.\n".
                "Extrais uniquement les informations suivantes: cadastral_parcels et owners.\n".
                "Chaque element de cadastral_parcels doit representer une parcelle cadastrale distincte.\n".
                "Pour chaque parcelle, retourne prefixe, section, numero, street_address, postal_code et city.\n".
                "Si une parcelle mentionne un lieudit ou leudit, utilise cette valeur comme street_address lorsqu aucune adresse numerotee plus precise n est visible.\n".
                "Si une adresse n est pas visible pour une parcelle, laisse street_address, postal_code et city vides.\n".
                "N invente jamais une parcelle cadastrale absente.\n".
                "Les owners sont uniquement les proprietaires acquereurs, c est a dire les personnes ou entites qui achetent ou recoivent le terrain a la fin de l acte.\n".
                "N ajoute jamais les vendeurs, les cedants, leurs representants, le notaire ou toute autre partie non acquereuse dans owners.\n".
                "Si une commune, municipalite ou administration apparait seulement comme venderesse ou cedante, ne la retourne pas dans owners.\n".
                "Les owners peuvent etre des personnes physiques, des societes, des communes, des municipalites ou des administrations lorsqu elles sont acquereuses du terrain.\n".
                "Si le proprietaire est une personne morale, une commune ou une administration, entity_type doit etre 'company' et company_name doit contenir le nom exact.\n".
                "Pour un owner de type company, laisse civility, first_name et last_name vides.\n".
                "Pour un owner de type person, retourne seulement civility, first_name et last_name.\n".
                "N extrais ni notaire, ni date d acte, ni vendeurs.\n",
            default => '',
        };
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
