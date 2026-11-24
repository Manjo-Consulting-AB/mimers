<?php

/*
 * Integritetsbegäranden — se [[ADR-0095 Integritetsbegäranden]] § 4 och § 5
 * samt [[Konton och åtkomst]] § privacy_request.
 *
 * Listorna här är de värden kolumnernas CHECK-villkor tillåter, samlade på
 * ett ställe så att koden aldrig stavar en sträng och en ny typ blir en
 * ändring i config, inte i varje kommando. Senare issuers listor — statusar
 * att flytta mellan, åtgärder, verifieringsmetoder — läggs här när de
 * byggs; den här filen bär bara det första ärendet.
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
    | avslutat utan sakprövning (ADR-0095 § 4). Uppdelningen i öppna och
    | avslutade lägen kommer med kommandot som flyttar ärendet (issue 482).
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
    | Fristen
    |--------------------------------------------------------------------------
    |
    | En kalendermånad från mottagandet, se art. 12.3 och ADR-0095 § 5.
    | Förlängningen med högst två månader (issue 483) räknas ovanpå den här.
    |
    */

    'deadline_months' => (int) env('PRIVACY_DEADLINE_MONTHS', 1),

];
