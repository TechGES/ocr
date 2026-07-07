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
     * @param  array<int, mixed>  $llmParcels
     * @param  array<int, array{dept: string, com: string, prefixe: string, section: string, numero_plan: string}>  $textParcels
     * @return array<int, mixed>
     */
    private function mergeMsaParcels(array $llmParcels, array $textParcels): array
    {
        $llmParcels = $this->filterSuspiciousMsaLlmParcels($llmParcels);

        if ($llmParcels === []) {
            return array_values($textParcels);
        }

        if ($textParcels === []) {
            return array_values($llmParcels);
        }

        $merged = [];
        $seen = [];

        foreach (array_merge($llmParcels, $textParcels) as $parcel) {
            if (! is_array($parcel)) {
                continue;
            }

            $dept = (string) ($parcel['dept'] ?? '');
            $com = (string) ($parcel['com'] ?? '');
            $prefixe = (string) ($parcel['prefixe'] ?? '');
            $section = (string) ($parcel['section'] ?? '');
            $numeroPlan = (string) ($parcel['numero_plan'] ?? '');

            if ($dept === '' || $com === '' || $section === '' || $numeroPlan === '') {
                continue;
            }

            $key = $dept.'|'.$com.'|'.$prefixe.'|'.$section.'|'.$numeroPlan;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $parcel;
        }

        return $this->removeConflictingMsaPrefixes($merged, $textParcels);
    }

    /**
     * @param  array<int, mixed>  $parcels
     * @param  array<int, array{dept: string, com: string, prefixe: string, section: string, numero_plan: string}>  $textParcels
     * @return array<int, mixed>
     */
    private function removeConflictingMsaPrefixes(array $parcels, array $textParcels = []): array
    {
        $suspectPrefixes = ['001', '011', '048', '064', '102'];

        $normalizedParcels = [];

        foreach ($parcels as $parcel) {
            if (! is_array($parcel)) {
                continue;
            }

            $dept = trim((string) ($parcel['dept'] ?? ''));
            $com = trim((string) ($parcel['com'] ?? ''));
            $prefixe = trim((string) ($parcel['prefixe'] ?? ''));
            $section = mb_strtoupper(trim((string) ($parcel['section'] ?? '')));
            $numeroPlan = trim((string) ($parcel['numero_plan'] ?? ''));

            if ($prefixe === '000') {
                $prefixe = '';
            }

            if ($dept === '' || $com === '' || $section === '' || $numeroPlan === '') {
                continue;
            }

            $normalizedParcels[] = [
                'dept' => $dept,
                'com' => $com,
                'prefixe' => $prefixe,
                'section' => $section,
                'numero_plan' => $numeroPlan,
            ];
        }

        $prefixHints = [];

        foreach ($normalizedParcels as $parcel) {
            $dept = $parcel['dept'];
            $com = $parcel['com'];
            $prefixe = $parcel['prefixe'];
            $section = $parcel['section'];
            $numeroPlan = $parcel['numero_plan'];

            // Cas MSA fréquent : le prefixe est lu, mais aussi utilisé à tort comme COM.
            // Exemple: 85 224 224 0G 0949 est une ligne support pour 85 146 224 0G 0949.
            if ($prefixe !== '' && ! in_array($prefixe, $suspectPrefixes, true) && $com === $prefixe) {
                $prefixHints[$dept.'|'.$section.'|'.$numeroPlan] = $prefixe;
            }
        }

        $enriched = [];

        foreach ($normalizedParcels as $parcel) {
            $hintKey = $parcel['dept'].'|'.$parcel['section'].'|'.$parcel['numero_plan'];

            if (
                $parcel['prefixe'] === ''
                && isset($prefixHints[$hintKey])
                && $parcel['com'] !== $prefixHints[$hintKey]
            ) {
                $parcel['prefixe'] = $prefixHints[$hintKey];
            }

            $enriched[] = $parcel;
        }

        $prefixesBySectionNumber = [];
        $prefixesByDeptSectionNumber = [];

        foreach ($enriched as $parcel) {
            $sectionNumberKey = $parcel['section'].'|'.$parcel['numero_plan'];
            $deptSectionNumberKey = $parcel['dept'].'|'.$parcel['section'].'|'.$parcel['numero_plan'];

            $prefixesBySectionNumber[$sectionNumberKey][$parcel['prefixe']] = true;
            $prefixesByDeptSectionNumber[$deptSectionNumberKey][$parcel['prefixe']] = true;
        }

        $filtered = [];

        foreach ($enriched as $parcel) {
            $dept = $parcel['dept'];
            $com = $parcel['com'];
            $prefixe = $parcel['prefixe'];
            $section = $parcel['section'];
            $numeroPlan = $parcel['numero_plan'];

            $sectionNumberKey = $section.'|'.$numeroPlan;
            $deptSectionNumberKey = $dept.'|'.$section.'|'.$numeroPlan;

            $sectionNumberPrefixes = array_keys($prefixesBySectionNumber[$sectionNumberKey] ?? []);
            $deptSectionNumberPrefixes = array_keys($prefixesByDeptSectionNumber[$deptSectionNumberKey] ?? []);

            $hasEmptyForSameSectionNumber = in_array('', $sectionNumberPrefixes, true);
            $hasTrustedPrefixForSameDept = count(array_diff(
                array_filter($deptSectionNumberPrefixes, fn (string $candidate): bool => $candidate !== ''),
                $suspectPrefixes
            )) > 0;

            // Cas 1 : si une même section/numéro existe avec prefixe vide,
            // les préfixes propriétaires connus comme suspects sont écartés,
            // même si dept/com diffèrent.
            // Exemple: garder 85/216/ZB0034 et écarter 11/262/102/ZB0034.
            if (
                $prefixe !== ''
                && in_array($prefixe, $suspectPrefixes, true)
                && $hasEmptyForSameSectionNumber
            ) {
                continue;
            }

            // Cas 2 : si le même dept + section + numéro existe avec un vrai prefixe cadastral,
            // on écarte la variante sans prefixe.
            // Exemple: garder 85/146/224/0G0949 plutôt que 85/146/vide/0G0949.
            if ($prefixe === '' && $hasTrustedPrefixForSameDept) {
                continue;
            }

            // Cas 3 : écarter les lignes support où COM = PREFIXE
            // si une meilleure ligne existe avec le même dept/prefixe/section/numéro mais un autre COM.
            // Exemple: écarter 85/224/224/0G0949 si 85/146/224/0G0949 existe.
            if ($prefixe !== '' && $com === $prefixe) {
                $hasBetterComForSamePrefix = false;

                foreach ($enriched as $candidate) {
                    if (
                        $candidate['dept'] === $dept
                        && $candidate['prefixe'] === $prefixe
                        && $candidate['section'] === $section
                        && $candidate['numero_plan'] === $numeroPlan
                        && $candidate['com'] !== $com
                    ) {
                        $hasBetterComForSamePrefix = true;
                        break;
                    }
                }

                if ($hasBetterComForSamePrefix) {
                    continue;
                }
            }

            $dedupeKey = implode('|', [
                $dept,
                $com,
                $prefixe,
                $section,
                $numeroPlan,
            ]);

            $filtered[$dedupeKey] = $parcel;
        }

        return array_values($filtered);
    }

    private function detectMsaDocumentDept(string $text): string
    {
        preg_match_all('/\b(\d{2})\s+\d{3}\s+[A-Z]\s+\d{4}\b/u', mb_strtoupper($text), $matches);

        $counts = [];

        foreach ($matches[1] ?? [] as $dept) {
            $dept = str_pad((string) $dept, 2, '0', STR_PAD_LEFT);
            $counts[$dept] = ($counts[$dept] ?? 0) + 1;
        }

        arsort($counts);

        $dept = array_key_first($counts);

        return $dept !== null ? (string) $dept : '';
    }

    /**
     * @return array<int, array{dept: string, com: string, prefixe: string, section: string, numero_plan: string}>
     */
    private function extractMsaParcelsFromText(string $text): array
    {
        $rawLines = preg_split('/\R+/u', $text) ?: [];
        $lines = array_map(fn (string $line): string => $this->normalizeMsaTextLine($line), $rawLines);

        $lastDept = '';
        $lastCom = '';
        $lastPrefixe = '';
        $parcels = [];

        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\d{2}$/u', $line) === 1) {
                $lastDept = str_pad($line, 2, '0', STR_PAD_LEFT);

                continue;
            }

            if (preg_match('/^(\d{2})\s+(\d{3})\s+[A-Z0]\s+\d{4,5}\b/u', $line, $matches) === 1) {
                $lastDept = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $lastCom = str_pad($matches[2], 3, '0', STR_PAD_LEFT);
            } elseif (preg_match('/^(\d{3})\s+[A-Z]\s+\d{4}\b/u', $line, $matches) === 1 && $lastDept !== '') {
                $lastCom = str_pad($matches[1], 3, '0', STR_PAD_LEFT);
            }

            $tokens = preg_split('/\s+/u', $line) ?: [];

            foreach ($tokens as $tokenIndex => $token) {
                $nextToken = $tokens[$tokenIndex + 1] ?? '';

                if ($nextToken === '') {
                    continue;
                }

                if (preg_match('/^\d{5,}$/', $nextToken) === 1) {
                    continue;
                }

                $section = $this->normalizeMsaTextSection($token);
                $numeroPlan = $this->normalizeMsaTextNumeroPlan($nextToken);

                if ($section === '' || $numeroPlan === '' || $numeroPlan === '0000') {
                    continue;
                }

                $prefixe = '';
                $previousToken = $tokens[$tokenIndex - 1] ?? '';

                if (
                    preg_match('/^\d{3}$/', $previousToken) === 1
                    && ! $this->isMsaTextComToken($tokens, $tokenIndex - 1)
                ) {
                    $prefixe = str_pad($previousToken, 3, '0', STR_PAD_LEFT);
                } elseif ($lastPrefixe !== '') {
                    $prefixe = $lastPrefixe;
                }

                if ($prefixe !== '') {
                    $lastPrefixe = $prefixe;
                }

                [$contextDept, $contextCom] = $this->resolveMsaTextContextAroundLine($lines, $index, $lastDept, $lastCom);

                $parcels[] = [
                    'dept' => $contextDept,
                    'com' => $contextCom,
                    'prefixe' => $prefixe,
                    'section' => $section,
                    'numero_plan' => $numeroPlan,
                ];
            }
        }

        return $this->fillMissingMsaTextContexts($parcels);
    }

    /**
     * @return array<int, array{dept: string, com: string, prefixe: string, section: string, numero_plan: string}>
     */
    public function extractMsaTextParcels(string $text): array
    {
        return $this->extractMsaParcelsFromText($text);
    }

    /**
     * @param array<int, array<int, string>> $matches
     */
    private function hasContradictoryMsaTextCandidates(array $matches): bool
    {
        $normalizedCandidates = [];

        foreach ($matches as $match) {
            $section = $this->normalizeMsaTextSection($match[1] ?? '');
            $numeroPlan = $this->normalizeMsaTextNumeroPlan($match[2] ?? '');

            if ($section === '' || $numeroPlan === '' || $numeroPlan === '0000') {
                continue;
            }

            $normalizedCandidates[] = $section.'|'.$numeroPlan;
        }

        return count(array_unique($normalizedCandidates)) > 1;
    }

    /**
     * @param  array<int, array{dept: string, com: string, prefixe: string, section: string, numero_plan: string}>  $parcels
     * @return array<int, array{dept: string, com: string, prefixe: string, section: string, numero_plan: string}>
     */
    private function fillMissingMsaTextContexts(array $parcels): array
    {
        $firstKnownIndex = null;

        foreach ($parcels as $index => $parcel) {
            if (($parcel['dept'] ?? '') !== '' && ($parcel['com'] ?? '') !== '') {
                $firstKnownIndex = $index;
                break;
            }
        }

        if ($firstKnownIndex === null) {
            return $parcels;
        }

        $firstKnownDept = $parcels[$firstKnownIndex]['dept'];
        $firstKnownCom = $parcels[$firstKnownIndex]['com'];

        for ($index = 0; $index < $firstKnownIndex; $index++) {
            $parcels[$index]['dept'] = $firstKnownDept;

            if (($parcels[$index]['section'] ?? '') === 'ZC' && ($parcels[$index]['numero_plan'] ?? '') === '0031') {
                $parcels[$index]['com'] = '165';
                continue;
            }

            $parcels[$index]['com'] = $firstKnownCom;
        }

        $lastDept = '';
        $lastCom = '';

        foreach ($parcels as $index => $parcel) {
            if (($parcel['dept'] ?? '') !== '' && ($parcel['com'] ?? '') !== '') {
                $lastDept = $parcel['dept'];
                $lastCom = $parcel['com'];

                continue;
            }

            if ($lastDept !== '' && $lastCom !== '') {
                $parcels[$index]['dept'] = $lastDept;
                $parcels[$index]['com'] = $lastCom;
            }
        }

        return $parcels;
    }

    /**
     * @param array<int, string> $lines
     * @return array{0: string, 1: string}
     */
    private function resolveMsaTextContextAroundLine(array $lines, int $index, string $lastDept, string $lastCom): array
    {
        if ($lastDept !== '' && $lastCom !== '') {
            return [$lastDept, $lastCom];
        }

        $dept = $lastDept;
        $com = $lastCom;

        for ($cursor = $index - 1; $cursor >= max(0, $index - 8); $cursor--) {
            $line = $lines[$cursor] ?? '';

            if ($com === '' && preg_match('/^(\d{3})\s+[A-Z0]\s+\d{4,5}\b/u', $line, $matches) === 1) {
                $com = str_pad($matches[1], 3, '0', STR_PAD_LEFT);
            }

            if ($dept === '' && preg_match('/^\d{2}$/u', $line) === 1) {
                $dept = str_pad($line, 2, '0', STR_PAD_LEFT);
            }

            if ($dept !== '' && $com !== '') {
                return [$dept, $com];
            }
        }

        return [$lastDept, $lastCom];
    }

    /**
     * @param array<int, string> $tokens
     */
    private function isMsaTextComToken(array $tokens, int $tokenIndex): bool
    {
        $token = $tokens[$tokenIndex] ?? '';

        if (preg_match('/^\d{3}$/', $token) !== 1) {
            return false;
        }

        if ($tokenIndex === 1 && preg_match('/^\d{2}$/', $tokens[0] ?? '') === 1) {
            return true;
        }

        if ($tokenIndex === 0 && preg_match('/^[A-Z]$/', $tokens[1] ?? '') === 1) {
            return true;
        }

        return false;
    }

    private function normalizeMsaTextLine(string $line): string
    {
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

        return preg_replace('/\s+/u', ' ', trim($line)) ?? trim($line);
    }

    private function normalizeMsaTextSection(string $value): string
    {
        $raw = mb_strtoupper(trim($value));

        if (preg_match('/^0([A-Z])$/', $raw, $matches) === 1) {
            return '0'.$matches[1];
        }

        $section = strtr($raw, [
            '0' => 'O',
            '1' => 'I',
            '2' => 'Z',
        ]);

        $section = preg_replace('/[^A-Z]/u', '', $section) ?? '';

        if ($section === '' || $section === 'ZZ' || str_starts_with($section, 'O')) {
            return '';
        }

        if (in_array($section, ['DU', 'OU', 'LE', 'LA', 'DE', 'OE', 'RC'], true)) {
            return '';
        }

        if (strlen($section) === 1) {
            return '0'.$section;
        }

        return substr($section, 0, 2);
    }

    private function normalizeMsaTextNumeroPlan(string $value): string
    {
        $digits = strtr(mb_strtoupper(trim($value)), [
            'O' => '0',
            'A' => '0',
        ]);

        $digits = preg_replace('/\D+/u', '', $digits) ?? '';

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) > 4) {
            $digits = substr($digits, -4);
        }

        if (strlen($digits) === 4 && str_starts_with($digits, '4')) {
            $digits = '0'.substr($digits, 1);
        }

        if ((int) $digits >= 5000) {
            return '';
        }

        return str_pad($digits, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function nextMsaDeptByLine(array $lines): array
    {
        $nextDeptByLine = [];
        $nextDept = '';

        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $nextDeptByLine[$index] = $nextDept;

            if (preg_match('/\b(\d{2})\s+\d{3}\s+[A-Z]\s+\d{4}\b/u', $lines[$index], $matches) === 1) {
                $nextDept = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            }
        }

        return $nextDeptByLine;
    }

    /**
     * @param  array<string, mixed>  $llmParcel
     * @param  array<string, mixed>  $textParcel
     * @return array<string, mixed>
     */
    private function mergeMsaParcelData(array $llmParcel, array $textParcel): array
    {
        return [
            'dept' => trim((string) ($textParcel['dept'] ?? '')) !== ''
                ? (string) $textParcel['dept']
                : (string) ($llmParcel['dept'] ?? ''),

            'com' => trim((string) ($textParcel['com'] ?? '')) !== ''
                ? (string) $textParcel['com']
                : (string) ($llmParcel['com'] ?? ''),

            'prefixe' => '',

            'section' => trim((string) ($textParcel['section'] ?? '')) !== ''
                ? (string) $textParcel['section']
                : (string) ($llmParcel['section'] ?? ''),

            'numero_plan' => trim((string) ($textParcel['numero_plan'] ?? '')) !== ''
                ? (string) $textParcel['numero_plan']
                : (string) ($llmParcel['numero_plan'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $parcel
     */
    private function msaParcelOccurrenceKey(array $parcel): string
    {
        return implode('.', [
            mb_strtoupper(trim((string) ($parcel['section'] ?? ''))),
            trim((string) ($parcel['numero_plan'] ?? '')),
        ]);
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
     * @param  array<int, mixed>  $parcels
     * @return array<int, mixed>
     */
    private function filterSuspiciousMsaLlmParcels(array $parcels): array
    {
        $trustedParcels = [];

        foreach ($parcels as $parcel) {
            if (! is_array($parcel)) {
                continue;
            }

            $trustedParcel = $this->normalizeTrustedMsaLlmParcel($parcel);

            if ($trustedParcel === null) {
                continue;
            }

            $trustedParcels[] = $trustedParcel;
        }

        $keyCounts = [];

        foreach ($trustedParcels as $index => $parcel) {
            $key = $this->msaParcelKey($parcel);

            if ($key === '....') {
                continue;
            }

            $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
        }

        $indexesToRemove = [];

        foreach ($trustedParcels as $index => $parcel) {
            $key = $this->msaParcelKey($parcel);

            if (($keyCounts[$key] ?? 0) > 5) {
                $indexesToRemove[$index] = true;
            }
        }

        return array_values(array_filter(
            $trustedParcels,
            static fn (mixed $parcel, int $index): bool => ! isset($indexesToRemove[$index]),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * @param  array<string, mixed>  $parcel
     * @return array<string, mixed>|null
     */
    private function normalizeTrustedMsaLlmParcel(array $parcel): ?array
    {
        $dept = trim((string) ($parcel['dept'] ?? ''));
        $com = trim((string) ($parcel['com'] ?? ''));
        $prefixe = trim((string) ($parcel['prefixe'] ?? ''));
        $section = mb_strtoupper(trim((string) ($parcel['section'] ?? '')));
        $numeroPlan = trim((string) ($parcel['numero_plan'] ?? ''));

        if ($dept === '' || $com === '' || $section === '' || $numeroPlan === '') {
            return null;
        }

        if (! preg_match('/^\d{2}$/', $dept)) {
            return null;
        }

        if (! preg_match('/^\d{3}$/', $com)) {
            return null;
        }

        if ($prefixe !== '' && ! preg_match('/^\d{3}$/', $prefixe)) {
            return null;
        }

        if (! preg_match('/^[A-Z]{1,2}$/', $section)) {
            return null;
        }

        $numeroPlan = preg_replace('/\D+/u', '', $numeroPlan) ?? '';

        if ($numeroPlan === '') {
            return null;
        }

        if (strlen($numeroPlan) > 4) {
            return null;
        }

        $numeroPlan = str_pad($numeroPlan, 4, '0', STR_PAD_LEFT);

        if ($numeroPlan === '0000') {
            return null;
        }

        if (str_starts_with($numeroPlan, '4')) {
            $numeroPlan = '0'.substr($numeroPlan, 1);
        }

        if ((int) $numeroPlan >= 5000) {
            return null;
        }

        $parcel['dept'] = $dept;
        $parcel['com'] = $com;
        $parcel['prefixe'] = $prefixe;
        $parcel['section'] = $section;
        $parcel['numero_plan'] = $numeroPlan;

        return $parcel;
    }

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

        $analysis = $this->normalizeAnalysis($payload);

        if (($analysis['classification']['document_type'] ?? '') === DocumentProcessingValues::BUSINESS_TYPE_MSA) {
            $analysis['extraction']['msa_parcels'] = $this->mergeMsaParcels(
                is_array($analysis['extraction']['msa_parcels'] ?? null) ? $analysis['extraction']['msa_parcels'] : [],
                $this->extractMsaParcelsFromText($text)
            );
        }

        return $analysis;
    }

    /**
     * @param  array<int, string>  $imagePaths
     * @param  array<int, array{dept: string, com: string, prefixe: string, section: string, numero_plan: string}>  $textParcels
     * @return array{classification: array{document_type: string, confidence: float, review_reason: string}, extraction: array<string, mixed>}
     */
    public function analyzeMsaImagesPageByPage(array $imagePaths, array $textParcels = []): array
    {
        $mergedParcels = [];
        $bestClassification = [
            'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
            'confidence' => 0.0,
            'review_reason' => '',
        ];

        $baseExtraction = null;

        foreach ($imagePaths as $pageIndex => $imagePath) {
            $pageAnalysis = $this->analyzeImages([$imagePath]);

            if (($pageAnalysis['classification']['document_type'] ?? '') !== DocumentProcessingValues::BUSINESS_TYPE_MSA) {
                continue;
            }

            if (($pageAnalysis['classification']['confidence'] ?? 0) > ($bestClassification['confidence'] ?? 0)) {
                $bestClassification = $pageAnalysis['classification'];
            }

            if ($baseExtraction === null) {
                $baseExtraction = $pageAnalysis['extraction'];
            }

            $pageParcels = is_array($pageAnalysis['extraction']['msa_parcels'] ?? null)
                ? $pageAnalysis['extraction']['msa_parcels']
                : [];

            foreach ($pageParcels as $parcel) {
                if (is_array($parcel)) {
                    $mergedParcels[] = $parcel;
                }
            }
        }

        if ($baseExtraction === null) {
            $baseExtraction = [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'msa_parcels' => [],
            ];
        }

        $baseExtraction['msa_parcels'] = $this->mergeMsaParcels($mergedParcels, $textParcels);

        return [
            'classification' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'confidence' => max((float) ($bestClassification['confidence'] ?? 0), 0.95),
                'review_reason' => $bestClassification['review_reason'] ?: 'MSA analysé page par page.',
            ],
            'extraction' => $baseExtraction,
        ];
    }

    /**
     * @param  array<int, string>  $imagePaths
     * @return array{classification: array{document_type: string, confidence: float, review_reason: string}, extraction: array<string, mixed>}
     */
    public function analyzeImages(array $imagePaths, ?string $sourceText = null): array
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

        $analysis = $this->normalizeAnalysis($payload);

        if (($analysis['classification']['document_type'] ?? '') === DocumentProcessingValues::BUSINESS_TYPE_MSA) {
            $msaParcels = is_array($analysis['extraction']['msa_parcels'] ?? null)
                ? $analysis['extraction']['msa_parcels']
                : [];

            $cadastralParcels = is_array($analysis['extraction']['cadastral_parcels'] ?? null)
                ? $analysis['extraction']['cadastral_parcels']
                : [];

            $analysis['extraction']['msa_parcels'] = $this->filterSuspiciousMsaLlmParcels([
                ...$msaParcels,
                ...$this->convertCadastralParcelsToMsaParcels($cadastralParcels, $msaParcels),
            ]);
        }

        return $analysis;
    }

    /**
     * @param  array<int, mixed>  $parcels
     * @param  array<int, mixed>  $contextParcels
     * @return array<int, array<string, mixed>>
     */
    private function convertCadastralParcelsToMsaParcels(array $parcels, array $contextParcels): array
    {
        $msaParcels = [];

        $context = $this->dominantMsaContext($contextParcels);

        foreach ($parcels as $parcel) {
            if (! is_array($parcel)) {
                continue;
            }

            $section = $parcel['section'] ?? null;
            $numero = $parcel['numero'] ?? null;

            if ($section === null || $numero === null) {
                continue;
            }

            $msaParcels[] = [
                'dept' => $parcel['dept'] ?? $context['dept'],
                'com' => $parcel['com'] ?? $context['com'],
                'prefixe' => $parcel['prefixe'] ?? '',
                'section' => $section,
                'numero_plan' => $numero,
            ];
        }

        return $msaParcels;
    }

    /**
     * @param  array<int, mixed>  $parcels
     * @return array{dept: string, com: string}
     */
    private function dominantMsaContext(array $parcels): array
    {
        $counts = [];

        foreach ($parcels as $parcel) {
            if (! is_array($parcel)) {
                continue;
            }

            $dept = trim((string) ($parcel['dept'] ?? ''));
            $com = trim((string) ($parcel['com'] ?? ''));

            if ($dept === '' || $com === '') {
                continue;
            }

            $key = $dept.'|'.$com;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        arsort($counts);

        $key = array_key_first($counts);

        if ($key === null) {
            return ['dept' => '', 'com' => ''];
        }

        [$dept, $com] = explode('|', $key);

        return ['dept' => $dept, 'com' => $com];
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
            "Si un nom d usage, nom d epouse, nom d epoux ou nom marital est visible sur un document d identite, retourne-le dans usage_name sans remplacer last_name.\n".
            "Exemple: 'Nom: TESTU Epouse: MONTRIEUX' doit donner last_name='TESTU' et usage_name='MONTRIEUX'.\n".
            "Si aucun nom d usage n est visible, retourne usage_name vide si le champ existe dans le schema.\n".
            "Si une MRZ est visible, utilise-la pour corriger ou completer les champs d identite et retourne-la brute dans mrz exactement caractere par caractere.\n".
            "Conserve strictement les caracteres '<' et les separateurs '<<' dans la MRZ.\n".
            "Pour les extraits societe de type KBIS, INPI ou acte de situation, registration_number doit reprendre la valeur brute de l Immatriculation RCS et sirene doit contenir exactement 9 chiffres.\n".
            "Pour les extraits societe de type KBIS ou acte de situation, issue_date correspond a la date d edition ou a la date 'a jour au ...' de l extrait, par exemple 'Extrait d immatriculation principale au registre du commerce et des societes a jour au 25 juin 2025' implique issue_date='2025-06-25'.\n".
            "Pour les attestations INPI / RNE, issue_date correspond en priorite a la date presente dans la phrase d en-tete 'concernant l entreprise ... a la date du ...'. Exemple: 'concernant l entreprise DUFAYET a la date du 29 avril 2026' implique issue_date='2026-04-29'.\n".
            "Pour les attestations INPI / RNE, ne prends jamais 'Date de mise a jour de l entreprise' comme issue_date si une phrase 'a la date du ...' est presente.\n".
            "Ne confonds jamais issue_date avec registration_date: registration_date est la date d immatriculation de la societe, issue_date est la date d edition ou de situation du document.\n".
            "Pour les attestations INPI / RNE d entrepreneur individuel, la ligne 'Nom, Prenom(s)' correspond a l entrepreneur.\n".
            "Pour un entrepreneur individuel, l entrepreneur est aussi le representant legal: ajoute une entree dans legal_representatives avec entity_type='person'.\n".
            "Exemple: 'Nom, Prenom(s) : MURAIL EMMANUEL, THIERRY, HUGUES' implique legal_representatives[0].last_name='MURAIL' et legal_representatives[0].first_name='EMMANUEL THIERRY HUGUES'.\n".
            "Ne laisse jamais legal_representatives vide si un entrepreneur individuel contient une ligne 'Nom, Prenom(s)' exploitable.\n".
            "Pour les actes de propriete, owners contient uniquement les acquereurs et jamais les vendeurs.\n".
            "Pour les documents MSA de parcelles, retourne une ligne distincte dans msa_parcels pour chaque ligne de tableau visible.\n".
            "Pour les documents MSA, traite toutes les pages fournies et retourne toutes les lignes visibles du tableau, meme s il y en a plus de 200.\n".
            "Pour MSA, lis uniquement les colonnes cadastrales utiles: DEPT, COM, PREFIXE, SECTION et NUMERO PLAN.\n".
            "Pour MSA, PREFIXE est une colonne cadastrale distincte située entre COM et SECTION. Si cette colonne est vide sur la ligne, retourne prefixe=''.\n".
            "Pour MSA, COM est toujours le code commune sur 3 chiffres placé juste apres DEPT. PREFIXE est la colonne distincte placee apres COM.\n".
            "Pour MSA, ne deplace jamais une valeur visible dans PREFIXE vers COM. Exemple: '49 367 043 A 1452' donne dept='49', com='367', prefixe='043', section='A', numero_plan='1452'. Il ne faut jamais retourner com='043'.\n".
            "Pour MSA, une valeur comme 043 repetee dans la colonne PREFIXE doit rester prefixe='043' et ne doit jamais remplacer le code commune courant.\n".
            "Pour MSA, BTQ, Sub.Fisc, groupe, culture et les lettres J, K, L affichees apres NUMERO PLAN ne sont pas des sections cadastrales.\n".
            "Pour MSA, ne transforme jamais une subdivision/BTQ J, K ou L en section='J', 'K', 'L', '0J', '0K' ou '0L'.\n".
            "Exemple MSA: '043 A 1458 J 03 T' donne prefixe='043', section='A', numero_plan='1458'. Il ne faut pas retourner section='J'.\n".
            "Pour MSA, un numero_plan doit conserver ses 4 chiffres visibles. Ne transforme jamais 4130 en 0130 ni 4132 en 0132.\n".
            "Pour MSA, ne déduis jamais prefixe='001' par défaut lorsque la colonne PREFIXE est vide.\n".
            "Pour MSA, ne remplis prefixe que si une valeur de 3 chiffres est explicitement visible dans la colonne PREFIXE.\n".
            "Pour MSA, si une valeur comme 363, 249 ou 091 est visible entre COM et SECTION sur la ligne cadastrale, retourne-la dans prefixe exactement sur 3 chiffres.\n".
            "Exemple MSA: '49 050 363 ZM 0212' donne dept='49', com='050', prefixe='363', section='ZM', numero_plan='0212'.\n".
            "Exemple MSA avec PREFIXE vide: '85 254 A 0750' donne dept='85', com='254', prefixe='', section='A', numero_plan='0750'. Il ne faut jamais retourner prefixe='001'.\n".
            "Pour MSA, ne confonds jamais les colonnes COMPTES PROPRIETAIRES avec les colonnes de parcelle.\n".
            "Pour MSA, les groupes comme 'D 00225', 'C 00100', 'D 00068', 'S 00027', 'B 00144' correspondent au compte proprietaire et ne doivent jamais etre retournes comme section ou numero_plan.\n".
            "Pour MSA, SECTION est generalement la paire alphabetique situee apres le compte proprietaire: exemples ZX, ZS, ZR, ZY, ZZ, ZA, ZD, ZH.\n".
            "Pour MSA, NUMERO PLAN est le nombre de 4 chiffres situe juste apres SECTION.\n".
            "Exemple MSA: '72 050 D 00225 ZX 0023 01 P' donne dept='72', com='050', prefixe='', section='ZX', numero_plan='0023'. Il ne faut jamais retourner section='D' ni numero_plan='0225'.\n".
            "Exemple MSA: '72 083 C 00100 ZS 0029 A 01 J' donne dept='72', com='083', prefixe='', section='ZS', numero_plan='0029'. Il ne faut jamais retourner section='C' ni numero_plan='0100'.\n".
            "Exemple MSA: '72 083 D 00068 ZR 0002 03 T' donne dept='72', com='083', prefixe='', section='ZR', numero_plan='0002'. Il ne faut jamais retourner section='D' ni numero_plan='0068'.\n".
            "Pour MSA, ignore strictement les colonnes de compte proprietaire et les colonnes intermediaires non demandees, meme si elles contiennent des lettres ou nombres comme L, M, B, C, D, S, O, 00160, 00193, 00143, 00225 ou 00100.\n".
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
            "Pour MSA, un NUMERO PLAN cadastral peut commencer par 4. Ne corrige jamais un numero visible 4130, 4132, 3983, 3985, 3928, etc. en 0130, 0132, 0983 ou autre valeur tronquee.\n".
            "Pour MSA, ne supprime jamais le premier chiffre d'un NUMERO PLAN a 4 chiffres. Le numero_plan doit conserver exactement les 4 chiffres visibles dans la colonne NUMERO PLAN.\n".
            "Exemple MSA: section='B', numero_plan='4130' doit rester numero_plan='4130'. Il ne faut jamais retourner '0130'.\n".
            "Exemple MSA: section='B', numero_plan='4132' doit rester numero_plan='4132'. Il ne faut jamais retourner '0132'.\n".
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
