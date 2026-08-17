<?php

declare(strict_types=1);

use Ges\Ocr\Contracts\LlmClient;
use Ges\Ocr\DocumentSchemaFactory;
use Ges\Ocr\OpenAiDocumentAnalyzer;

/*
 * Les transcriptions intégrales archivées des lots
 * problématiques ne sont pas toutes rejouables :
 *
 * - le texte du lot 584 est fortement dégradé ;
 * - les textes des lots 715, 763, 838, 897 et 1204
 *   ne contiennent que des séparateurs de pages.
 *
 * Ces fixtures compactes reproduisent donc localement
 * les anomalies cadastrales déterministes identifiées
 * dans ces lots, sans appel réseau ni dépendance LLM.
 */
it(
    'replays a compact cadastral regression fixture '
    .'from a problematic MSA lot',
    function (
        string $text,
        array $expected,
    ): void {
        $analyzer = new OpenAiDocumentAnalyzer(
            Mockery::mock(LlmClient::class),
            new DocumentSchemaFactory,
        );

        expect(
            $analyzer->extractMsaTextParcels(
                $text,
            ),
        )->toBe($expected);
    },
)->with([
    /*
     * Lot 584 :
     * - contexte département/commune séparé ;
     * - section cadastrale à une lettre ;
     * - ligne TOTAL non cadastrale.
     */
    'lot 584 — one-letter section and total row' => [
        <<<'TEXT'
85
025 S 00093
C 0175 01 P
C 0176 01 P
* TOTAL COMMUNE 0003512
TEXT,
        [
            [
                'dept' => '85',
                'com' => '025',
                'prefixe' => '',
                'section' => '0C',
                'numero_plan' => '0175',
            ],
            [
                'dept' => '85',
                'com' => '025',
                'prefixe' => '',
                'section' => '0C',
                'numero_plan' => '0176',
            ],
        ],
    ],

    /*
     * Lot 715 :
     * - ligne culturale répétant une parcelle ;
     * - changement de commune ;
     * - apparition d’un préfixe ;
     * - absence de propagation du préfixe.
     */
    'lot 715 — commune and prefix changes' => [
        <<<'TEXT'
49 260 C 00001 C 0221 J 02 T
C 0221 K 03 T
49 301 C 00002 210 C 0008 03 T
C 0009 03 T
TEXT,
        [
            [
                'dept' => '49',
                'com' => '260',
                'prefixe' => '',
                'section' => '0C',
                'numero_plan' => '0221',
            ],
            [
                'dept' => '49',
                'com' => '260',
                'prefixe' => '',
                'section' => '0C',
                'numero_plan' => '0221',
            ],
            [
                'dept' => '49',
                'com' => '301',
                'prefixe' => '210',
                'section' => '0C',
                'numero_plan' => '0008',
            ],
            [
                'dept' => '49',
                'com' => '301',
                'prefixe' => '',
                'section' => '0C',
                'numero_plan' => '0009',
            ],
        ],
    ],

    /*
     * Lot 763 :
     * - changement de commune ;
     * - compte propriétaire commençant par 003 ;
     * - préfixe cadastral séparé du compte ;
     * - ligne culturale.
     */
    'lot 763 — owner account and commune change' => [
        <<<'TEXT'
49 021 C 00140 ZS 0182 03 T
ZS 0182 K 03 T
49 138 C 00318 049 AB 0012 03 T
AB 0013 02 P
TEXT,
        [
            [
                'dept' => '49',
                'com' => '021',
                'prefixe' => '',
                'section' => 'ZS',
                'numero_plan' => '0182',
            ],
            [
                'dept' => '49',
                'com' => '021',
                'prefixe' => '',
                'section' => 'ZS',
                'numero_plan' => '0182',
            ],
            [
                'dept' => '49',
                'com' => '138',
                'prefixe' => '049',
                'section' => 'AB',
                'numero_plan' => '0012',
            ],
            [
                'dept' => '49',
                'com' => '138',
                'prefixe' => '',
                'section' => 'AB',
                'numero_plan' => '0013',
            ],
        ],
    ],

    /*
     * Lot 838 :
     * - numéro de compte non utilisé comme préfixe ;
     * - absence de préfixe inventé ;
     * - plusieurs communes ;
     * - section cadastrale à une lettre.
     */
    'lot 838 — no invented or number-derived prefix' => [
        <<<'TEXT'
85 059 C 00184 ZC 0022 03 T
ZC 0022 K 03 T
85 252 C 00318 ZM 0044 03 T
85 289 C 00385 B 0185 03 T
TEXT,
        [
            [
                'dept' => '85',
                'com' => '059',
                'prefixe' => '',
                'section' => 'ZC',
                'numero_plan' => '0022',
            ],
            [
                'dept' => '85',
                'com' => '059',
                'prefixe' => '',
                'section' => 'ZC',
                'numero_plan' => '0022',
            ],
            [
                'dept' => '85',
                'com' => '252',
                'prefixe' => '',
                'section' => 'ZM',
                'numero_plan' => '0044',
            ],
            [
                'dept' => '85',
                'com' => '289',
                'prefixe' => '',
                'section' => '0B',
                'numero_plan' => '0185',
            ],
        ],
    ],

    /*
     * Lot 897 :
     * - préfixe explicite puis retour à un préfixe vide ;
     * - changement de commune ;
     * - préservation d’une section à deux lettres.
     */
    'lot 897 — prefix reset and two-letter section' => [
        <<<'TEXT'
49 023 C 00184 162 F 0386 03 T
F 0389 03 T
49 024 C 00200 WT 0042 03 T
WT 0043 03 T
TEXT,
        [
            [
                'dept' => '49',
                'com' => '023',
                'prefixe' => '162',
                'section' => '0F',
                'numero_plan' => '0386',
            ],
            [
                'dept' => '49',
                'com' => '023',
                'prefixe' => '',
                'section' => '0F',
                'numero_plan' => '0389',
            ],
            [
                'dept' => '49',
                'com' => '024',
                'prefixe' => '',
                'section' => 'WT',
                'numero_plan' => '0042',
            ],
            [
                'dept' => '49',
                'com' => '024',
                'prefixe' => '',
                'section' => 'WT',
                'numero_plan' => '0043',
            ],
        ],
    ],

    /*
     * Lot 1204 :
     * - département et commune sur des lignes séparées ;
     * - changement de préfixe dans un même compte ;
     * - retour à un préfixe vide ;
     * - ligne TOTAL ignorée.
     */
    'lot 1204 — prefix changes inside one account' => [
        <<<'TEXT'
49
183 C 00108
108 F 0506 03 T
F 0507 03 T
183 C 00376
376 A 0354 03 T
A 0355 03 T
* TOTAL COMMUNE 0020500
TEXT,
        [
            [
                'dept' => '49',
                'com' => '183',
                'prefixe' => '108',
                'section' => '0F',
                'numero_plan' => '0506',
            ],
            [
                'dept' => '49',
                'com' => '183',
                'prefixe' => '',
                'section' => '0F',
                'numero_plan' => '0507',
            ],
            [
                'dept' => '49',
                'com' => '183',
                'prefixe' => '376',
                'section' => '0A',
                'numero_plan' => '0354',
            ],
            [
                'dept' => '49',
                'com' => '183',
                'prefixe' => '',
                'section' => '0A',
                'numero_plan' => '0355',
            ],
        ],
    ],
]);
