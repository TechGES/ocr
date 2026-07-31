<?php

declare(strict_types=1);

/**
 * Compare les sorties OCR normalisées avec la baseline visuelle ID-3202.
 *
 * Usage :
 *
 * php OCR-0.3.9-ID-3202/scripts/compare-baseline.php \
 *   --runs="dir1,dir2" \
 *   --output="OCR-0.3.9-ID-3202/summary/comparison"
 *
 * Codes de sortie :
 * 0 : correspondance exacte ;
 * 1 : écarts détectés ;
 * 2 : erreur de configuration ou de lecture.
 */

$projectRoot = dirname(__DIR__, 2);

$options = [
    'baseline' => $projectRoot.'/OCR-0.3.9-ID-3202',
    'runs' => '',
    'output' => '',
];

foreach (array_slice($argv, 1) as $argument) {
    if (! str_starts_with($argument, '--') || ! str_contains($argument, '=')) {
        continue;
    }

    [$name, $value] = explode('=', substr($argument, 2), 2);

    if (array_key_exists($name, $options)) {
        $options[$name] = $value;
    }
}

$baselineDir = rtrim($options['baseline'], '/');

if ($options['runs'] === '') {
    $options['runs'] = $baselineDir.'/runs/candidate';
}

if ($options['output'] === '') {
    $options['output'] = $baselineDir.'/summary/candidate-comparison';
}

$runDirectories = array_values(array_filter(
    array_map('trim', explode(',', $options['runs'])),
    static fn (string $path): bool => $path !== ''
));

$outputDir = rtrim($options['output'], '/');

$expectedFiles = [
    $baselineDir.'/expected/baseline-34/ref_expected-full.csv',
    $baselineDir.'/expected/problematic-6/ref_expected.csv',
];

$manifestPath = $baselineDir.'/panel-40-manifest.csv';

foreach ([...$expectedFiles, $manifestPath] as $requiredFile) {
    if (! is_file($requiredFile)) {
        fwrite(STDERR, "Fichier requis absent : {$requiredFile}\n");
        exit(2);
    }
}

foreach ($runDirectories as $runDirectory) {
    if (! is_dir($runDirectory)) {
        fwrite(STDERR, "Répertoire de résultats absent : {$runDirectory}\n");
        exit(2);
    }
}

if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, "Impossible de créer le répertoire : {$outputDir}\n");
    exit(2);
}

/**
 * @return array<int, array<string, string>>
 */
