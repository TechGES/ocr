<?php

declare(strict_types=1);

use Ges\Ocr\DocumentClassifier;
use Ges\Ocr\DocumentExtractor;
use Ges\Ocr\DocumentInputDetector;
use Ges\Ocr\DocumentNormalizationService;
use Ges\Ocr\DocumentProcessor;
use Ges\Ocr\MrzProcessor;
use Ges\Ocr\OpenAiDocumentAnalyzer;
use Ges\Ocr\PdfToImageConverter;
use Ges\Ocr\VisionDocumentTranscriber;
use Ges\Ocr\Support\DocumentProcessingValues;

it('uses the one-shot openai analyzer for image inputs', function () {
    config()->set('ges-ocr.ai.provider', 'openai');

    $inputDetector = Mockery::mock(DocumentInputDetector::class);
    $inputDetector->shouldReceive('detect')
        ->once()
        ->andReturn([
            'input_type' => DocumentProcessingValues::INPUT_TYPE_IMAGE,
            'pages_count' => 1,
            'extracted_text' => null,
        ]);

    $pdfToImageConverter = Mockery::mock(PdfToImageConverter::class);
    $pdfToImageConverter->shouldNotReceive('convert');

    $visionDocumentTranscriber = Mockery::mock(VisionDocumentTranscriber::class);
    $visionDocumentTranscriber->shouldNotReceive('transcribeImages');
    $visionDocumentTranscriber->shouldNotReceive('extractMrzFromImages');

    $documentClassifier = Mockery::mock(DocumentClassifier::class);
    $documentClassifier->shouldNotReceive('classifyText');

    $documentExtractor = Mockery::mock(DocumentExtractor::class);
    $documentExtractor->shouldNotReceive('extractFromText');

    $openAiDocumentAnalyzer = Mockery::mock(OpenAiDocumentAnalyzer::class);
    $openAiDocumentAnalyzer->shouldReceive('analyzeImages')
        ->once()
        ->andReturn([
            'classification' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_CIN,
                'confidence' => 0.98,
                'review_reason' => '',
            ],
            'extraction' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_CIN,
                'first_name' => 'Wadie',
                'last_name' => 'EL ARRIM',
                'date_of_birth' => '1989-04-06',
                'place_of_birth' => '',
                'document_number' => '',
                'expiry_date' => '',
                'nationality' => 'MAR',
                'sex' => 'M',
                'mrz' => 'IDFRA...',
                'street_address' => '',
                'postal_code' => '',
                'city' => '',
            ],
        ]);

    $normalizationService = app(DocumentNormalizationService::class);
    $mrzProcessor = Mockery::mock(MrzProcessor::class);
    $mrzProcessor->shouldNotReceive('best');

    $processor = new DocumentProcessor(
        $inputDetector,
        $pdfToImageConverter,
        $visionDocumentTranscriber,
        $documentClassifier,
        $documentExtractor,
        $openAiDocumentAnalyzer,
        $normalizationService,
        $mrzProcessor,
    );

    $path = dirname(__DIR__, 2).'/tests/Fixtures/documents/cin/cin.webp';
    $result = $processor->processFile($path, 'image/webp', 'cin.webp');

    expect($result->documentType)->toBe(DocumentProcessingValues::BUSINESS_TYPE_CIN)
        ->and($result->status)->toBe(DocumentProcessingValues::STATUS_DONE)
        ->and($result->rawExtractionJson['first_name'])->toBe('Wadie')
        ->and($result->normalizedJson['last_name'])->toBe('EL ARRIM');
});

it('routes MSA-like text pdfs through image analysis for openai', function () {
    config()->set('ges-ocr.ai.provider', 'openai');

    $inputDetector = Mockery::mock(DocumentInputDetector::class);
    $inputDetector->shouldReceive('detect')
        ->once()
        ->andReturn([
            'input_type' => DocumentProcessingValues::INPUT_TYPE_PDF_TEXT,
            'pages_count' => 14,
            'extracted_text' => "RELEVE D'EXPLOITATION\nIDENTIFICATION DES PARCELLES\nNUMERO PLAN\nMSA",
        ]);

    $pdfToImageConverter = Mockery::mock(PdfToImageConverter::class);
    $pdfToImageConverter->shouldReceive('convert')
        ->once()
        ->andReturn([dirname(__DIR__, 2).'/tests/Fixtures/documents/cin/cin.webp']);

    $visionDocumentTranscriber = Mockery::mock(VisionDocumentTranscriber::class);
    $visionDocumentTranscriber->shouldNotReceive('transcribeImages');
    $visionDocumentTranscriber->shouldNotReceive('extractMrzFromImages');

    $documentClassifier = Mockery::mock(DocumentClassifier::class);
    $documentClassifier->shouldNotReceive('classifyText');

    $documentExtractor = Mockery::mock(DocumentExtractor::class);
    $documentExtractor->shouldNotReceive('extractFromText');

    $openAiDocumentAnalyzer = Mockery::mock(OpenAiDocumentAnalyzer::class);
    $openAiDocumentAnalyzer->shouldReceive('analyzeImages')
        ->once()
        ->andReturn([
            'classification' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'confidence' => 0.99,
                'review_reason' => '',
            ],
            'extraction' => [
                'document_type' => DocumentProcessingValues::BUSINESS_TYPE_MSA,
                'msa_parcels' => [
                    [
                        'dept' => '85',
                        'com' => '006',
                        'prefixe' => '',
                        'section' => 'B',
                        'numero_plan' => '0357',
                    ],
                ],
            ],
        ]);
    $openAiDocumentAnalyzer->shouldNotReceive('analyzeText');

    $normalizationService = app(DocumentNormalizationService::class);
    $mrzProcessor = Mockery::mock(MrzProcessor::class);
    $mrzProcessor->shouldNotReceive('best');

    $processor = new DocumentProcessor(
        $inputDetector,
        $pdfToImageConverter,
        $visionDocumentTranscriber,
        $documentClassifier,
        $documentExtractor,
        $openAiDocumentAnalyzer,
        $normalizationService,
        $mrzProcessor,
    );

    $path = dirname(__DIR__, 2).'/composer.json';
    $result = $processor->processFile($path, 'application/pdf', 'MSA.pdf');

    expect($result->documentType)->toBe(DocumentProcessingValues::BUSINESS_TYPE_MSA)
        ->and($result->rawExtractionJson['msa_parcels'][0]['section'])->toBe('B')
        ->and($result->normalizedJson['msa_parcels'][0]['section'])->toBe('0B');
});
