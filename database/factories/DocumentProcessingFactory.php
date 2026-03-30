<?php

namespace Ges\Ocr\Database\Factories;

use Ges\Ocr\Models\DocumentProcessing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentProcessing>
 */
class DocumentProcessingFactory extends Factory
{
    protected $model = DocumentProcessing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'original_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'path' => 'documents/uploads/'.fake()->uuid().'.pdf',
            'input_type' => null,
            'document_type' => null,
            'status' => DocumentProcessing::STATUS_PENDING,
            'pages_count' => null,
            'raw_classification_json' => null,
            'raw_extraction_json' => null,
            'normalized_json' => null,
            'error_message' => null,
        ];
    }
}