function readSemicolonCsv(string $path): array
{
    $handle = fopen($path, 'rb');

    if ($handle === false) {
        throw new RuntimeException("Impossible d'ouvrir {$path}");
    }

    $headers = fgetcsv($handle, 0, ';', '"', '');

    if (! is_array($headers)) {
        fclose($handle);

        throw new RuntimeException("En-tête CSV invalide : {$path}");
    }

    $headers = array_map(
        static fn (mixed $value): string => trim((string) $value),
        $headers
    );

    $rows = [];

    while (($values = fgetcsv($handle, 0, ';', '"', '')) !== false) {
        if ($values === [null] || $values === []) {
            continue;
        }

        $values = array_pad($values, count($headers), '');
        $row = [];

        foreach ($headers as $index => $header) {
            $row[$header] = trim((string) ($values[$index] ?? ''));
        }

        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

function normalizeDigits(string $value, int $length): ?string
{
    $value = trim($value);

    if ($value === '' || ! preg_match('/^\d+$/', $value)) {
        return null;
    }

    if (strlen($value) > $length) {
        return null;
    }

    return str_pad($value, $length, '0', STR_PAD_LEFT);
}

function normalizePrefix(string $value): ?string
{
    $value = strtoupper(trim($value));

    if ($value === '') {
        return '000';
    }

    if (! preg_match('/^[A-Z0-9]+$/', $value) || strlen($value) > 3) {
        return null;
    }

    return str_pad($value, 3, '0', STR_PAD_LEFT);
}

function normalizeSection(string $value): ?string
{
    $value = strtoupper(trim($value));

    if (preg_match('/^[A-Z]$/', $value)) {
        return '0'.$value;
    }

    if (! preg_match('/^[A-Z0-9]{2}$/', $value)) {
        return null;
    }

    return $value;
}

/**
 * @return array{
 *     department: string,
 *     commune: string,
 *     prefix: string,
 *     section: string,
 *     number: string,
 *     reference: string
 * }|null
 */
function normalizeParcel(
    string $department,
    string $commune,
    string $prefix,
    string $section,
    string $number
): ?array {
    $department = normalizeDigits($department, 2);
    $commune = normalizeDigits($commune, 3);
    $prefix = normalizePrefix($prefix);
    $section = normalizeSection($section);
    $number = normalizeDigits($number, 4);

    if (
        $department === null
        || $commune === null
        || $prefix === null
        || $section === null
        || $number === null
    ) {
        return null;
    }

    return [
        'department' => $department,
        'commune' => $commune,
        'prefix' => $prefix,
        'section' => $section,
        'number' => $number,
        'reference' => implode('/', [
            $department,
            $commune,
            $prefix,
            $section,
            $number,
        ]),
    ];
}

/**
 * @param array<int, array<int, scalar|null>> $rows
 */
function writeCsv(string $path, array $headers, array $rows): void
{
    $handle = fopen($path, 'wb');

    if ($handle === false) {
        throw new RuntimeException("Impossible d'écrire {$path}");
    }

    fputcsv($handle, $headers, ';', '"', '');

    foreach ($rows as $row) {
        fputcsv($handle, $row, ';', '"', '');
    }

    fclose($handle);
}

/**
 * @return int|null
 */
function lotIdFromFilename(string $filename): ?int
{
    $basename = basename($filename);

    if (preg_match('/^ocr_lot(\d+)\.json$/', $basename, $matches)) {
        return (int) $matches[1];
    }

    if (preg_match('/^(\d+)-\d+\.json$/', $basename, $matches)) {
        return (int) $matches[1];
    }

    return null;
}

try {
    /*
     * 1. Charger le manifeste des 40 lots.
     */
    $manifestLots = [];

    foreach (readSemicolonCsv($manifestPath) as $row) {
        $lotId = (int) ($row['lot'] ?? 0);

        if ($lotId <= 0) {
            continue;
        }

        $manifestLots[$lotId] = [
            'group' => $row['group'] ?? '',
            'pdf_path' => $row['path'] ?? '',
            'sha256' => $row['sha256'] ?? '',
        ];
    }

    ksort($manifestLots, SORT_NUMERIC);

    /*
     * 2. Charger toutes les références visuellement confirmées.
     */
    $expectedByLot = [];
    $expectedDuplicateRows = [];
    $invalidExpectedRows = [];
    $baselineStatusCounts = [
        'confirmed' => 0,
        'ambiguous' => 0,
        'failed' => 0,
        'other' => 0,
    ];

    foreach ($expectedFiles as $expectedFile) {
        foreach (readSemicolonCsv($expectedFile) as $csvIndex => $row) {
            $expectedStatus = trim((string) ($row['expected_status'] ?? ''));

            if (array_key_exists($expectedStatus, $baselineStatusCounts)) {
                $baselineStatusCounts[$expectedStatus]++;
            } else {
                $baselineStatusCounts['other']++;
            }

            if ($expectedStatus !== 'confirmed') {
                continue;
            }

            $lotId = (int) ($row['lot'] ?? 0);

            $parcel = normalizeParcel(
                $row['expected_department'] ?? '',
                $row['expected_commune'] ?? '',
                $row['expected_prefix'] ?? '',
                $row['expected_section'] ?? '',
                $row['expected_number'] ?? ''
            );

            if ($lotId <= 0 || $parcel === null) {
                $invalidExpectedRows[] = [
                    $expectedFile,
                    $csvIndex + 2,
                    $row['lot'] ?? '',
                    json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];

                continue;
            }

            $reference = $parcel['reference'];

            if (isset($expectedByLot[$lotId][$reference])) {
                $expectedDuplicateRows[] = [
                    $lotId,
                    $reference,
                    $expectedFile,
                    $csvIndex + 2,
                ];
            }

            $expectedByLot[$lotId][$reference] = $parcel;
        }
    }

    /*
     * 3. Rechercher les fichiers JSON des résultats OCR.
     */
    $runFilesByLot = [];

    foreach ($runDirectories as $runDirectory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $runDirectory,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'json') {
                continue;
            }

            $lotId = lotIdFromFilename($fileInfo->getFilename());

            if ($lotId === null) {
                continue;
            }

            $runFilesByLot[$lotId][] = $fileInfo->getPathname();
        }
    }

    /*
     * 4. Comparer lot par lot.
     */
    $summaryRows = [];
    $missingRows = [];
    $extraRows = [];
    $duplicateRows = [];
    $invalidActualRows = [];
    $runErrorRows = [];

    $globalExpected = 0;
    $globalActualUnique = 0;
    $globalMissing = 0;
    $globalExtra = 0;
    $globalDuplicates = 0;
    $globalInvalidActual = 0;
    $exactLots = 0;

    foreach ($manifestLots as $lotId => $manifest) {
        $expected = $expectedByLot[$lotId] ?? [];
        $files = $runFilesByLot[$lotId] ?? [];

        ksort($expected);

        if (count($files) !== 1) {
            $reason = count($files) === 0
                ? 'run_missing'
                : 'multiple_runs_found';

            $runErrorRows[] = [
                $lotId,
                $reason,
                implode('|', $files),
            ];

            $summaryRows[] = [
                $manifest['group'],
                $lotId,
                '',
                count($expected),
                0,
                count($expected),
                0,
                0,
                0,
                'RUN_ERROR',
            ];

            $globalExpected += count($expected);
            $globalMissing += count($expected);

            foreach ($expected as $parcel) {
                $missingRows[] = [
                    $lotId,
                    $parcel['reference'],
                    $parcel['department'],
                    $parcel['commune'],
                    $parcel['prefix'],
                    $parcel['section'],
                    $parcel['number'],
                ];
            }

            continue;
        }

        $runFile = $files[0];
        $payload = json_decode((string) file_get_contents($runFile), true);

        if (! is_array($payload)) {
            $runErrorRows[] = [
                $lotId,
                'invalid_json',
                $runFile,
            ];

            continue;
        }

        $actualRows = $payload['normalized']['msa_parcels'] ?? null;

        if (! is_array($actualRows)) {
            $runErrorRows[] = [
                $lotId,
                'normalized_msa_parcels_missing',
                $runFile,
            ];

            continue;
        }

        $actualCounts = [];
        $actualParcels = [];
        $invalidCountForLot = 0;

        foreach ($actualRows as $rowIndex => $row) {
            if (! is_array($row)) {
                $invalidActualRows[] = [
                    $lotId,
                    $rowIndex,
                    'row_not_array',
                    json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
                $invalidCountForLot++;

                continue;
            }

            $parcel = normalizeParcel(
                (string) ($row['dept'] ?? ''),
                (string) ($row['com'] ?? ''),
                (string) ($row['prefixe'] ?? ''),
                (string) ($row['section'] ?? ''),
                (string) ($row['numero_plan'] ?? '')
            );

            if ($parcel === null) {
                $invalidActualRows[] = [
                    $lotId,
                    $rowIndex,
                    'invalid_cadastral_components',
                    json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
                $invalidCountForLot++;

                continue;
            }

            $reference = $parcel['reference'];
            $actualCounts[$reference] = ($actualCounts[$reference] ?? 0) + 1;
            $actualParcels[$reference] = $parcel;
        }

        ksort($actualParcels);
        ksort($actualCounts);

        $missing = array_diff_key($expected, $actualParcels);
        $extra = array_diff_key($actualParcels, $expected);

        $duplicatesForLot = array_filter(
            $actualCounts,
            static fn (int $count): bool => $count > 1
        );

        foreach ($missing as $parcel) {
            $missingRows[] = [
                $lotId,
                $parcel['reference'],
                $parcel['department'],
                $parcel['commune'],
                $parcel['prefix'],
                $parcel['section'],
                $parcel['number'],
            ];
        }

        foreach ($extra as $parcel) {
            $extraRows[] = [
                $lotId,
                $parcel['reference'],
                $parcel['department'],
                $parcel['commune'],
                $parcel['prefix'],
                $parcel['section'],
                $parcel['number'],
            ];
        }

        foreach ($duplicatesForLot as $reference => $count) {
            $duplicateRows[] = [
                $lotId,
                $reference,
                $count,
            ];
        }

        $isExact = $missing === []
            && $extra === []
            && $duplicatesForLot === []
            && $invalidCountForLot === 0;

        if ($isExact) {
            $exactLots++;
        }

        $summaryRows[] = [
            $manifest['group'],
            $lotId,
            $runFile,
            count($expected),
            count($actualParcels),
            count($missing),
            count($extra),
            count($duplicatesForLot),
            $invalidCountForLot,
            $isExact ? 'EXACT' : 'DIFF',
        ];

        $globalExpected += count($expected);
        $globalActualUnique += count($actualParcels);
        $globalMissing += count($missing);
        $globalExtra += count($extra);
        $globalDuplicates += count($duplicatesForLot);
        $globalInvalidActual += $invalidCountForLot;
    }

    /*
     * 5. Générer les rapports.
     */
    writeCsv(
        $outputDir.'/comparison-by-lot.csv',
        [
            'group',
            'lot',
            'run_file',
            'expected',
            'actual_unique',
            'missing',
            'extra',
            'duplicate_references',
            'invalid_rows',
            'status',
        ],
        $summaryRows
    );

    writeCsv(
        $outputDir.'/missing.csv',
        [
            'lot',
            'reference',
            'department',
            'commune',
            'prefix',
            'section',
            'number',
        ],
        $missingRows
    );

    writeCsv(
        $outputDir.'/extra.csv',
        [
            'lot',
            'reference',
            'department',
            'commune',
            'prefix',
            'section',
            'number',
        ],
        $extraRows
    );

    writeCsv(
        $outputDir.'/duplicates.csv',
        [
            'lot',
            'reference',
            'occurrences',
        ],
        $duplicateRows
    );

    writeCsv(
        $outputDir.'/invalid-actual-rows.csv',
        [
            'lot',
            'row_index',
            'reason',
            'raw_row',
        ],
        $invalidActualRows
    );

    writeCsv(
        $outputDir.'/run-errors.csv',
        [
            'lot',
            'reason',
            'files',
        ],
        $runErrorRows
    );

    writeCsv(
        $outputDir.'/expected-duplicates.csv',
        [
            'lot',
            'reference',
            'source_file',
            'source_line',
        ],
        $expectedDuplicateRows
    );

    writeCsv(
        $outputDir.'/invalid-expected-rows.csv',
        [
            'source_file',
            'source_line',
            'lot',
            'raw_row',
        ],
        $invalidExpectedRows
    );

    $hasConfigurationError = $invalidExpectedRows !== [];
    $hasDifferences = $globalMissing > 0
        || $globalExtra > 0
        || $globalDuplicates > 0
        || $globalInvalidActual > 0
        || $runErrorRows !== []
        || $expectedDuplicateRows !== [];

    echo PHP_EOL;
    echo "=== Comparaison baseline ID-3202 ===", PHP_EOL;
    echo "Lots attendus             : ", count($manifestLots), PHP_EOL;
    echo "Lots exacts               : ", $exactLots, PHP_EOL;
    echo "Contrôles visuels         : ", array_sum($baselineStatusCounts), PHP_EOL;
    echo "Références confirmées     : ", $baselineStatusCounts['confirmed'], PHP_EOL;
    echo "Cas ambigus               : ", $baselineStatusCounts['ambiguous'], PHP_EOL;
    echo "Cas rejetés               : ", $baselineStatusCounts['failed'], PHP_EOL;
    echo "Références attendues      : ", $globalExpected, PHP_EOL;
    echo "Références OCR uniques    : ", $globalActualUnique, PHP_EOL;
    echo "Références manquantes     : ", $globalMissing, PHP_EOL;
    echo "Références supplémentaires: ", $globalExtra, PHP_EOL;
    echo "Références dupliquées     : ", $globalDuplicates, PHP_EOL;
    echo "Lignes OCR invalides      : ", $globalInvalidActual, PHP_EOL;
    echo "Erreurs de runs           : ", count($runErrorRows), PHP_EOL;
    echo "Rapports                   : ", $outputDir, PHP_EOL;

    if ($hasConfigurationError) {
        echo "Statut                     : ERREUR BASELINE", PHP_EOL;
        exit(2);
    }

    if ($hasDifferences) {
        echo "Statut                     : DIFFÉRENCES DÉTECTÉES", PHP_EOL;
        exit(1);
    }

    echo "Statut                     : CORRESPONDANCE EXACTE", PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(2);
}
