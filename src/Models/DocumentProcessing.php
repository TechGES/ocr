<?php

namespace Ges\Ocr\Models;

use Ges\Ocr\Database\Factories\DocumentProcessingFactory;
use Ges\Ocr\Support\DocumentProcessingValues;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentProcessing extends Model
{
    /** @use HasFactory<DocumentProcessingFactory> */
    use HasFactory;

    public const STATUS_PENDING = DocumentProcessingValues::STATUS_PENDING;

    public const STATUS_PROCESSING = DocumentProcessingValues::STATUS_PROCESSING;

    public const STATUS_DONE = DocumentProcessingValues::STATUS_DONE;

    public const STATUS_FAILED = DocumentProcessingValues::STATUS_FAILED;

    public const STATUS_NEEDS_REVIEW = DocumentProcessingValues::STATUS_NEEDS_REVIEW;

    public const INPUT_TYPE_IMAGE = DocumentProcessingValues::INPUT_TYPE_IMAGE;

    public const INPUT_TYPE_PDF_TEXT = DocumentProcessingValues::INPUT_TYPE_PDF_TEXT;

    public const INPUT_TYPE_PDF_SCAN = DocumentProcessingValues::INPUT_TYPE_PDF_SCAN;

    public const BUSINESS_TYPE_IDENTITY_CARD = DocumentProcessingValues::BUSINESS_TYPE_IDENTITY_CARD;

    public const BUSINESS_TYPE_CIN = DocumentProcessingValues::BUSINESS_TYPE_CIN;

    public const BUSINESS_TYPE_RESIDENCE_PERMIT = DocumentProcessingValues::BUSINESS_TYPE_RESIDENCE_PERMIT;

    public const BUSINESS_TYPE_TITRE_DE_SEJOUR = DocumentProcessingValues::BUSINESS_TYPE_TITRE_DE_SEJOUR;

    public const BUSINESS_TYPE_PASSPORT = DocumentProcessingValues::BUSINESS_TYPE_PASSPORT;

    public const BUSINESS_TYPE_VISA = DocumentProcessingValues::BUSINESS_TYPE_VISA;

    public const BUSINESS_TYPE_CREW_CARD = DocumentProcessingValues::BUSINESS_TYPE_CREW_CARD;

    public const BUSINESS_TYPE_TRAVEL_DOCUMENT = DocumentProcessingValues::BUSINESS_TYPE_TRAVEL_DOCUMENT;

    public const BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT = DocumentProcessingValues::BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT;

    public const BUSINESS_TYPE_KBIS = DocumentProcessingValues::BUSINESS_TYPE_KBIS;

    public const BUSINESS_TYPE_ACTE_PROPRIETE = DocumentProcessingValues::BUSINESS_TYPE_ACTE_PROPRIETE;

    public const BUSINESS_TYPE_MSA = DocumentProcessingValues::BUSINESS_TYPE_MSA;

    public const BUSINESS_TYPE_AUTRE = DocumentProcessingValues::BUSINESS_TYPE_AUTRE;

    protected $fillable = [
        'original_name',
        'mime_type',
        'path',
        'input_type',
        'document_type',
        'status',
        'pages_count',
        'raw_classification_json',
        'raw_extraction_json',
        'normalized_json',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'raw_classification_json' => 'array',
            'raw_extraction_json' => 'array',
            'normalized_json' => 'array',
            'pages_count' => 'integer',
        ];
    }

    protected static function newFactory(): DocumentProcessingFactory
    {
        return DocumentProcessingFactory::new();
    }
}
