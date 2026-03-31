<?php

namespace Ges\Ocr;

use Ges\Ocr\Data\ProcessedDocumentResult;
use Ges\Ocr\Data\ProcessingSource;
use Ges\Ocr\Support\DocumentProcessingValues;
use Ges\Ocr\Support\LlmConfig;
use RuntimeException;

class DocumentProcessor
{
    public function __construct(
        protected DocumentInputDetector $inputDetector,
        protected PdfToImageConverter $pdfToImageConverter,
        protected VisionDocumentTranscriber $visionDocumentTranscriber,
        protected DocumentClassifier $documentClassifier,
        protected DocumentExtractor $documentExtractor,
        protected OpenAiDocumentAnalyzer $openAiDocumentAnalyzer,
        protected DocumentNormalizationService $normalizationService,
        protected MrzProcessor $mrzProcessor,
    ) {}

    public function processFile(string $path, string $mimeType, string $originalName): ProcessedDocumentResult
    {
        return $this->processSource(new ProcessingSource(
            path: $path,
            mimeType: $mimeType,
            originalName: $originalName,
        ));
    }

    public function processSource(ProcessingSource $source): ProcessedDocumentResult
    {
        if (! is_file($source->path)) {
            throw new RuntimeException('The source file could not be found.');
        }

        $temporaryImageDirectory = storage_path('app/tmp/document-processing/'.($source->processingId ?? 'direct').'-'.uniqid('', true));
        $pageImages = [];
        $maxPages = $this->configuredMaxPages();

        try {
            $detection = $this->inputDetector->detect($source->path, $source->mimeType);
            $pageCount = $detection['pages_count'];
            $extractedText = $detection['extracted_text'];

            if ($detection['input_type'] === DocumentProcessingValues::INPUT_TYPE_IMAGE) {
                $pageImages = [$source->path];
            }

            if ($detection['input_type'] === DocumentProcessingValues::INPUT_TYPE_PDF_TEXT && is_string($extractedText)) {
                $extractedText = $this->limitTextToPages($extractedText, $maxPages);
            }

            if (
                LlmConfig::provider() === 'openai'
                && $detection['input_type'] === DocumentProcessingValues::INPUT_TYPE_PDF_TEXT
                && $this->looksLikeMsaParcelTable((string) $extractedText)
            ) {
                $pageImages = $this->pdfToImageConverter->convert($source->path, $temporaryImageDirectory, $maxPages);
                $pageCount = count($pageImages);

                $analysis = $this->openAiDocumentAnalyzer->analyzeImages($pageImages);
                $classification = $analysis['classification'];
                $extraction = $analysis['extraction'];

                return $this->buildProcessedResult(
                    source: $source,
                    detectionInputType: $detection['input_type'],
                    pageCount: $pageCount,
                    classification: $classification,
                    extraction: $extraction
                );
            }

            if ($detection['input_type'] === DocumentProcessingValues::INPUT_TYPE_PDF_SCAN) {
                $pageImages = $this->pdfToImageConverter->convert($source->path, $temporaryImageDirectory, $maxPages);
                $pageCount = count($pageImages);
            }

            if (
                in_array($detection['input_type'], [DocumentProcessingValues::INPUT_TYPE_IMAGE, DocumentProcessingValues::INPUT_TYPE_PDF_SCAN], true)
                && $pageImages !== []
            ) {
                if (LlmConfig::provider() === 'openai') {
                    $analysis = $this->openAiDocumentAnalyzer->analyzeImages($pageImages);
                    $classification = $analysis['classification'];
                    $extraction = $analysis['extraction'];

                    return $this->buildProcessedResult(
                        source: $source,
                        detectionInputType: $detection['input_type'],
                        pageCount: $pageCount,
                        classification: $classification,
                        extraction: $extraction
                    );
                }

                $extractedText = $this->visionDocumentTranscriber->transcribeImages($pageImages);
            }

            if (LlmConfig::provider() === 'openai') {
                $analysis = $this->openAiDocumentAnalyzer->analyzeText((string) $extractedText);
                $classification = $analysis['classification'];
                $extraction = $analysis['extraction'];

                return $this->buildProcessedResult(
                    source: $source,
                    detectionInputType: $detection['input_type'],
                    pageCount: $pageCount,
                    classification: $classification,
                    extraction: $extraction
                );
            }

            $classification = $this->documentClassifier->classifyText((string) $extractedText);
            $classificationReviewMessages = $this->classificationReviewMessages($classification);

            if ($classification['document_type'] === DocumentProcessingValues::BUSINESS_TYPE_AUTRE) {
                return $this->unsupportedDocumentResult($source, $detection['input_type'], $pageCount, $classification, $classificationReviewMessages);
            }

            $extraction = $this->documentExtractor->extractFromText($classification['document_type'], (string) $extractedText);

            if (
                in_array($classification['document_type'], DocumentProcessingValues::identityBusinessTypes(), true)
                && $pageImages !== []
                && (bool) config('ges-ocr.mrz.ocr_enabled', true)
            ) {
                $mrz = $this->mrzProcessor->best($classification['document_type'], [
                    (string) ($extraction['mrz'] ?? ''),
                    $this->visionDocumentTranscriber->extractMrzFromImages($pageImages),
                ]);

                if (trim($mrz) !== '') {
                    $extraction['mrz'] = $mrz;
                }
            }

            $review = $this->normalizationService->normalizeAndValidate($classification['document_type'], $extraction);
            $needsReview = $classification['confidence'] < LlmConfig::classificationConfidenceThreshold()
                || $classificationReviewMessages !== []
                || $review['needs_review'];

            $reviewMessages = $classificationReviewMessages;

            if ($classification['confidence'] < LlmConfig::classificationConfidenceThreshold()) {
                $reviewMessages[] = sprintf('Classification confidence %.2f is below threshold.', $classification['confidence']);
            }

            $reviewMessages = array_merge($reviewMessages, $review['errors']);

            return new ProcessedDocumentResult(
                originalName: $source->originalName,
                mimeType: $source->mimeType,
                path: $source->path,
                inputType: $detection['input_type'],
                documentType: $classification['document_type'],
                status: $needsReview ? DocumentProcessingValues::STATUS_NEEDS_REVIEW : DocumentProcessingValues::STATUS_DONE,
                pagesCount: $pageCount,
                rawClassificationJson: $classification,
                rawExtractionJson: $extraction,
                normalizedJson: $review['normalized'],
                errorMessage: $needsReview ? implode(' ', $reviewMessages) : null,
                processingId: $source->processingId,
            );
        } finally {
            $this->cleanupTemporaryImages($pageImages, $temporaryImageDirectory, $source->path);
        }
    }

