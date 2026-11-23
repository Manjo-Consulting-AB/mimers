<?php

/*
 * Hjälpcentrets innehållsmodell — M80 · issue 460, [[ADR-0094 Hjälpcentret]] § 1.
 *
 * `types` är de värden en artikels `type` får ha. En ny typ är ett nytt värde
 * här; ingen ny modell krävs (ADR-0094 § 1).
 *
 * `stopwords` hör till sökningen (M81) och fylls per språk när språket får sitt
 * innehåll. Engelskan står tom med flit — 466 fyller den.
 *
 * `max_related` är taket för en artikels `related` (ADR-0094 § 1). Valideringen
 * läser taket härifrån, så formen och regeln inte kan glida isär.
 */

return [
    'types' => ['faq', 'guide', 'reference', 'troubleshooting'],

    'stopwords' => [
        'en' => [],
    ],

    'max_related' => 6,
];
