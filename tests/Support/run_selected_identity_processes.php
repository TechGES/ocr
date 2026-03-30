<?php

$files = [
    'tests/Fixtures/documents/cin/cin_WE.pdf',
    'tests/Fixtures/documents/cin/cin_Thierry Gaurenne.pdf',
    'tests/Fixtures/documents/cin/cnie.jpg',
];

foreach ($files as $path) {
    $result = app('Ges\\Ocr\\DocumentProcessor')->processFile(
        $path,
        mime_content_type($path) ?: 'application/octet-stream',
        basename($path)
    );

    echo '=== '.basename($path)." ===\n";
    echo json_encode(
        get_object_vars($result),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    )."\n\n";
}