    /**
     * @param  array{document_type: string, confidence: float, review_reason: string}  $classification
     * @param  array<string, mixed>  $extraction
     */
    protected function buildProcessedResult(
        ProcessingSource $source,
        ?string $detectionInputType,
        int $pageCount,
        array $classification,
        array $extraction
    ): ProcessedDocumentResult {
        $classificationReviewMessages = $this->classificationReviewMessages($classification);

        if ($classification['document_type'] === DocumentProcessingValues::BUSINESS_TYPE_AUTRE) {
            return $this->unsupportedDocumentResult($source, $detectionInputType, $pageCount, $classification, $classificationReviewMessages);
        }

        $review = $this->normalizationService->normalizeAndValidate($classification['document_type'], $extraction);
        $needsReview = $classification['confidence'] < LlmConfig::classificationConfidenceThreshold()
            || $classificationReviewMessages !== []
            || $review['needs_review'];

        $reviewMessages = $classificationReviewMessages;

        if ($classification['confidence'] < LlmConfig::classificationConfidenceThreshold()) {
            $reviewMessages[] = sprintf('Classification confidence %.2f is below threshold.', $classification['confidence']);
        }

        $reviewMessages = array_merge($reviewMessages, $review['errors']);

        return new ProcessedDocumentResult(
            originalName: $source->originalName,
            mimeType: $source->mimeType,
            path: $source->path,
            inputType: $detectionInputType,
            documentType: $classification['document_type'],
            status: $needsReview ? DocumentProcessingValues::STATUS_NEEDS_REVIEW : DocumentProcessingValues::STATUS_DONE,
            pagesCount: $pageCount,
            rawClassificationJson: $classification,
            rawExtractionJson: $extraction,
            normalizedJson: $review['normalized'],
            errorMessage: $needsReview ? implode(' ', $reviewMessages) : null,
            processingId: $source->processingId,
        );
    }

    /**
     * @param  array{document_type: string, confidence: float, review_reason: string}  $classification
     * @param  array<int, string>  $classificationReviewMessages
     */
    protected function unsupportedDocumentResult(
        ProcessingSource $source,
        ?string $detectionInputType,
        int $pageCount,
        array $classification,
        array $classificationReviewMessages
    ): ProcessedDocumentResult {
        return new ProcessedDocumentResult(
            originalName: $source->originalName,
            mimeType: $source->mimeType,
            path: $source->path,
            inputType: $detectionInputType,
            documentType: $classification['document_type'],
            status: DocumentProcessingValues::STATUS_NEEDS_REVIEW,
            pagesCount: $pageCount,
            rawClassificationJson: $classification,
            rawExtractionJson: null,
            normalizedJson: ['document_type' => DocumentProcessingValues::BUSINESS_TYPE_AUTRE],
            errorMessage: $classificationReviewMessages !== []
                ? implode(' ', $classificationReviewMessages)
                : 'Unsupported business document type.',
            processingId: $source->processingId,
        );
    }

