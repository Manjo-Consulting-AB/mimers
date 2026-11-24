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
 *
 * `event_retention_days` och `search_gap_retention_days` är gallringsfristerna
 * för hjälpens mätning (M82 · issue 470, ADR-0094 § 8): händelserna sparas i
 * 400 dagar och sökfrågorna utan träff i 90. Fristerna bor här av samma skäl
 * som loggarnas i config/loggar.php — de är uppgifter om verksamheten, inte om
 * koden — och läses av App\Console\PrunesHelpData.
 *
 * `search_gap_daily_limit` och `unknown_context_daily_limit` är taken som gör
 * att ingen kan fylla tabellerna genom att spruta frågor eller sammanhang:
 * högst 1000 nya rader per dag vardera. En rad som redan finns räknas upp
 * även när taket är nått.
 *
 * `feedback_retention_days` är fristen för återkopplingen (M82 · issue 471,
 * ADR-0094 § 7): en rad *Was this helpful?* sparas i 90 dagar och raderas
 * sedan av App\Console\PrunesHelpFeedback. Frister räknas i dagar, som
 * mätningens ovan.
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

    'event_retention_days' => 400,

    'search_gap_retention_days' => 90,

    'search_gap_daily_limit' => 1000,

    'unknown_context_daily_limit' => 1000,

    'feedback_retention_days' => 90,
];
