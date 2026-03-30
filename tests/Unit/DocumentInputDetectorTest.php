<?php

declare(strict_types=1);

use Ges\Ocr\DocumentInputDetector;
use Ges\Ocr\Models\DocumentProcessing;
use Ges\Ocr\PdfTextExtractor;

it('detects the technical input type from real document fixtures', function (
    string $relativePath,
    string $mimeType,
    string $expectedInputType,
    ?int $expectedPagesCount,
    bool $shouldExposeExtractedText
) {
    $detector = new DocumentInputDetector(new PdfTextExtractor);
    $fixturePath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relativePath;

    $result = $detector->detect($fixturePath, $mimeType);

    expect($result['input_type'])->toBe($expectedInputType);

    if ($expectedPagesCount !== null) {
        expect($result['pages_count'])->toBeGreaterThanOrEqual($expectedPagesCount);
    } else {
        expect($result['pages_count'])->toBeNull();
    }

    if ($shouldExposeExtractedText) {
        expect($result['extracted_text'])->not->toBeNull()->and(trim((string) $result['extracted_text']))->not->toBe('');

        return;
    }

    expect($result['extracted_text'])->toBeNull();
})->with([
    'cin image' => [
        'relativePath' => 'tests/Fixtures/documents/cin/cin.webp',
        'mimeType' => 'image/webp',
        'expectedInputType' => DocumentProcessing::INPUT_TYPE_IMAGE,
        'expectedPagesCount' => 1,
        'shouldExposeExtractedText' => false,
    ],
    'land deed image' => [
        'relativePath' => 'tests/Fixtures/documents/acte_propriete/acte.jpg',
        'mimeType' => 'image/jpeg',
        'expectedInputType' => DocumentProcessing::INPUT_TYPE_IMAGE,
        'expectedPagesCount' => 1,
        'shouldExposeExtractedText' => false,
    ],
    'text kbis pdf' => [
        'relativePath' => 'tests/Fixtures/documents/kbis/ges_kbis.pdf',
        'mimeType' => 'application/pdf',
        'expectedInputType' => DocumentProcessing::INPUT_TYPE_PDF_TEXT,
        'expectedPagesCount' => 1,
        'shouldExposeExtractedText' => true,
    ],
    'text land deed pdf' => [
        'relativePath' => 'tests/Fixtures/documents/acte_propriete/Titre de propriété.pdf',
        'mimeType' => 'application/pdf',
        'expectedInputType' => DocumentProcessing::INPUT_TYPE_PDF_TEXT,
        'expectedPagesCount' => 1,
        'shouldExposeExtractedText' => true,
    ],
    'scan cin pdf' => [
        'relativePath' => 'tests/Fixtures/documents/cin/cin_Thierry Gaurenne.pdf',
        'mimeType' => 'application/pdf',
        'expectedInputType' => DocumentProcessing::INPUT_TYPE_PDF_SCAN,
        'expectedPagesCount' => null,
        'shouldExposeExtractedText' => false,
    ],
    'scan kbis pdf' => [
        'relativePath' => 'tests/Fixtures/documents/kbis/infonet.pdf',
        'mimeType' => 'application/pdf',
        'expectedInputType' => DocumentProcessing::INPUT_TYPE_PDF_SCAN,
        'expectedPagesCount' => null,
        'shouldExposeExtractedText' => false,
    ],
]);
