<?php

declare(strict_types=1);

use Ges\Ocr\OllamaClient;
use Ges\Ocr\VisionDocumentTranscriber;

it('uses an identity-document OCR prompt for vision transcription', function () {
    $capturedContent = null;
    $ollamaClient = Mockery::mock(OllamaClient::class);
    $ollamaClient->shouldReceive('chatText')
        ->once()
        ->andReturnUsing(function (string $model, array $messages) use (&$capturedContent): string {
            expect($model)->not->toBe('');
            $capturedContent = $messages[0]['content'] ?? '';

            return 'CARTE NATIONALE D IDENTITE';
        });

    $transcriber = new VisionDocumentTranscriber($ollamaClient);
    $transcriber->transcribeImages([dirname(__DIR__, 2).'/tests/Fixtures/documents/cin/cin.webp']);

    expect($capturedContent)
        ->toContain('Tu es un agent OCR specialise dans la lecture de cartes d identite')
        ->toContain('Transcris exactement le texte visible sur cette page de document')
        ->toContain('Fais particulierement attention aux champs d identite')
        ->toContain('a la MRZ si elle est visible')
        ->toContain('Si une MRZ est visible, transcris-la exactement caractere par caractere')
        ->toContain("Conserve strictement les caracteres '<' et les separateurs '<<'")
        ->toContain('Retourne toutes les lignes de la MRZ dans le bon ordre')
        ->toContain("si la MRZ contient 'EL<ARRIM<<WADIE', retourne exactement 'EL<ARRIM<<WADIE'");
});

it('uses a dedicated MRZ-only OCR prompt', function () {
    $capturedContent = null;
    $ollamaClient = Mockery::mock(OllamaClient::class);
    $ollamaClient->shouldReceive('chatText')
        ->once()
        ->andReturnUsing(function (string $model, array $messages) use (&$capturedContent): string {
            expect($model)->not->toBe('');
            $capturedContent = $messages[0]['content'] ?? '';

            return 'IRFRA27KBIM1FC2<9924075293<<<<';
        });

    $transcriber = new VisionDocumentTranscriber($ollamaClient);
    $transcriber->extractMrzFromImages([dirname(__DIR__, 2).'/tests/Fixtures/documents/cin/cin.webp']);

    expect($capturedContent)
        ->toContain('Tu es un agent OCR specialise uniquement dans la lecture de MRZ')
        ->toContain('Retourne uniquement la MRZ visible sur cette page')
        ->toContain('Si aucune MRZ n est visible, retourne une chaine vide')
        ->toContain('Transcris la MRZ exactement caractere par caractere')
        ->toContain("Conserve strictement les caracteres '<' et les separateurs '<<'")
        ->toContain('sans les reformuler, sans les compacter');
});
