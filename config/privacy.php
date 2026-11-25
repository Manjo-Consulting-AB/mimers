<?php

/*
 * Integritetsbegäranden — se [[ADR-0095 Integritetsbegäranden]] § 4 och § 5
 * samt [[Konton och åtkomst]] § privacy_request.
 *
 * Listorna här är de värden kolumnernas CHECK-villkor tillåter, samlade på
 * ett ställe så att koden aldrig stavar en sträng och en ny typ blir en
 * ändring i config, inte i varje kommando. Statusarna, verifieringens ämnen,
 * metoder och resultat finns här; senare issuers listor — åtgärder,
 * bedömningar — läggs hit när de byggs.
 *
 * `deadline_months` är fristen i art. 12.3: en kalendermånad från
 * mottagandet, räknad med addMonthsNoOverflow() (ADR-0095 § 5). Den är inte
 * hårdkodad någon annanstans.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Typerna av begäran
    |--------------------------------------------------------------------------
    |
    | Art. 15–21. `consent_withdrawal` gäller bara behandling som vilar på
    | samtycke; kontoradering är ingen allmän återkallelse (ADR-0095 § 4).
    |
    */

    'types' => [
        'access',
        'rectification',
        'erasure',
        'restriction',
        'portability',
        'objection',
        'consent_withdrawal',
        'other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Statusarna
    |--------------------------------------------------------------------------
    |
    | De öppna är `received`, `verification_required`, `under_assessment`,
    | `in_progress` och `awaiting_requester`. De avslutade är `completed`,
    | `partially_completed`, `rejected` och `closed`, där `closed` betyder
    | avslutat utan sakprövning (ADR-0095 § 4).
    |
    | `statuses` är summan av de två andra listorna och används för att pröva
    | ett okänt värde; uppdelningen avgör vilka byten som är tillåtna.
    |
    */

    'statuses' => [
        'received',
        'verification_required',
        'under_assessment',
        'in_progress',
        'awaiting_requester',
        'completed',
        'partially_completed',
        'rejected',
        'closed',
    ],

    'open_statuses' => [
        'received',
        'verification_required',
        'under_assessment',
        'in_progress',
        'awaiting_requester',
    ],

    'closed_statuses' => [
        'completed',
        'partially_completed',
        'rejected',
        'closed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Prioriteterna
    |--------------------------------------------------------------------------
    */

    'priorities' => [
        'low',
        'normal',
        'high',
    ],

    /*
    |--------------------------------------------------------------------------
    | Verifieringen av identiteten och ett ombuds behörighet
    |--------------------------------------------------------------------------
    |
    | Vad som prövades och hur — aldrig vad som visades. En verifiering är en
    | händelse med `subject`, `method` och `result`, och ingen kopia av en
    | identitetshandling sparas, varken som text eller som bilaga (ADR-0095
    | § 2).
    |
    | Metoderna: svar från kontots adress, en begäran gjord inloggad,
    | kompletterande uppgifter, fullmakt eller annat.
    |
    */

    'verification_subjects' => [
        'identity',
        'authority',
    ],

    'verification_methods' => [
        'account_email',
        'signed_in_session',
        'additional_information',
        'power_of_attorney',
        'other',
    ],

    'verification_results' => [
        'verified',
        'insufficient',
        'failed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fristen
    |--------------------------------------------------------------------------
    |
    | En kalendermånad från mottagandet, se art. 12.3 och ADR-0095 § 5.
    | `deadline_months` är grundfristen; förlängningen räknas ovanpå den.
    |
    */

    'deadline_months' => (int) env('PRIVACY_DEADLINE_MONTHS', 1),

    /*
    |--------------------------------------------------------------------------
    | Förlängningen och omräkningen
    |--------------------------------------------------------------------------
    |
    | Art. 12.3 tillåter en förlängning med högst två månader sammanlagt, och
    | `privacy:extend` förlänger i steg om en eller två månader
    | (`extension_months`). `max_extension_months` är taket på summan, inte på
    | ett enskilt steg: 1 + 1 går, 2 + 1 går inte (ADR-0095 § 5).
    |
    | En förlängning efter den URSPRUNGLIGA fristen vägras — mottagandet plus
    | `deadline_months`, utan förlängning — eftersom den registrerade ska ha
    | beskedet inom den första månaden. `privacy:deadline` räknar i stället om
    | fristen från en ny dag, till exempel den dag kompletterande uppgifter
    | kom in, och behöver inget tak här: den nya fristen är alltid dagen plus
    | `deadline_months` plus den förlängning som redan beviljats.
    |
    */

    'extension_months' => [1, 2],

    'max_extension_months' => 2,

];
