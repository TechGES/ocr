<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Dotenv\Dotenv;
use Ges\Ocr\DocumentProcessor;
use Ges\Ocr\Providers\DocumentProcessingServiceProvider;
use Orchestra\Testbench\Foundation\Application;

$projectRoot = dirname(__DIR__, 2);

Dotenv::createImmutable($projectRoot)->safeLoad();

$options = getopt('', [
    'baseline:',
    'output:',
    'lot:',
    'all',
    'overwrite',
]);

$baselineDir = rtrim(
    (string) ($options['baseline'] ?? $projectRoot.'/OCR-0.3.9-ID-3202'),
    '/'
);

$outputDir = rtrim(
    (string) ($options['output'] ?? $baselineDir.'/runs/candidate'),
    '/'
);

$manifestPath = $baselineDir.'/panel-40-manifest.csv';
$overwrite = array_key_exists('overwrite', $options);
$runAll = array_key_exists('all', $options);
$lotOption = trim((string) ($options['lot'] ?? ''));

if (! $runAll && $lotOption === '') {
    fwrite(
        STDERR,
        "Indiquer --lot=723, --lot=723,1143 ou --all.\n"
    );
    exit(2);
}

if ($runAll && $lotOption !== '') {
    fwrite(STDERR, "Utiliser soit --all, soit --lot, mais pas les deux.\n");
    exit(2);
}

if (! is_file($manifestPath)) {
    fwrite(STDERR, "Manifest absent : {$manifestPath}\n");
    exit(2);
}

$apiKey = trim((string) ($_ENV['OPENAI_API_KEY'] ?? ''));

if ($apiKey === '') {
    fwrite(STDERR, "OPENAI_API_KEY est absente du fichier .env.\n");
    exit(2);
}

if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, "Impossible de créer {$outputDir}\n");
    exit(2);
}

$errorDir = $outputDir.'/errors';

if (! is_dir($errorDir) && ! mkdir($errorDir, 0775, true) && ! is_dir($errorDir)) {
    fwrite(STDERR, "Impossible de créer {$errorDir}\n");
    exit(2);
}

/**
 * @return array<int, array{
 *     group: string,
 *     lot: int,
 *     path: string,
 *     size_bytes: int,
 *     sha256: string
 * }>
 */
function readManifest(string $path, string $projectRoot): array
{
    $handle = fopen($path, 'rb');

    if ($handle === false) {
        throw new RuntimeException("Impossible d'ouvrir {$path}");
    }

    $headers = fgetcsv($handle, 0, ';', '"', '');

    if (! is_array($headers)) {
        fclose($handle);

        throw new RuntimeException("En-tête invalide dans {$path}");
    }

    $rows = [];

    while (($values = fgetcsv($handle, 0, ';', '"', '')) !== false) {
        $values = array_pad($values, count($headers), '');
        $row = array_combine($headers, $values);

        if (! is_array($row)) {
            continue;
        }

        $lot = (int) ($row['lot'] ?? 0);
        $relativePath = trim((string) ($row['path'] ?? ''));

        if ($lot <= 0 || $relativePath === '') {
            continue;
        }

        $rows[$lot] = [
            'group' => trim((string) ($row['group'] ?? '')),
            'lot' => $lot,
            'path' => $projectRoot.'/'.ltrim($relativePath, '/'),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'sha256' => strtolower(trim((string) ($row['sha256'] ?? ''))),
        ];
    }

    fclose($handle);
    ksort($rows, SORT_NUMERIC);

    return $rows;
}

/**
 * @return array<int, int>
 */
function parseLotSelection(string $value): array
{
    $lots = [];

    foreach (explode(',', $value) as $candidate) {
        $candidate = trim($candidate);

        if ($candidate === '' || ! ctype_digit($candidate)) {
            throw new InvalidArgumentException(
                "Identifiant de lot invalide : {$candidate}"
            );
        }

        $lot = (int) $candidate;

        if ($lot <= 0) {
            throw new InvalidArgumentException(
                "Identifiant de lot invalide : {$candidate}"
            );
        }

        $lots[$lot] = $lot;
    }

    ksort($lots, SORT_NUMERIC);

    return array_values($lots);
}

function atomicJsonWrite(string $path, array $payload): void
{
    $temporaryPath = $path.'.tmp';

    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );

    if (file_put_contents($temporaryPath, $json.PHP_EOL) === false) {
        throw new RuntimeException("Écriture impossible : {$temporaryPath}");
    }

    if (! rename($temporaryPath, $path)) {
        @unlink($temporaryPath);

        throw new RuntimeException("Finalisation impossible : {$path}");
    }
}

