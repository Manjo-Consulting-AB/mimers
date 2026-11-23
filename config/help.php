<?php

/*
 * Hjälpcentrets innehållsmodell — M80 · issue 460, [[ADR-0094 Hjälpcentret]] § 1.
 *
 * `types` är de värden en artikels `type` får ha. En ny typ är ett nytt värde
 * här; ingen ny modell krävs (ADR-0094 § 1).
 *
 * `stopwords` hör till sökningen (M81 · issue 466) och fylls per språk. Ett
 * ord som bara är ett stoppord tas bort ur frågan — utom när frågan bara
 * består av stoppord, för då hade den blivit tom (App\Support\Help\HelpSearch).
 *
 * `max_related` är taket för en artikels `related` (ADR-0094 § 1). Valideringen
 * läser taket härifrån, så formen och regeln inte kan glida isär.
 */

return [
    'types' => ['faq', 'guide', 'reference', 'troubleshooting'],

    'stopwords' => [
        'en' => [
            'a', 'an', 'and', 'are', 'can', 'do', 'does', 'for', 'how', 'i',
            'if', 'in', 'is', 'it', 'my', 'of', 'on', 'or', 'the', 'to',
            'what', 'when', 'where', 'who', 'why', 'with', 'you',
        ],
    ],

    'max_related' => 6,
];