    /**
     * @param  array{document_type: string, confidence: float, review_reason: string}  $classification
     * @return array<int, string>
     */
    protected function classificationReviewMessages(array $classification): array
    {
        $messages = [];
        $reviewReason = trim($classification['review_reason']);
        $loweredReason = mb_strtolower($reviewReason);
        $documentType = $classification['document_type'];

        if (
            $documentType !== DocumentProcessingValues::BUSINESS_TYPE_CIN
            && $documentType !== DocumentProcessingValues::BUSINESS_TYPE_TITRE_DE_SEJOUR
            && ! $this->isNegatedMention($loweredReason, 'cin')
            && ! $this->isNegatedMention($loweredReason, 'identit')
            && preg_match('/\b(cin|carte d ?identit[eé]?|identit[eé])\b/u', $loweredReason) === 1
        ) {
            if ($reviewReason !== '') {
                $messages[] = $reviewReason;
            }

            $messages[] = 'Classification rationale suggests a CIN-like document, but the structured type differs.';
        }

        if (
            $documentType !== DocumentProcessingValues::BUSINESS_TYPE_TITRE_DE_SEJOUR
            && ! $this->isNegatedMention($loweredReason, 'titre de sejour')
            && ! $this->isNegatedMention($loweredReason, 'carte de sejour')
            && ! $this->isNegatedMention($loweredReason, 'titre de residence')
            && preg_match('/\b(titre de s[ée]jour|carte de s[ée]jour|titre de r[ée]sidence)\b/u', $loweredReason) === 1
        ) {
            if ($reviewReason !== '') {
                $messages[] = $reviewReason;
            }

            $messages[] = 'Classification rationale suggests a titre de sejour-like document, but the structured type differs.';
        }

        if (
            $documentType !== DocumentProcessingValues::BUSINESS_TYPE_KBIS
            && ! $this->isNegatedMention($loweredReason, 'kbis')
            && ! $this->isNegatedMention($loweredReason, 'registre du commerce')
            && ! $this->isNegatedMention($loweredReason, 'siret')
            && ! $this->isNegatedMention($loweredReason, 'sirene')
            && preg_match('/\b(kbis|registre du commerce|siret|sirene)\b/u', $loweredReason) === 1
        ) {
            if ($reviewReason !== '') {
                $messages[] = $reviewReason;
            }

            $messages[] = 'Classification rationale suggests a KBIS-like document, but the structured type differs.';
        }

        if (
            $documentType !== DocumentProcessingValues::BUSINESS_TYPE_ACTE_PROPRIETE
            && ! $this->isNegatedMention($loweredReason, 'acte')
            && ! $this->isNegatedMention($loweredReason, 'propri')
            && ! $this->isNegatedMention($loweredReason, 'cadastr')
            && preg_match('/\b(acte|propri[eé]t[eé]|cadastr)\b/u', $loweredReason) === 1
        ) {
            if ($reviewReason !== '') {
                $messages[] = $reviewReason;
            }

            $messages[] = 'Classification rationale suggests an acte de propriete-like document, but the structured type differs.';
        }

        return array_values(array_unique($messages));
    }

    protected function isNegatedMention(string $reviewReason, string $keyword): bool
    {
        return preg_match('/\b(ne correspond pas|pas|aucun|ni)\b[^.]{0,80}\b'.preg_quote($keyword, '/').'/u', $reviewReason) === 1;
    }

    protected function looksLikeMsaParcelTable(string $text): bool
    {
        $normalized = mb_strtolower($text);
        $normalized = str_replace(["'", '’'], ' ', $normalized);

        return str_contains($normalized, 'releve d exploitation')
            && str_contains($normalized, 'identification des parcelles')
            && str_contains($normalized, 'numero plan')
            && str_contains($normalized, 'msa');
    }

    protected function configuredMaxPages(): int
    {
        return LlmConfig::maxPages();
    }

    protected function limitTextToPages(string $text, int $maxPages): string
    {
        if ($maxPages === 0) {
            return $text;
        }

        $pages = preg_split('/\f/u', $text) ?: [$text];

        return implode("\f", array_slice($pages, 0, $maxPages));
    }

    /**
     * @param  array<int, string>  $pageImages
     */
    protected function cleanupTemporaryImages(array $pageImages, string $temporaryImageDirectory, string $documentPath): void
    {
        if (! (bool) config('ges-ocr.processing.cleanup_temporary_files', true)) {
            return;
        }

        foreach ($pageImages as $pageImage) {
            if ($pageImage !== $documentPath && is_file($pageImage)) {
                @unlink($pageImage);
            }
        }

        if (is_dir($temporaryImageDirectory)) {
            @rmdir($temporaryImageDirectory);
        }
    }
}
