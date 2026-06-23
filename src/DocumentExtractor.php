<?php

namespace Ges\Ocr;

use Ges\Ocr\Contracts\LlmClient;
use Ges\Ocr\Support\DocumentProcessingValues;
use Ges\Ocr\Support\LlmConfig;

class DocumentExtractor
{
    public function __construct(
        protected LlmClient $llmClient,
        protected DocumentSchemaFactory $schemaFactory
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extractFromText(string $documentType, string $text): array
    {
        $result = $this->llmClient->chatStructured(
            LlmConfig::textModel(),
            [[
                'role' => 'user',
                'content' => $this->buildTextPrompt($documentType, $text),
            ]],
            $this->schemaFactory->extractionSchema($documentType)
        );

        if ($documentType === DocumentProcessingValues::BUSINESS_TYPE_MSA) {
            $result['msa_parcels'] = $this->mergeMsaParcels(
                is_array($result['msa_parcels'] ?? null) ? $result['msa_parcels'] : [],
                $this->extractMsaParcelsFromText($text)
            );
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $llmParcels
     * @param  array<int, array{dept: string, com: string, prefixe: string, section: string, numero_plan: string}>  $textParcels
     * @return array<int, mixed>
     */
    private function mergeMsaParcels(array $llmParcels, array $textParcels): array
    {
        $merged = [];
        $mergedCounts = [];

        foreach ($llmParcels as $parcel) {
            if (! is_array($parcel)) {
                continue;
            }

            $key = $this->msaParcelKey($parcel);
            $mergedCounts[$key] = ($mergedCounts[$key] ?? 0) + 1;
            $merged[] = $parcel;
        }

        $textCounts = [];

        foreach ($textParcels as $parcel) {
            $key = $this->msaParcelKey($parcel);
            $textCounts[$key] = ($textCounts[$key] ?? 0) + 1;
        }

        foreach ($textParcels as $parcel) {
            $key = $this->msaParcelKey($parcel);

            if (($mergedCounts[$key] ?? 0) >= ($textCounts[$key] ?? 0)) {
                continue;
            }

            $mergedCounts[$key] = ($mergedCounts[$key] ?? 0) + 1;
            $merged[] = $parcel;
        }

        return array_values($merged);
    }

    /**
     * @return array<int, array{dept: string, com: string, prefixe: string, section: string, numero_plan: string}>
     */
    private function extractMsaParcelsFromText(string $text): array
    {
        $lines = preg_split('/\R+/u', $text) ?: [];
        $lastDept = '';
        $lastCom = '';
        $parcels = [];

        foreach ($lines as $line) {
            $line = mb_strtoupper($line);
            $line = strtr($line, [
                'É' => 'E',
                'È' => 'E',
                'Ê' => 'E',
                'Ë' => 'E',
                'À' => 'A',
                'Â' => 'A',
                'Î' => 'I',
                'Ï' => 'I',
                'Ô' => 'O',
                'Û' => 'U',
                'Ù' => 'U',
                'Ç' => 'C',
            ]);
            $line = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $line) ?? $line;
            $line = preg_replace('/\s+/u', ' ', trim($line)) ?? trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/\b(\d{2})\s+(\d{3})\b/u', $line, $matches) === 1) {
                $lastDept = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $lastCom = str_pad($matches[2], 3, '0', STR_PAD_LEFT);
            } elseif (preg_match('/^(\d{3})\s+[A-Z]\s+\d{4}\b/u', $line, $matches) === 1 && $lastDept !== '') {
                $lastCom = str_pad($matches[1], 3, '0', STR_PAD_LEFT);
            }

            preg_match_all(
                '/\b([A-Z]{1,2})\s*(\d{4})\s+(?:(?:[A-Z]{1,2})\s*)?\d{2}\s*[A-Z]\b/u',
                $line,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $section = mb_strtoupper($match[1]);
                $numeroPlan = $match[2];

                if (in_array($section, ['DU', 'OU', 'LE', 'LA', 'DE', 'OE', 'RC'], true)) {
                    continue;
                }

                if ($numeroPlan === '0000') {
                    continue;
                }

                $parcels[] = [
                    'dept' => $lastDept,
                    'com' => $lastCom,
                    'prefixe' => '',
                    'section' => $section,
                    'numero_plan' => $numeroPlan,
                ];
            }
        }

        return $parcels;
    }

    /**
     * @param  array<string, mixed>  $parcel
     */
    private function msaParcelKey(array $parcel): string
    {
        return implode('.', [
            trim((string) ($parcel['dept'] ?? '')),
            trim((string) ($parcel['com'] ?? '')),
            trim((string) ($parcel['prefixe'] ?? '')),
            mb_strtoupper(trim((string) ($parcel['section'] ?? ''))),
            trim((string) ($parcel['numero_plan'] ?? '')),
        ]);
    }

    /**
     * @param  array<int, string>  $imagePaths
     * @return array<string, mixed>
     */
    public function extractFromImages(string $documentType, array $imagePaths): array
    {
        return $this->llmClient->chatStructured(
            LlmConfig::visionModel(),
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
                "Si un nom d usage, nom d epouse, nom d epoux ou nom marital est visible, retourne-le dans usage_name sans remplacer last_name.\n".
                "Si le document contient une mention comme 'epouse', 'epoux', 'nee', 'nom d usage' ou 'nom marital', conserve last_name avec le nom principal et mets uniquement le nom d usage dans usage_name.\n".
                "Exemple: 'Nom: TESTU Epouse: MONTRIEUX' doit donner last_name='TESTU' et usage_name='MONTRIEUX'.\n".
                "Si aucun nom d usage n est visible, retourne usage_name vide si le champ existe dans le schema.\n".
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
            DocumentProcessingValues::BUSINESS_TYPE_KBIS,
            DocumentProcessingValues::BUSINESS_TYPE_INPI,
            DocumentProcessingValues::BUSINESS_TYPE_ACTE_DE_SITUATION => "Pour les representants legaux, entity_type doit valoir strictement 'person' ou 'company'.\n".
                "Il s agit toujours d un extrait de situation d entreprise francais de type KBIS, INPI ou acte de situation.\n".
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
                "Pour les extraits societe, registration_number doit contenir exactement la valeur brute de l Immatriculation RCS, par exemple '387 931 694 R.C.S. Paris'.\n".
                "Si la ligne R.C.S. est visible, registration_number doit inclure le suffixe 'R.C.S.' suivi de la ville, et pas seulement les 9 chiffres.\n".
                "Pour les extraits societe, sirene doit contenir exactement 9 chiffres.\n".
                "Pour les extraits societe, extrais le sirene uniquement a partir de l Immatriculation RCS.\n".
                "Le sirene correspond aux 9 chiffres de la ligne R.C.S., avant 'R.C.S.' et avant la ville.\n".
                "N utilise jamais le numero d identification europeen pour remplir sirene.\n".
                "N utilise jamais une autre suite de chiffres voisine pour remplir sirene.\n".
                "Pour les extraits societe, extrais siret uniquement s il apparait explicitement comme SIRET sur le document. N utilise jamais l Immatriculation RCS pour remplir siret.\n".
                "Pour les extraits societe de type KBIS ou acte de situation, issue_date correspond a la date d edition ou a la date 'a jour au ...' de l extrait, par exemple 'Extrait d immatriculation principale au registre du commerce et des societes a jour au 25 juin 2025' implique issue_date='2025-06-25'.\n".
                "Pour les attestations INPI / RNE, issue_date correspond en priorite a la date presente dans la phrase d en-tete 'concernant l entreprise ... a la date du ...'. Exemple: 'concernant l entreprise DUFAYET a la date du 29 avril 2026' implique issue_date='2026-04-29'.\n".
                "Pour les attestations INPI / RNE, ne prends jamais 'Date de mise a jour de l entreprise' comme issue_date si une phrase 'a la date du ...' est presente.\n".
                "Ne confonds jamais issue_date avec registration_date: registration_date est la date d immatriculation de la societe, issue_date est la date d edition ou de situation du document.\n".
                "issue_date doit etre retourne au format YYYY-MM-DD.\n",
                "Pour les attestations INPI / RNE d entrepreneur individuel, la ligne 'Nom, Prenom(s)' correspond a l entrepreneur.\n".
                "Pour un entrepreneur individuel, l entrepreneur est aussi le representant legal: ajoute une entree dans legal_representatives avec entity_type='person'.\n".
                "Exemple: 'Nom, Prenom(s) : MURAIL EMMANUEL, THIERRY, HUGUES' implique legal_representatives[0].last_name='MURAIL' et legal_representatives[0].first_name='EMMANUEL THIERRY HUGUES'.\n".
                "Ne laisse jamais legal_representatives vide si un entrepreneur individuel contient une ligne 'Nom, Prenom(s)' exploitable.\n".
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
            DocumentProcessingValues::BUSINESS_TYPE_MSA => "Il s agit toujours d un tableau MSA de parcelles cadastrales.\n".
                "Extrais uniquement les informations suivantes: msa_parcels.\n".
                "Chaque element de msa_parcels doit representer exactement une ligne de parcelle du tableau visible.\n".
                "Traite toutes les pages du document fournies en entree et retourne toutes les lignes de parcelles visibles sur toutes ces pages, sans t arreter apres un echantillon partiel.\n".
                "Si le tableau contient plus de 200 lignes, retourne quand meme toutes les lignes visibles.\n".
                "Pour chaque ligne, retourne dept, com, prefixe, section et numero_plan.\n".
                "Lis uniquement les colonnes cadastrales utiles: DEPT, COM, PREFIXE, SECTION et NUMERO PLAN.\n".
                "Ne confonds jamais les colonnes COMPTES PROPRIETAIRES avec les colonnes de parcelle.\n".
                "Dans une ligne MSA, les groupes comme 'D 00225', 'C 00100', 'D 00068', 'S 00027', 'B 00144' correspondent au compte proprietaire et ne doivent jamais etre retournes comme section ou numero_plan.\n".
                "SECTION est generalement la paire alphabetique situee apres le compte proprietaire: exemples ZX, ZS, ZR, ZY, ZZ, ZA, ZD, ZH.\n".
                "NUMERO PLAN est le nombre de 4 chiffres situe juste apres SECTION.\n".
                "Exemple MSA: '72 050 D 00225 ZX 0023 01 P' donne dept='72', com='050', prefixe='', section='ZX', numero_plan='0023'. Il ne faut jamais retourner section='D' ni numero_plan='0225'.\n".
                "Exemple MSA: '72 083 C 00100 ZS 0029 A 01 J' donne dept='72', com='083', prefixe='', section='ZS', numero_plan='0029'. Il ne faut jamais retourner section='C' ni numero_plan='0100'.\n".
                "Exemple MSA: '72 083 D 00068 ZR 0002 03 T' donne dept='72', com='083', prefixe='', section='ZR', numero_plan='0002'. Il ne faut jamais retourner section='D' ni numero_plan='0068'.\n".
                "Ignore strictement les colonnes de compte proprietaire et les colonnes intermediaires non demandees, meme si elles contiennent des lettres ou nombres comme L, M, B, C, D, S, O, 00160, 00193, 00143, 00225 ou 00100.\n".
                "Pour MSA, certaines parcelles sont subdivisees: la ligne peut contenir SECTION + NUMERO PLAN + suffixe de subdivision + code culture.\n".
                "Exemples: 'ZZ 0004 AJ 01 T' donne section='ZZ', numero_plan='0004'. Le suffixe 'AJ' et le code culture '01 T' ne doivent pas modifier numero_plan.\n".
                "Exemples: 'ZZ 0004 AK 02 T' donne encore section='ZZ', numero_plan='0004'. Ne retourne pas ZZ0007 ni ZZ0006.\n".
                "Exemples: 'ZZ 0018 J 01 T' donne section='ZZ', numero_plan='0018'. Ne retourne pas ZZ0011.\n".
                "Exemples: 'ZZ 0018 K 02 T' donne encore section='ZZ', numero_plan='0018'. Ne retourne pas ZZ0016.\n".
                "Exemples: 'ZS 0005 AJ 02 P', 'ZS 0005 AK 03 P' et 'ZS 0005 B 02 T' donnent toutes section='ZS', numero_plan='0005'.\n".
                "Exemples: 'ZS 0030 J 02 T' et 'ZS 0030 K 03 T' donnent toutes section='ZS', numero_plan='0030'.\n".
                "Pour MSA, ne construis jamais numero_plan a partir des codes culture ou des suffixes AJ, AK, A, B, J, K.\n".
                "Pour MSA, ignore les valeurs de compte proprietaire comme '083 D 00114' et '083 S 00027' si elles ne sont pas suivies immediatement d'une vraie section cadastrale.\n".
                "Pour MSA, si une section + numero_plan apparait plusieurs fois avec des suffixes differents, retourne plusieurs entrees seulement si le schema ne permet pas de stocker le suffixe; elles auront alors le meme section et le meme numero_plan.\n".
                "Pour MSA, conserve les lignes subdivisees comme des lignes distinctes si le schema ne permet pas de stocker la subdivision. Exemple: 'ZZ 0004 AJ 01 T' et 'ZZ 0004 AK 02 T' doivent produire deux entrees avec section='ZZ' et numero_plan='0004'.\n".
                "Pour MSA, conserve aussi les subdivisions simples A, B, J, K, AJ, AK, BJ, BK comme lignes distinctes lorsque presentes dans le tableau.\n".
                "Pour MSA, ne deduplique pas les lignes qui ont le meme SECTION + NUMERO PLAN si elles ont des suffixes de subdivision differents dans le document.\n".
                "Pour MSA, ne transforme jamais un code culture ou un suffixe en numero_plan. Exemple: 'ZZ 0018 K 02 T' donne section='ZZ', numero_plan='0018', et jamais '0014'.\n".
                "Pour MSA, le departement doit etre lu depuis la colonne DEPT en debut de ligne ou repris du bloc courant si la ligne continue sur le meme bloc. Ne jamais inventer dept='05' si le document indique dept='72'.\n".
                "Pour MSA, si une ligne de parcelle ne repete pas DEPT/COM mais suit directement une ligne du meme bloc, reutilise le dernier DEPT/COM valide.\n".
                "Pour MSA, une ligne comme 'ZS 0003 02 P' est une parcelle valide: section='ZS', numero_plan='0003'.\n".
                "Pour MSA, lis toutes les lignes jusqu'a la fin du tableau, y compris apres les lignes TOTAL, POTAG ou les ruptures de compte proprietaire.\n".
                "Pour MSA, une ligne qui commence directement par SECTION + NUMERO PLAN sans repeter DEPT et COM doit etre extraite en reutilisant le dernier DEPT et COM valides.\n".
                "Exemple: apres une ligne '72 083 S 00027 ZS 0030 J 02 T', une ligne suivante 'ZS 0030 K 03 T' doit produire une deuxieme entree section='ZS', numero_plan='0030'.\n".
                "Exemple: une ligne 'ZS 0003 02 P' doit etre extraite avec le dernier dept/com valides: dept='72', com='083', section='ZS', numero_plan='0003'.\n".
                "Ne t'arrete pas au premier total intermediaire: les lignes de parcelles visibles apres un total doivent aussi etre extraites.\n".
                "Le couple SECTION + NUMERO PLAN doit etre lu uniquement dans le bloc d identification des parcelles, juste a droite du bloc PREFIXE/numero intermediaire et avant les colonnes CULT CAD, ANT, SUPERFICIE, R.C REEL, Euros, Faire Valoir et lieu-dit.\n".
                "Ne lis jamais SECTION ou NUMERO PLAN dans les colonnes de culture ou de surface a droite. Des motifs comme '02 T', '03 T', '02 P', '01 P', 'A 03 T' ou 'B 03 P' appartiennent a ces colonnes de droite et ne sont jamais des identifiants de parcelle.\n".
                "DEPT correspond a la colonne 1 et doit contenir 2 chiffres si lisibles.\n".
                "COM correspond a la colonne 2 et doit contenir 3 chiffres si lisibles.\n".
                "PREFIXE correspond a la colonne 6 et doit contenir exactement 3 chiffres si lisibles, sinon une chaine vide. Si la valeur visible est une lettre comme L, M, B, C ou O, ce n est pas un prefixe et il faut retourner une chaine vide.\n".
                "SECTION correspond a la colonne 7 et doit contenir 1 ou 2 lettres ou caracteres cadastraux tels que visibles. Une section ne doit jamais etre purement numerique, donc des valeurs comme 03, 00, 00160, 00193 ou 00143 sont invalides pour section.\n".
                "NUMERO PLAN correspond a la colonne 8 et doit contenir 4 chiffres si lisibles. La valeur 0000 est impossible et ne doit jamais etre retournee.\n".
                "Si tu hesites entre une valeur numerique courte comme 03 et une section alphabétique voisine comme B, ZI ou ZD, la section correcte est la valeur alphabétique de la colonne SECTION.\n".
                "Dans une ligne comme '85 006 L 00160 ... B 0357', il faut retourner dept=85, com=006, prefixe='', section='B', numero_plan='0357'.\n".
                "Dans une ligne comme '85 055 B 00143 O ... ZI 0030', il faut retourner dept=85, com=055, prefixe='', section='ZI', numero_plan='0030'. Le 'O' de pluri exploitation ne doit jamais etre retourne dans prefixe.\n".
                "Dans un bloc comme '85 055 M 00042 ... ZD 0026 ... A 03 T', il faut retourner section='ZD' et numero_plan='0026'. Il ne faut jamais retourner section='A' ni numero_plan='0365' a partir de 'A 03 T'.\n".
                "Si plusieurs lignes partagent les memes colonnes de gauche et que seules les valeurs du bloc SECTION + NUMERO PLAN changent en dessous, retourne une ligne de parcelle pour chaque paire visible comme 'ZD 0006', 'ZD 0007', 'ZD 0011', 'ZD 0016', 'ZD 0026', 'ZD 0041', etc.\n".
                "Ne saute aucune ligne simplement parce que dept ou com sont omis visuellement: retourne une entree pour chaque ligne visible et laisse dept ou com vides si necessaire.\n".
                "Avant de repondre, verifie que chaque ligne retournee a une section alphabetique plausible et un numero_plan different de 0000.\n".
                "Si DEPT ou COM est vide sur une ligne du tableau, laisse la valeur vide plutot que d inventer: la normalisation applicative reportera la derniere valeur connue.\n".
                "N invente jamais de ligne absente et ne fusionne jamais deux lignes distinctes.\n",
            default => '',
        };
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
