<?php

declare(strict_types=1);

use Ges\Ocr\Models\DocumentProcessing;
use Ges\Ocr\MrzProcessor;

it('cleans a noisy residence permit mrz candidate into a parseable form', function () {
    $processor = new MrzProcessor;

    $result = $processor->best(DocumentProcessing::BUSINESS_TYPE_TITRE_DE_SEJOUR, [
        'IR<FR*A27KBIM1FC<<2<9924075293<<<<8904067M2803180MAR<<<<<<<<<<<<8EL<ARRIM<<WADIE<<<<<<<<<<<<<<<<<',
        "IRFRA27KBIM1FC2<9924075293<<<<\n8904067M2803180MAR<<<<<<<<<<<<8\nEL<ARRIM<<WADIE<<<<<<<<<<<<<<<<<",
    ]);

    expect($result)
        ->toContain('IRFRA27KBIM1FC2<9924075293<<<<')
        ->toContain('8904067M2803180MAR')
        ->toContain('EL<ARRIM<<WADIE')
        ->not->toContain('*');
});

it('prefers a compact valid mrz over an overlong noisy candidate', function () {
    $processor = new MrzProcessor;

    $result = $processor->best(DocumentProcessing::BUSINESS_TYPE_CIN, [
        '9007138F3002119FRA<<<<<<<<<<<<<<6',
        'IDFRAX4RTBPFW46<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<',
    ]);

    expect($result)->toBe('9007138F3002119FRA<<<<<<<<<<<<<<6');
});

it('drops non-mrz text from a mixed multiline identity-card candidate', function () {
    $processor = new MrzProcessor;

    $result = $processor->best(DocumentProcessing::BUSINESS_TYPE_CIN, [
        "<<GAURENNE<THIERRY<CLAUDE<FRANCOIS<<M<FRA<14091964<MONTAUBAN<N9EB26TJ7<26042032<<\n6409144M3204267FRA<<<<<<<<<4\nGAURENNE<<THIERRY<<CLAUDE<FRANC",
        '6409144M3204267FRA',
    ]);

    expect($result)
        ->toContain('6409144M3204267FRA')
        ->not->toContain('MONTAUBAN')
        ->not->toContain('N9EB26TJ7');
});
