<?php

namespace Ges\Ocr\Data;

class ProcessingSource
{
    public function __construct(
        public readonly string $path,
        public readonly string $mimeType,
        public readonly string $originalName,
        public readonly ?int $processingId = null,
    ) {}
}
