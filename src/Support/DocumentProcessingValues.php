<?php

namespace Ges\Ocr\Support;

class DocumentProcessingValues
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const INPUT_TYPE_IMAGE = 'image';

    public const INPUT_TYPE_PDF_TEXT = 'pdf_text';

    public const INPUT_TYPE_PDF_SCAN = 'pdf_scan';

    public const BUSINESS_TYPE_IDENTITY_CARD = 'identity_card';

    public const BUSINESS_TYPE_CIN = self::BUSINESS_TYPE_IDENTITY_CARD;

    public const BUSINESS_TYPE_RESIDENCE_PERMIT = 'residence_permit';

    public const BUSINESS_TYPE_TITRE_DE_SEJOUR = self::BUSINESS_TYPE_RESIDENCE_PERMIT;

    public const BUSINESS_TYPE_PASSPORT = 'passport';

    public const BUSINESS_TYPE_VISA = 'visa';

    public const BUSINESS_TYPE_CREW_CARD = 'crew_card';

    public const BUSINESS_TYPE_TRAVEL_DOCUMENT = 'travel_document';

    public const BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT = 'other_identity_document';

    public const BUSINESS_TYPE_KBIS = 'kbis';

    public const BUSINESS_TYPE_ACTE_PROPRIETE = 'acte_propriete';

    public const BUSINESS_TYPE_MSA = 'msa';

    public const BUSINESS_TYPE_AUTRE = 'autre';

    /**
     * @return array<int, string>
     */
    public static function identityBusinessTypes(): array
    {
        return [
            self::BUSINESS_TYPE_IDENTITY_CARD,
            self::BUSINESS_TYPE_RESIDENCE_PERMIT,
            self::BUSINESS_TYPE_PASSPORT,
            self::BUSINESS_TYPE_VISA,
            self::BUSINESS_TYPE_CREW_CARD,
            self::BUSINESS_TYPE_TRAVEL_DOCUMENT,
            self::BUSINESS_TYPE_OTHER_IDENTITY_DOCUMENT,
        ];
    }
}