try {
    $manifest = readManifest($manifestPath, $projectRoot);

    $selectedLots = $runAll
        ? array_keys($manifest)
        : parseLotSelection($lotOption);

    foreach ($selectedLots as $lot) {
        if (! isset($manifest[$lot])) {
            throw new InvalidArgumentException(
                "Le lot {$lot} est absent du manifest ID-3202."
            );
        }
    }

    $app = Application::create(basePath: $projectRoot);
    $app->register(DocumentProcessingServiceProvider::class);

    $app['config']->set('ges-ocr.ai.provider', 'openai');
    $app['config']->set('ges-ocr.openai.api_key', $apiKey);
    $app['config']->set(
        'ges-ocr.openai.text_model',
        $_ENV['OPENAI_TEXT_MODEL'] ?? 'gpt-4.1-mini'
    );
    $app['config']->set(
        'ges-ocr.openai.vision_model',
        $_ENV['OPENAI_VISION_MODEL'] ?? 'gpt-4.1-mini'
    );

    $processor = $app->make(DocumentProcessor::class);

    $processed = 0;
    $skipped = 0;
    $failed = 0;

    echo "=== Runner baseline ID-3202 ===\n";
    echo 'Lots sélectionnés : '.count($selectedLots)."\n";
    echo 'Sortie            : '.$outputDir."\n";
    echo 'Modèle texte      : '.$app['config']->get('ges-ocr.openai.text_model')."\n";
    echo 'Modèle vision     : '.$app['config']->get('ges-ocr.openai.vision_model')."\n\n";

    foreach ($selectedLots as $position => $lot) {
        $entry = $manifest[$lot];
        $sourcePath = $entry['path'];
        $outputPath = $outputDir.'/ocr_lot'.$lot.'.json';
        $errorPath = $errorDir.'/lot-'.$lot.'-error.json';

        echo sprintf(
            "[%d/%d] Lot %d : ",
            $position + 1,
            count($selectedLots),
            $lot
        );

        if (is_file($outputPath) && ! $overwrite) {
            echo "SKIP — résultat déjà présent\n";
            $skipped++;

            continue;
        }

        if (! is_file($sourcePath)) {
            echo "ERREUR — PDF absent\n";
            $failed++;

            atomicJsonWrite($errorPath, [
                'lot' => $lot,
                'status' => 'source_missing',
                'source_path' => $sourcePath,
                'recorded_at' => date(DATE_ATOM),
            ]);

            continue;
        }

        $actualSize = filesize($sourcePath);
        $actualHash = hash_file('sha256', $sourcePath);

        if (
            $actualSize !== $entry['size_bytes']
            || strtolower((string) $actualHash) !== $entry['sha256']
        ) {
            echo "ERREUR — empreinte du PDF différente\n";
            $failed++;

            atomicJsonWrite($errorPath, [
                'lot' => $lot,
                'status' => 'source_checksum_mismatch',
                'source_path' => $sourcePath,
                'expected_size' => $entry['size_bytes'],
                'actual_size' => $actualSize,
                'expected_sha256' => $entry['sha256'],
                'actual_sha256' => $actualHash,
                'recorded_at' => date(DATE_ATOM),
            ]);

            continue;
        }

        try {
            $startedAt = microtime(true);

            $result = $processor->processFile(
                path: $sourcePath,
                mimeType: mime_content_type($sourcePath) ?: 'application/pdf',
                originalName: basename($sourcePath),
            );

            $payload = [
                'input_type' => $result->inputType,
                'document_type' => $result->documentType,
                'status' => $result->status,
                'pages_count' => $result->pagesCount,
                'normalized' => $result->normalizedJson,
                'raw_classification' => $result->rawClassificationJson,
                'raw_extraction' => $result->rawExtractionJson,
                'error' => $result->errorMessage,
                '_baseline' => [
                    'ticket' => 'ID-3202',
                    'lot' => $lot,
                    'group' => $entry['group'],
                    'source_path' => $sourcePath,
                    'source_sha256' => $actualHash,
                    'duration_seconds' => round(microtime(true) - $startedAt, 3),
                    'generated_at' => date(DATE_ATOM),
                    'text_model' => $app['config']->get('ges-ocr.openai.text_model'),
                    'vision_model' => $app['config']->get('ges-ocr.openai.vision_model'),
                ],
            ];

            atomicJsonWrite($outputPath, $payload);
            @unlink($errorPath);

            $parcelCount = is_array($result->normalizedJson['msa_parcels'] ?? null)
                ? count($result->normalizedJson['msa_parcels'])
                : 0;

            echo sprintf(
                "OK — %d pages, %d parcelles, %.2f s\n",
                $result->pagesCount,
                $parcelCount,
                microtime(true) - $startedAt
            );

            $processed++;
        } catch (Throwable $exception) {
            echo 'ERREUR — '.$exception->getMessage()."\n";
            $failed++;

            atomicJsonWrite($errorPath, [
                'lot' => $lot,
                'status' => 'processing_failed',
                'source_path' => $sourcePath,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'recorded_at' => date(DATE_ATOM),
            ]);
        }
    }

    echo "\n=== Résumé ===\n";
    echo "Traités : {$processed}\n";
    echo "Ignorés : {$skipped}\n";
    echo "Échecs  : {$failed}\n";

    exit($failed > 0 ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(2);
}
