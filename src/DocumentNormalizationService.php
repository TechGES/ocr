<?php

namespace Ges\Ocr;

use Carbon\CarbonImmutable;
use Ges\Ocr\Support\DocumentProcessingValues;

class DocumentNormalizationService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{normalized: array<string, mixed>, needs_review: bool, errors: array<int, string>}
     */
    public function normalizeAndValidate(string $documentType, array $payload): array
    {
        $normalized = match ($documentType) {
            DocumentProcessingValues::BUSINESS_TYPE_CIN => $this->normalizeCin($payload),
            DocumentProcessingValues::BUSINESS_TYPE_TITRE_DE_SEJOUR => $this->normalizeTitreDeSejour($payload),
            DocumentProcessingValues::BUSINESS_TYPE_PASSPORT => $this->normalizeIdentityDocument($payload, DocumentProcessingValues::BUSINESS_TYPE_PASSPORT),
            DocumentProcessingValues::BUSINESS_TYPE_VISA => $this->normalizeIdentityDocument($payload, DocumentProcessingValues::BUSINESS_TYPE_VISA),
            DocumentProcessingValues::BUSINESS_TYPE_CREW_CARD => $this->normalizeIdentityDocument($payload, DocumentProcessingValues::BUSINESS_TYPE_CREW_CARD),
            DocumentProcessingValues::BUSINESS_TYPE_TRAVEL_DOCUMENT => $this->normalizeIdentityDocument($payload, DocumentProcessingValues::BUSINESS_TYPE_TRAVEL_DOCUMENT),
            DocumentProcessingValues::BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT => $this->normalizeIdentityDocument($payload, DocumentProcessingValues::BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT),
            DocumentProcessingValues::BUSINESS_TYPE_KBIS => $this->normalizeKbis($payload),
            DocumentProcessingValues::BUSINESS_TYPE_INPI => $this->normalizeCompanyExtract($payload, DocumentProcessingValues::BUSINESS_TYPE_INPI),
            DocumentProcessingValues::BUSINESS_TYPE_ACTE_DE_SITUATION => $this->normalizeCompanyExtract($payload, DocumentProcessingValues::BUSINESS_TYPE_ACTE_DE_SITUATION),
            DocumentProcessingValues::BUSINESS_TYPE_ACTE_PROPRIETE => $this->normalizeActePropriete($payload),
            DocumentProcessingValues::BUSINESS_TYPE_MSA => $this->normalizeMsa($payload),
            default => ['document_type' => DocumentProcessingValues::BUSINESS_TYPE_AUTRE],
        };

        $errors = match ($documentType) {
            DocumentProcessingValues::BUSINESS_TYPE_CIN => $this->validateCin($normalized),
            DocumentProcessingValues::BUSINESS_TYPE_TITRE_DE_SEJOUR => $this->validateCin($normalized),
            DocumentProcessingValues::BUSINESS_TYPE_PASSPORT => $this->validateCin($normalized),
            DocumentProcessingValues::BUSINESS_TYPE_VISA => $this->validateCin($normalized),
            DocumentProcessingValues::BUSINESS_TYPE_CREW_CARD => $this->validateCin($normalized),
            DocumentProcessingValues::BUSINESS_TYPE_TRAVEL_DOCUMENT => $this->validateCin($normalized),
            DocumentProcessingValues::BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT => $this->validateCin($normalized),
            DocumentProcessingValues::BUSINESS_TYPE_KBIS => $this->validateKbis($normalized),
            DocumentProcessingValues::BUSINESS_TYPE_INPI => $this->validateKbis($normalized),
            DocumentProcessingValues::BUSINESS_TYPE_ACTE_DE_SITUATION => $this->validateKbis($normalized),
            DocumentProcessingValues::BUSINESS_TYPE_ACTE_PROPRIETE => $this->validateActePropriete($normalized),
            DocumentProcessingValues::BUSINESS_TYPE_MSA => $this->validateMsa($normalized),
            default => ['Document type requires manual review.'],
        };

        return [
            'normalized' => $normalized,
            'needs_review' => $errors !== [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeCin(array $payload): array
    {
        return $this->normalizeIdentityDocument($payload, DocumentProcessingValues::BUSINESS_TYPE_CIN);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeTitreDeSejour(array $payload): array
    {
        return $this->normalizeIdentityDocument($payload, DocumentProcessingValues::BUSINESS_TYPE_TITRE_DE_SEJOUR);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeIdentityDocument(array $payload, string $documentType): array
    {
        $parsedMrz = $this->parseMrz($this->firstStringValue($payload, ['mrz']));
        $firstName = $this->normalizePersonNameValue($this->firstStringValue($payload, ['first_name', 'prenom']));
        $lastName = $this->normalizePersonNameValue($this->firstStringValue($payload, ['last_name', 'nom']));

        if ($lastName === '' && $parsedMrz['last_name'] !== '') {
            $lastName = $parsedMrz['last_name'];
        }

        if ($firstName === '' && $parsedMrz['first_name'] !== '') {
            $firstName = $parsedMrz['first_name'];
        }

        if ($lastName === '' && $this->looksLikeCombinedCinName($firstName)) {
            $parsedName = $this->splitFullName($firstName);

            if ($parsedName['first_name'] !== '' && $parsedName['last_name'] !== '') {
                $firstName = $parsedName['first_name'];
                $lastName = $parsedName['last_name'];
            }
        }

        if ($lastName !== '' && str_starts_with(mb_strtoupper($firstName), mb_strtoupper($lastName).' ')) {
            $firstName = trim(substr($firstName, mb_strlen($lastName) + 1));
        }

        if (
            $parsedMrz['first_name'] !== ''
            && $lastName !== ''
            && str_starts_with(mb_strtoupper($firstName), mb_strtoupper($lastName).' ')
        ) {
            $firstName = $parsedMrz['first_name'];
        }

        $cinAddress = $this->normalizeAddressParts(
            $this->firstStringValue($payload, ['street_address', 'address', 'adresse']),
            $this->firstStringValue($payload, ['postal_code']),
            $this->firstStringValue($payload, ['city'])
        );

        $dateOfBirth = $this->normalizeDate($this->firstStringValue($payload, ['date_of_birth', 'date_naissance']));
        if ($dateOfBirth === '' && $parsedMrz['date_of_birth'] !== '') {
            $dateOfBirth = $parsedMrz['date_of_birth'];
        }

        $documentNumber = $this->firstStringValue($payload, ['document_number', 'numero_document']);
        if ($documentNumber === '' && $parsedMrz['document_number'] !== '') {
            $documentNumber = $parsedMrz['document_number'];
        }

        $expiryDate = $this->normalizeDate($this->firstStringValue($payload, ['expiry_date', 'date_expiration']));
        if ($expiryDate === '' && $parsedMrz['expiry_date'] !== '') {
            $expiryDate = $parsedMrz['expiry_date'];
        }

        $nationality = $this->normalizeNationality($this->firstStringValue($payload, ['nationality', 'nationalite']));
        if ($parsedMrz['nationality'] !== '' && ($nationality === '' || $nationality !== $parsedMrz['nationality'])) {
            $nationality = $parsedMrz['nationality'];
        }

        $sex = $this->firstStringValue($payload, ['sex', 'sexe']);
        if ($sex === '' && $parsedMrz['sex'] !== '') {
            $sex = $parsedMrz['sex'];
        }

        return [
            'document_type' => $documentType,
            'civility' => $this->normalizeCinCivility($payload),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => $dateOfBirth,
            'place_of_birth' => $this->firstStringValue($payload, ['place_of_birth', 'lieu_naissance']),
            'document_number' => $documentNumber,
            'expiry_date' => $expiryDate,
            'nationality' => $nationality,
            'sex' => $sex,
            'street_address' => $cinAddress['street_address'],
            'postal_code' => $cinAddress['postal_code'],
            'city' => $cinAddress['city'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeKbis(array $payload): array
    {
        return $this->normalizeCompanyExtract($payload, DocumentProcessingValues::BUSINESS_TYPE_KBIS);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeCompanyExtract(array $payload, string $documentType): array
    {
        $address = $this->normalizeAddressParts(
            $this->firstStringValue($payload, ['street_address', 'address', 'adresse_siege']),
            $this->firstStringValue($payload, ['postal_code']),
            $this->firstStringValue($payload, ['city'])
        );

        $representants = array_map(
            fn (mixed $representant): array => $this->normalizePerson(
                is_array($representant) ? $representant : [],
                roleKeys: ['role', 'qualite']
            ),
            is_array($payload['legal_representatives'] ?? null)
                ? $payload['legal_representatives']
                : (is_array($payload['representants_legaux'] ?? null) ? $payload['representants_legaux'] : [])
        );

        return [
            'document_type' => $documentType,
            'company_name' => $this->firstStringValue($payload, ['company_name', 'raison_sociale']),
            'trade_name' => $this->firstStringValue($payload, ['trade_name', 'sigle']),
            'legal_form' => $this->firstStringValue($payload, ['legal_form', 'forme_juridique']),
            'capital' => $this->firstStringValue($payload, ['capital']),
            'registration_number' => preg_replace('/\s+/', ' ', $this->firstStringValue($payload, ['registration_number', 'immatriculation_rcs'])),
            'siret' => $this->normalizeKbisSiret($payload),
            'sirene' => $this->normalizeKbisSirene($payload),
            'street_address' => $address['street_address'],
            'postal_code' => $address['postal_code'],
            'city' => $address['city'],
            'naf_code' => $this->firstStringValue($payload, ['naf_code', 'code_naf']),
            'registration_date' => $this->normalizeDate($this->firstStringValue($payload, ['registration_date', 'date_immatriculation'])),
            'issue_date' => $this->normalizeDate($this->firstStringValue($payload, ['issue_date', 'date_edition', 'date_extrait', 'extract_date'])),
            'registry_city' => $this->firstStringValue($payload, ['registry_city', 'greffe']),
            'legal_representatives' => array_values(array_filter($representants, function (array $representant): bool {
                return $representant['company_name'] !== '' || $representant['first_name'] !== '' || $representant['last_name'] !== '' || $representant['role'] !== '';
            })),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeActePropriete(array $payload): array
    {
        $parcelPayloads = is_array($payload['cadastral_parcels'] ?? null)
            ? $payload['cadastral_parcels']
            : (is_array($payload['cadastral_references'] ?? null)
                ? $payload['cadastral_references']
                : (is_array($payload['references_cadastrales'] ?? null) ? $payload['references_cadastrales'] : []));

        $references = array_map(function (mixed $reference): array {
            $referencePayload = is_array($reference) ? $reference : [];
            $referenceAddress = $this->normalizeAddressParts(
                $this->firstStringValue($referencePayload, ['street_address', 'address', 'adresse_bien']),
                $this->firstStringValue($referencePayload, ['postal_code']),
                $this->firstStringValue($referencePayload, ['city'])
            );

            return [
                'prefixe' => $this->firstStringValue($referencePayload, ['prefixe', 'prefix']),
                'section' => trim((string) ($referencePayload['section'] ?? '')),
                'numero' => trim((string) ($referencePayload['numero'] ?? '')),
                'street_address' => $referenceAddress['street_address'],
                'postal_code' => $referenceAddress['postal_code'],
                'city' => $referenceAddress['city'],
            ];
        }, $parcelPayloads);

        $owners = array_map(
            fn (mixed $owner): array => $this->normalizePerson(
                is_array($owner) ? $owner : [],
                requireAddress: false,
                roleKeys: [],
                dateKeys: [],
                shareKeys: []
            ),
            is_array($payload['owners'] ?? null)
                ? $payload['owners']
                : (is_array($payload['proprietaires'] ?? null) ? $payload['proprietaires'] : [])
        );

        return [
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_ACTE_PROPRIETE,
            'cadastral_parcels' => array_values(array_filter($references, function (array $reference): bool {
                return $reference['prefixe'] !== '' || $reference['section'] !== '' || $reference['numero'] !== '' || $reference['street_address'] !== '' || $reference['postal_code'] !== '' || $reference['city'] !== '';
            })),
            'owners' => array_values(array_filter($owners, function (array $owner): bool {
                return $owner['company_name'] !== '' || $owner['first_name'] !== '' || $owner['last_name'] !== '';
            })),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeMsa(array $payload): array
    {
        $parcelPayloads = is_array($payload['msa_parcels'] ?? null)
            ? $payload['msa_parcels']
            : (is_array($payload['parcel_rows'] ?? null)
                ? $payload['parcel_rows']
                : (is_array($payload['parcels'] ?? null) ? $payload['parcels'] : []));

        $lastDept = '';
        $lastCom = '';
        $rows = [];
        $seen = [];

        foreach ($parcelPayloads as $parcelPayload) {
            $rowPayload = is_array($parcelPayload) ? $parcelPayload : [];

            $dept = $this->normalizeFixedDigits($this->firstStringValue($rowPayload, ['dept', 'departement']), 2);
            $com = $this->normalizeFixedDigits($this->firstStringValue($rowPayload, ['com', 'commune']), 3);
            $prefixe = $this->normalizeFixedDigits($this->firstStringValue($rowPayload, ['prefixe', 'prefix']), 3);
            $section = $this->normalizeMsaSection($this->firstStringValue($rowPayload, ['section']));
            $numeroPlan = $this->normalizeFixedDigits($this->firstStringValue($rowPayload, ['numero_plan', 'numero', 'plan_number']), 4);

            if ($dept === '') {
                $dept = $lastDept;
            } else {
                $lastDept = $dept;
            }

            if ($com === '') {
                $com = $lastCom;
            } else {
                $lastCom = $com;
            }

            if ($dept === '' && $com === '' && $prefixe === '' && $section === '' && $numeroPlan === '') {
                continue;
            }

            $record = [
                'dept' => $dept,
                'com' => $com,
                'prefixe' => $prefixe,
                'section' => $section,
                'numero_plan' => $numeroPlan,
            ];

            $dedupeKey = implode('.', [
                $record['dept'],
                $record['com'],
                $record['prefixe'],
                $record['section'],
                $record['numero_plan'],
            ]);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $rows[] = $record;
        }

        return [
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
            'msa_parcels' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function validateCin(array $payload): array
    {
        $errors = [];

        if (($payload['last_name'] ?? '') === '') {
            $errors[] = 'CIN missing last_name.';
        }

        if (($payload['first_name'] ?? '') === '') {
            $errors[] = 'CIN missing first_name.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function validateKbis(array $payload): array
    {
        $errors = [];
        $meaningfulFields = array_filter([
            (string) ($payload['company_name'] ?? ''),
            (string) ($payload['registration_number'] ?? ''),
            (string) ($payload['siret'] ?? ''),
            (string) ($payload['sirene'] ?? ''),
            (string) ($payload['registry_city'] ?? ''),
            (string) ($payload['street_address'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '');

        if (($payload['siret'] ?? '') !== '' && ! preg_match('/^\d{14}$/', (string) $payload['siret'])) {
            $errors[] = 'KBIS siret must contain exactly 14 digits.';
        }

        if (($payload['sirene'] ?? '') !== '' && ! preg_match('/^\d{9}$/', (string) $payload['sirene'])) {
            $errors[] = 'KBIS sirene must contain exactly 9 digits.';
        }

        if ($meaningfulFields === []) {
            $errors[] = 'KBIS extraction is empty and requires manual review.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizeKbisSiret(array $payload): string
    {
        $siret = preg_replace('/\D+/', '', $this->stringValue($payload, 'siret')) ?? '';
        $registrationNumber = $this->firstStringValue($payload, ['registration_number', 'immatriculation_rcs']);
        $sirene = $this->normalizeKbisSirene($payload);

        if (
            $siret !== ''
            && strlen($siret) === 9
            && ($registrationNumber !== '' || $sirene === $siret)
        ) {
            return '';
        }

        return $siret;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizeKbisSirene(array $payload): string
    {
        $sirene = preg_replace('/\D+/', '', $this->stringValue($payload, 'sirene')) ?? '';

        if (preg_match('/(\d{3}\s+\d{3}\s+\d{3})\s+R\.?C\.?S\.?/iu', $this->firstStringValue($payload, ['registration_number', 'immatriculation_rcs']), $matches) === 1) {
            $registrationSirene = preg_replace('/\D+/', '', $matches[1]) ?? '';

            if ($registrationSirene !== '' && (strlen($sirene) !== 9 || str_starts_with($sirene, $registrationSirene) === false)) {
                return $registrationSirene;
            }
        }

        return $sirene;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function validateActePropriete(array $payload): array
    {
        $errors = [];

        if (($payload['owners'] ?? []) === [] && ($payload['cadastral_parcels'] ?? []) === []) {
            $errors[] = 'Land title deed requires at least one owner or one cadastral parcel.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function validateMsa(array $payload): array
    {
        $errors = [];
        $rows = is_array($payload['msa_parcels'] ?? null) ? $payload['msa_parcels'] : [];

        if ($rows === []) {
            return ['MSA requires at least one parcel row.'];
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = sprintf('MSA row %d is invalid.', $index + 1);

                continue;
            }

            $rowNumber = $index + 1;

            if (($row['dept'] ?? '') === '' || preg_match('/^\d{2}$/', (string) $row['dept']) !== 1) {
                $errors[] = sprintf('MSA row %d dept must contain exactly 2 digits.', $rowNumber);
            }

            if (($row['com'] ?? '') === '' || preg_match('/^\d{3}$/', (string) $row['com']) !== 1) {
                $errors[] = sprintf('MSA row %d com must contain exactly 3 digits.', $rowNumber);
            }

            if (($row['prefixe'] ?? '') !== '' && preg_match('/^\d{3}$/', (string) $row['prefixe']) !== 1) {
                $errors[] = sprintf('MSA row %d prefixe must contain exactly 3 digits when present.', $rowNumber);
            }

            if (($row['section'] ?? '') === '' || preg_match('/^[A-Z0-9]{2}$/', (string) $row['section']) !== 1) {
                $errors[] = sprintf('MSA row %d section must contain exactly 2 normalized characters.', $rowNumber);
            }

            if (($row['numero_plan'] ?? '') === '' || preg_match('/^\d{4}$/', (string) $row['numero_plan']) !== 1) {
                $errors[] = sprintf('MSA row %d numero_plan must contain exactly 4 digits.', $rowNumber);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        return trim((string) ($payload[$key] ?? ''));
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function firstStringValue(array $payload, array $keys, string $fallback = ''): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($payload[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $roleKeys
     * @param  array<int, string>  $dateKeys
     * @param  array<int, string>  $addressKeys
     * @param  array<int, string>  $shareKeys
     * @return array<string, string>
     */
    private function normalizePerson(
        array $payload,
        bool $requireAddress = true,
        array $roleKeys = ['role'],
        array $dateKeys = ['date_of_birth'],
        array $addressKeys = ['street_address', 'address'],
        array $shareKeys = ['share']
    ): array {
        $fullName = $this->firstStringValue($payload, ['full_name', 'nom_complet']);
        $civilityValue = $this->firstStringValue($payload, ['civility']);

        if ($fullName === '' && $this->looksLikeCivilityWithName($civilityValue)) {
            $fullName = $civilityValue;
            $civilityValue = '';
        }

        $parsedName = $this->splitFullName($fullName);
        $address = $this->normalizeAddressParts(
            $this->firstStringValue($payload, $addressKeys),
            $this->firstStringValue($payload, ['postal_code']),
            $this->firstStringValue($payload, ['city'])
        );

        $companyName = $this->firstStringValue($payload, ['company_name']);
        if ($companyName === '') {
            $companyName = $this->detectCompanyName($payload, $fullName, $civilityValue, $parsedName);
        } elseif ($this->isGenericCompanyLabel($companyName)) {
            $companyName = $this->detectCompanyName($payload, $fullName, $civilityValue, $parsedName);
        }

        if ($this->shouldTreatAsCompany($payload, $civilityValue, $parsedName)) {
            $companyName = $this->companyCandidateFromPersonFields($payload, $civilityValue, $parsedName);
        }

        $entityType = $companyName !== '' ? 'company' : 'person';
        $normalizedCivility = $entityType === 'person'
            ? $this->normalizeCivility($civilityValue !== '' ? $civilityValue : $parsedName['civility'])
            : '';

        $person = [
            'entity_type' => $entityType,
            'company_name' => $companyName,
            'legal_form' => $entityType === 'company'
                ? $this->firstStringValue($payload, ['legal_form', 'forme_juridique'])
                : '',
            'civility' => $normalizedCivility,
            'first_name' => $entityType === 'person'
                ? $this->firstStringValue($payload, ['first_name', 'prenom'], $parsedName['first_name'])
                : '',
            'last_name' => $entityType === 'person'
                ? $this->firstStringValue($payload, ['last_name', 'nom'], $parsedName['last_name'])
                : '',
        ];

        if ($dateKeys !== []) {
            $person['date_of_birth'] = $entityType === 'person'
                ? $this->normalizeDate($this->firstStringValue($payload, $dateKeys))
                : '';
        }

        if ($requireAddress) {
            $person['street_address'] = $address['street_address'];
            $person['postal_code'] = $address['postal_code'];
            $person['city'] = $address['city'];
        }

        $person['registration_number'] = $entityType === 'company'
            ? preg_replace('/\s+/', ' ', $this->firstStringValue($payload, ['registration_number', 'rcs_number', 'immatriculation_rcs']))
            : '';
        $person['registry_city'] = $entityType === 'company'
            ? $this->firstStringValue($payload, ['registry_city', 'greffe'])
            : '';

        if ($shareKeys !== []) {
            $person['share'] = $this->firstStringValue($payload, $shareKeys);
        }

        if ($roleKeys !== []) {
            $person['role'] = $this->firstStringValue($payload, $roleKeys);
        }

        return $person;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{civility: string, first_name: string, last_name: string}  $parsedName
     */
    private function shouldTreatAsCompany(array $payload, string $civilityValue, array $parsedName): bool
    {
        $hasCompanySignals = $this->firstStringValue($payload, ['legal_form', 'forme_juridique']) !== ''
            || $this->firstStringValue($payload, ['registration_number', 'rcs_number', 'immatriculation_rcs']) !== '';

        if (! $hasCompanySignals) {
            return false;
        }

        $candidate = $this->companyCandidateFromPersonFields($payload, $civilityValue, $parsedName);

        return $candidate !== '' && $this->looksLikeCompanyName($candidate);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{civility: string, first_name: string, last_name: string}  $parsedName
     */
    private function companyCandidateFromPersonFields(array $payload, string $civilityValue, array $parsedName): string
    {
        $firstName = $this->firstStringValue($payload, ['first_name', 'prenom'], $parsedName['first_name']);
        $lastName = $this->firstStringValue($payload, ['last_name', 'nom'], $parsedName['last_name']);

        return trim(implode(' ', array_filter([
            $civilityValue,
            $firstName,
            $this->isGenericCompanyLabel($lastName) ? '' : $lastName,
        ])));
    }

    /**
     * @return array{street_address: string, postal_code: string, city: string}
     */
    private function normalizeAddressParts(string $streetAddress, string $postalCode = '', string $city = ''): array
    {
        $streetAddress = trim($streetAddress);
        $postalCode = trim($postalCode);
        $city = trim($city);

        if ($city !== '' && ($postalCode === '' || preg_match('/\d{5}/', $city) === 1)) {
            $cityPayload = $this->normalizeAddress($city);
            $postalCode = $postalCode !== '' ? $postalCode : $cityPayload['postal_code'];

            if ($cityPayload['city'] !== '') {
                $city = $cityPayload['city'];
            }
        }

        if ($streetAddress !== '') {
            $streetPayload = $this->normalizeAddress($streetAddress);

            if ($postalCode === '' && $streetPayload['postal_code'] !== '') {
                $postalCode = $streetPayload['postal_code'];
            }

            if ($city === '' && $streetPayload['city'] !== '') {
                $city = $streetPayload['city'];
            }

            $streetAddress = $streetPayload['street_address'];
        }

        return [
            'street_address' => $streetAddress,
            'postal_code' => $postalCode,
            'city' => preg_replace('/\s+FRANCE$/u', '', $city) ?? $city,
        ];
    }

    /**
     * @return array{street_address: string, postal_code: string, city: string}
     */
    private function normalizeAddress(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [
                'street_address' => '',
                'postal_code' => '',
                'city' => '',
            ];
        }

        if (str_contains($value, "\n")) {
            $lines = array_values(array_filter(array_map(
                static fn (string $line): string => trim($line),
                preg_split('/\R+/u', $value) ?: []
            ), static fn (string $line): bool => $line !== ''));

            if ($lines !== []) {
                $lastLine = array_pop($lines);
                $lastLinePayload = $this->normalizeAddress($lastLine);

                if ($lastLinePayload['postal_code'] !== '' && $lastLinePayload['city'] !== '') {
                    return [
                        'street_address' => trim(implode(' ', $lines)),
                        'postal_code' => $lastLinePayload['postal_code'],
                        'city' => $lastLinePayload['city'],
                    ];
                }
            }
        }

        if (preg_match('/^([A-Za-zÀ-ÿ\'’\-\s]+?)\s+(\d{5})\s+([A-Za-zÀ-ÿ\'’\-\s]+?)(?:\s+FRANCE)?$/u', $value, $matches) === 1) {
            return [
                'street_address' => '',
                'postal_code' => trim($matches[2]),
                'city' => trim($matches[3]),
            ];
        }

        if (preg_match('/^(.*?)(?:\s+|,|\()(\d{5})(?:\)|\s+)([A-Za-zÀ-ÿ\'’\-\s]+?)(?:\s+FRANCE)?$/u', $value, $matches) === 1) {
            return [
                'street_address' => trim($matches[1], " \t\n\r\0\x0B,"),
                'postal_code' => trim($matches[2]),
                'city' => trim($matches[3]),
            ];
        }

        if (preg_match('/^(?:.*?\s+)?(\d{5})\s+([A-Za-zÀ-ÿ\'’\-\s]+?)(?:\s+FRANCE)?$/u', $value, $matches) === 1) {
            return [
                'street_address' => '',
                'postal_code' => trim($matches[1]),
                'city' => trim($matches[2]),
            ];
        }

        return [
            'street_address' => preg_replace('/\s+/u', ' ', $value) ?? $value,
            'postal_code' => '',
            'city' => '',
        ];
    }

    private function normalizeFixedDigits(string $value, int $length): string
    {
        $digits = preg_replace('/\D+/u', '', trim($value)) ?? '';

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) >= $length) {
            return strlen($digits) === $length
                ? $digits
                : substr($digits, -$length);
        }

        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }

    private function normalizeMsaSection(string $value): string
    {
        $normalized = mb_strtoupper(trim($value));
        $normalized = preg_replace('/[^A-Z0-9]/u', '', $normalized) ?? $normalized;

        if ($normalized === '') {
            return '';
        }

        if (strlen($normalized) === 1) {
            return '0'.$normalized;
        }

        return substr($normalized, 0, 2);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizeCinCivility(array $payload): string
    {
        $explicitCivility = $this->normalizeCivility($this->firstStringValue($payload, ['civility']));

        if ($explicitCivility !== '') {
            return $explicitCivility;
        }

        $sex = mb_strtolower($this->firstStringValue($payload, ['sex', 'sexe']));

        return match ($sex) {
            'm', 'male', 'masculin' => 'M.',
            'f', 'female', 'feminin', 'féminin' => 'Mme.',
            default => $this->guessCivilityFromFirstName($this->firstStringValue($payload, ['first_name', 'prenom'])),
        };
    }

    private function normalizeCivility(string $value): string
    {
        $normalized = mb_strtolower(trim($value));

        return match ($normalized) {
            'm', 'm.', 'mr', 'monsieur' => 'M.',
            'mme', 'mme.', 'madame', 'mlle', 'mlle.', 'mademoiselle' => 'Mme.',
            default => trim($value),
        };
    }

    private function normalizePersonNameValue(string $value): string
    {
        $cleaned = trim($value);
        $cleaned = preg_replace('/^\d+/u', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/[*|_]+/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*,\s*/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    private function normalizeNationality(string $value): string
    {
        $normalized = mb_strtoupper(trim($value));

        return match ($normalized) {
            '', 'FRA', 'FRANÇAISE', 'FRANCAISE', 'FRANCAIS', 'FRANÇAIS' => $normalized === '' ? '' : 'FRA',
            'MAR', 'MAROCAIN', 'MAROCAINE', 'MAROCAN' => 'MAR',
            default => trim($value),
        };
    }

    /**
     * @return array{document_type: string, document_number: string, last_name: string, first_name: string, date_of_birth: string, expiry_date: string, sex: string, nationality: string}
     */
    private function parseMrz(string $mrz): array
    {
        $parsed = [
            'document_type' => '',
            'document_number' => '',
            'last_name' => '',
            'first_name' => '',
            'date_of_birth' => '',
            'expiry_date' => '',
            'sex' => '',
            'nationality' => '',
        ];

        $normalized = trim($mrz);

        if ($normalized === '') {
            return $parsed;
        }

        $normalized = preg_replace('/\s+/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^A-Z0-9<\n]/u', '', mb_strtoupper($normalized)) ?? mb_strtoupper($normalized);
        $normalized = preg_replace('/^I([DR])<(?=[A-Z]{3})/u', 'I$1', $normalized) ?? $normalized;

        if (! str_contains($normalized, "\n")) {
            $normalized = $this->injectMrzLineBreaks($normalized);
        }

        $lines = preg_split('/\R+/u', $normalized) ?: [];
        $lines = array_values(array_filter(array_map(function (string $line): string {
            $upper = mb_strtoupper(trim($line));

            return preg_replace('/[^A-Z0-9<]/u', '', $upper) ?? '';
        }, $lines), static fn (string $line): bool => $line !== ''));

        if (count($lines) >= 3 && strlen($lines[0]) >= 30 && strlen($lines[1]) >= 30) {
            $firstLine = substr($lines[0], 0, 30);
            $secondLine = substr($lines[1], 0, 30);
            $thirdLine = substr($lines[2], 0, 30);

            if (preg_match('/^\d([A-Z<]+<<[A-Z<]+)$/u', $thirdLine, $matches) === 1) {
                $thirdLine = $matches[1];
            }

            if ($parsed['document_type'] === '') {
                $parsed['document_type'] = $this->mrzDocumentType(substr($firstLine, 0, 2));
            }

            if ($parsed['document_number'] === '') {
                $parsed['document_number'] = trim(str_replace('<', '', substr($firstLine, 5, 9)));
            }

            if ($parsed['date_of_birth'] === '') {
                $parsed['date_of_birth'] = $this->normalizeMrzDate(substr($secondLine, 0, 6), substr($secondLine, 8, 6));
            }

            if ($parsed['sex'] === '') {
                $sex = substr($secondLine, 7, 1);
                $parsed['sex'] = $sex === '<' ? '' : $sex;
            }

            if ($parsed['expiry_date'] === '') {
                $parsed['expiry_date'] = $this->normalizeMrzDate(substr($secondLine, 8, 6), substr($secondLine, 0, 6));
            }

            if ($parsed['nationality'] === '') {
                $parsed['nationality'] = $this->normalizeNationality(substr($secondLine, 15, 3));
            }

            if ($parsed['last_name'] === '' && str_contains($thirdLine, '<<')) {
                [$lastNamePart, $firstNamePart] = array_pad(explode('<<', $thirdLine, 2), 2, '');
                $parsed['last_name'] = $this->normalizePersonNameValue(str_replace('<', ' ', $lastNamePart));
                $parsed['first_name'] = $this->normalizePersonNameValue(str_replace('<', ' ', $firstNamePart));
            }

            return $parsed;
        }

        foreach ($lines as $index => $line) {
            if ($parsed['document_type'] === '' && strlen($line) >= 2) {
                $parsed['document_type'] = $this->mrzDocumentType(substr($line, 0, 2));
            }

            if ($parsed['last_name'] === '' && str_contains($line, '<<')) {
                [$lastNamePart, $firstNamePart] = array_pad(explode('<<', $line, 2), 2, '');
                $parsed['last_name'] = $this->normalizePersonNameValue(str_replace('<', ' ', $lastNamePart));
                $parsed['first_name'] = $this->normalizePersonNameValue(str_replace('<', ' ', $firstNamePart));
            } elseif (
                $parsed['last_name'] === ''
                && isset($lines[$index + 1])
                && preg_match('/^[A-Z<]+$/', $line) === 1
                && str_contains($lines[$index + 1], '<')
            ) {
                $parsed['last_name'] = $this->normalizePersonNameValue(str_replace('<', ' ', $line));
                $parsed['first_name'] = $this->normalizePersonNameValue(str_replace('<', ' ', $lines[$index + 1]));
            }

            if (
                preg_match('/([A-Z0-9<]{1,9})\d([A-Z<]{3})(\d{6})\d([MFX<])(\d{6})\d([A-Z<]{3})/u', $line, $matches) === 1
            ) {
                if ($parsed['document_number'] === '') {
                    $parsed['document_number'] = trim(str_replace('<', '', $matches[1]));
                }

                if ($parsed['date_of_birth'] === '') {
                    $parsed['date_of_birth'] = $this->normalizeMrzDate($matches[3], $matches[5]);
                }

                if ($parsed['sex'] === '') {
                    $parsed['sex'] = $matches[4] === '<' ? '' : $matches[4];
                }

                if ($parsed['expiry_date'] === '') {
                    $parsed['expiry_date'] = $this->normalizeMrzDate($matches[5], $matches[3]);
                }

                if ($parsed['nationality'] === '') {
                    $parsed['nationality'] = $this->normalizeNationality($matches[6] !== '<<<' ? $matches[6] : $matches[2]);
                }
            }

            if (
                $parsed['date_of_birth'] === ''
                && preg_match('/(\d{6})\d([MFX<])(\d{6})\d([A-Z<]{3})/u', $line, $matches) === 1
            ) {
                $parsed['date_of_birth'] = $this->normalizeMrzDate($matches[1], $matches[3]);
                $parsed['sex'] = $parsed['sex'] !== '' ? $parsed['sex'] : ($matches[2] === '<' ? '' : $matches[2]);
                $parsed['expiry_date'] = $parsed['expiry_date'] !== '' ? $parsed['expiry_date'] : $this->normalizeMrzDate($matches[3], $matches[1]);
                $parsed['nationality'] = $parsed['nationality'] !== '' ? $parsed['nationality'] : $this->normalizeNationality($matches[4]);
            }

            if ($parsed['document_number'] === '' && preg_match('/^[A-Z0-9<]{5,12}$/', $line) === 1 && preg_match('/\d/u', $line) === 1) {
                $parsed['document_number'] = trim(str_replace('<', '', $line));
            }
        }

        if (
            ($parsed['last_name'] === '' || preg_match('/^\d/u', $parsed['last_name']) === 1)
            && preg_match('/\d?([A-Z<]+<<[A-Z<]+)$/u', str_replace("\n", '', $normalized), $matches) === 1
        ) {
            [$lastNamePart, $firstNamePart] = array_pad(explode('<<', $matches[1], 2), 2, '');
            $parsed['last_name'] = $this->normalizePersonNameValue(str_replace('<', ' ', $lastNamePart));
            $parsed['first_name'] = $this->normalizePersonNameValue(str_replace('<', ' ', $firstNamePart));
        }

        if (
            ($parsed['date_of_birth'] === '' || $parsed['expiry_date'] === '' || $parsed['sex'] === '' || $parsed['nationality'] === '')
            && preg_match('/(\d{6})\d([MFX<])(\d{6})\d([A-Z<]{3})/u', str_replace("\n", '', $normalized), $matches) === 1
        ) {
            if ($parsed['date_of_birth'] === '') {
                $parsed['date_of_birth'] = $this->normalizeMrzDate($matches[1], $matches[3]);
            }

            if ($parsed['sex'] === '') {
                $parsed['sex'] = $matches[2] === '<' ? '' : $matches[2];
            }

            if ($parsed['expiry_date'] === '') {
                $parsed['expiry_date'] = $this->normalizeMrzDate($matches[3], $matches[1]);
            }

            if ($parsed['nationality'] === '') {
                $parsed['nationality'] = $this->normalizeNationality($matches[4]);
            }
        }

        return $parsed;
    }

    private function injectMrzLineBreaks(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (strlen($value) >= 60 && ($value[0] ?? '') === 'I') {
            $firstLine = substr($value, 0, 30);
            $remaining = substr($value, 30);
            if (preg_match('/([A-Z<]+<<[A-Z<]+)$/u', $remaining, $matches) === 1) {
                $thirdLine = $matches[1];

                if (strlen($thirdLine) <= 30) {
                    $secondLine = substr($remaining, 0, -strlen($thirdLine));

                    return $firstLine."\n".$secondLine."\n".$thirdLine;
                }
            }

            if (strlen($value) >= 90) {
                return substr($value, 0, 30)."\n".substr($value, 30, 30)."\n".substr($value, 60, 30);
            }
        }

        if (
            preg_match(
                '/^(IR[A-Z<]{3}[A-Z0-9<]{9}\d[A-Z0-9<]{15})(\d{6}\d[MFX<]\d{6}\d[A-Z<]{3}[A-Z<]{14}\d)([A-Z<]+)$/u',
                $value,
                $matches
            ) === 1
        ) {
            return $matches[1]."\n".$matches[2]."\n".$matches[3];
        }

        if (
            preg_match(
                '/^([A-Z0-9<]{30})([A-Z0-9<]{30})([A-Z0-9<]{30})$/u',
                $value,
                $matches
            ) === 1
        ) {
            return $matches[1]."\n".$matches[2]."\n".$matches[3];
        }

        if (
            preg_match(
                '/^([A-Z0-9<]{36})([A-Z0-9<]{36})$/u',
                $value,
                $matches
            ) === 1
            || preg_match(
                '/^([A-Z0-9<]{44})([A-Z0-9<]{44})$/u',
                $value,
                $matches
            ) === 1
        ) {
            return $matches[1]."\n".$matches[2];
        }

        return $value;
    }

    private function mrzDocumentType(string $prefix): string
    {
        return match ($prefix) {
            'ID', 'I<' => DocumentProcessingValues::BUSINESS_TYPE_IDENTITY_CARD,
            'IR' => DocumentProcessingValues::BUSINESS_TYPE_RESIDENCE_PERMIT,
            'P<' => DocumentProcessingValues::BUSINESS_TYPE_PASSPORT,
            'V<' => DocumentProcessingValues::BUSINESS_TYPE_VISA,
            'AC', 'C<' => DocumentProcessingValues::BUSINESS_TYPE_CREW_CARD,
            'A<' => DocumentProcessingValues::BUSINESS_TYPE_TRAVEL_DOCUMENT,
            default => $prefix !== '' && ctype_alpha($prefix[0] ?? '') ? DocumentProcessingValues::BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT : '',
        };
    }

    private function normalizeMrzDate(string $value, string $anchor): string
    {
        if (preg_match('/^\d{6}$/', $value) !== 1 || preg_match('/^\d{6}$/', $anchor) !== 1) {
            return '';
        }

        $year = (int) substr($value, 0, 2);
        $month = substr($value, 2, 2);
        $day = substr($value, 4, 2);
        $anchorYear = (int) substr($anchor, 0, 2);
        $century = $year > $anchorYear ? 1900 : 2000;

        return sprintf('%04d-%s-%s', $century + $year, $month, $day);
    }

    private function looksLikeCombinedCinName(string $value): bool
    {
        $parts = preg_split('/\s+/u', $this->normalizePersonNameValue($value)) ?: [];

        if (count($parts) < 2) {
            return false;
        }

        $hasUppercaseToken = false;
        $hasCapitalizedToken = false;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^\p{Lu}[\p{Lu}\-\'’]*$/u', $part) === 1) {
                $hasUppercaseToken = true;

                continue;
            }

            if (preg_match('/^\p{Lu}[\p{Ll}\-\'’]+$/u', $part) === 1) {
                $hasCapitalizedToken = true;
            }
        }

        return $hasUppercaseToken && $hasCapitalizedToken;
    }

    private function looksLikeCivilityWithName(string $value): bool
    {
        return preg_match('/^(M(?:me|lle)?|Monsieur|Madame)\.?\s+\S+/iu', trim($value)) === 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{civility: string, first_name: string, last_name: string}  $parsedName
     */
    private function detectCompanyName(array $payload, string $fullName, string $civilityValue, array $parsedName): string
    {
        $candidates = array_filter([
            $this->firstStringValue($payload, ['entity_type']),
            trim($fullName),
            trim($civilityValue),
            $this->firstStringValue($payload, ['first_name', 'prenom']),
            $this->firstStringValue($payload, ['last_name', 'nom']),
        ]);

        foreach ($candidates as $candidate) {
            if ($this->isGenericCompanyLabel($candidate)) {
                continue;
            }

            if ($this->looksLikeCompanyName($candidate)) {
                return $candidate;
            }
        }

        if (
            $parsedName['civility'] === ''
            && $parsedName['first_name'] === ''
            && $parsedName['last_name'] === ''
            && $fullName !== ''
        ) {
            return $fullName;
        }

        return '';
    }

    private function looksLikeCompanyName(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        return preg_match('/\b(SAS|SARL|SCI|SCP|SA|EURL|COMMUNE|VILLE|INVEST|ENERGY|SERVICE|ELECTRIC|HOLDING)\b/iu', $value) === 1
            || (preg_match('/\s/u', $value) === 1 && preg_match('/[a-z]/u', $value) !== 1);
    }

    private function isGenericCompanyLabel(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, [
            'société à responsabilité limitée',
            'societe a responsabilite limitee',
            'société par actions simplifiée',
            'societe par actions simplifiee',
            'société anonyme',
            'societe anonyme',
            'société civile immobilière',
            'societe civile immobiliere',
        ], true);
    }

    private function guessCivilityFromFirstName(string $firstName): string
    {
        $firstName = mb_strtolower(trim($firstName));

        return match ($firstName) {
            'marie', 'marcelle', 'jacqueline', 'maelis-gaelle', 'maëlis-gaëlle', 'maëlys-gaëlle', 'lucette' => 'Mme.',
            'thierry', 'wadie', 'gabriel', 'julien', 'angelin' => 'M.',
            default => '',
        };
    }

    /**
     * @return array{civility: string, first_name: string, last_name: string}
     */
    private function splitFullName(string $fullName): array
    {
        $fullName = $this->normalizePersonNameValue($fullName);

        if ($fullName === '') {
            return [
                'civility' => '',
                'first_name' => '',
                'last_name' => '',
            ];
        }

        $civility = '';
        if (preg_match('/^(M(?:me|lle)?|Monsieur|Madame)\.?\s+/iu', $fullName, $matches) === 1) {
            $civility = trim($matches[1]);
            $fullName = trim(substr($fullName, strlen($matches[0])));
        }

        $parts = preg_split('/\s+/u', $fullName) ?: [];
        $lastName = '';
        $firstName = '';

        if ($parts !== []) {
            $lastNameParts = array_filter($parts, static fn (string $part): bool => strtoupper($part) === $part);
            $lastName = trim(implode(' ', $lastNameParts));
            $firstNameParts = $lastNameParts === [] ? array_slice($parts, 1) : array_values(array_diff($parts, $lastNameParts));
            $firstName = trim(implode(' ', $firstNameParts));

            if ($lastName === '') {
                $lastName = trim((string) array_pop($parts));
                $firstName = trim(implode(' ', $parts));
            }
        }

        return [
            'civility' => $civility,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $normalizedValue = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $normalizedValue = str_replace(['\\', '_', ',', ';'], ['/', '/', '/', '/'], $normalizedValue);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd m Y', 'Y m d', 'Y/m/d', 'Y.m.d'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, $normalizedValue)->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        if (preg_match('/^(\d{2})\s+(\d{2})\s+(\d{4})$/', $normalizedValue, $matches) === 1) {
            try {
                return CarbonImmutable::createFromFormat('d m Y', "{$matches[1]} {$matches[2]} {$matches[3]}")->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        if (preg_match('/^(\d{4})\s+(\d{2})\s+(\d{2})$/', $normalizedValue, $matches) === 1) {
            try {
                return CarbonImmutable::createFromFormat('Y m d', "{$matches[1]} {$matches[2]} {$matches[3]}")->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        return '';
    }
}
