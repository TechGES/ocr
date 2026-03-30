<?php

$path = 'tests/Fixtures/documents/cin/cin_WE.pdf';
$result = app('Ges\\Ocr\\DocumentProcessor')->processFile(
    $path,
    mime_content_type($path) ?: 'application/octet-stream',
    basename($path)
);

echo json_encode(
    get_object_vars($result),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
).PHP_EOL;
