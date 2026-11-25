<?php

/*
 * Integritetsbegäranden — se [[ADR-0095 Integritetsbegäranden]] § 4 och § 5
 * samt [[Konton och åtkomst]] § privacy_request.
 *
 * Listorna här är de värden kolumnernas CHECK-villkor tillåter, samlade på
 * ett ställe så att koden aldrig stavar en sträng och en ny typ blir en
 * ändring i config, inte i varje kommando. Här finns statusarna,
 * verifieringens ämnen, metoder och resultat, och det som bedömningen,
 * åtgärderna och kommunikationen antecknar (ADR-0095 § 7).
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

    /*
    |--------------------------------------------------------------------------
    | Rollen
    |--------------------------------------------------------------------------
    |
    | Mimers roll för det begäran gäller. Bedömningen sätter den på ärendet,
    | och listan prövas av `privacy:assess` (ADR-0095 § 7). `undetermined` är
    | förvalet på ett nytt ärende och kan inte väljas som bedömning. Rollen
    | avgör om raden i [[Registerförteckning]] är vår eller kundens.
    |
    */

    'roles' => [
        'controller',
        'processor',
        'both',
    ],

    /*
    |--------------------------------------------------------------------------
    | Systemen
    |--------------------------------------------------------------------------
    |
    | Var uppgifterna bor och var en åtgärd vidtogs. Kategorierna nedan är
    | raderna i [[Registerförteckning]]; den här listan säger vilket system
    | raden finns i.
    |
    */

    'systems' => [
        'database',
        'user_content',
        'sentry',
        'mailgun',
        'backups',
        'server_logs',
        'other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Datakategorierna
    |--------------------------------------------------------------------------
    |
    | Kategorierna är raderna i [[Registerförteckning]], så att en sökning på
    | ärendena går att lita på: samma ord om samma uppgift, oavsett vilket
    | system åtgärden gällde. `other` finns för det som ännu inte fått en rad.
    |
    */

    'data_categories' => [
        'profile',
        'account',
        'sessions',
        'security_log',
        'audit_log',
        'agents',
        'help_feedback',
        'item_versions',
        'user_content',
        'error_reports',
        'email_delivery',
        'other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Operationerna
    |--------------------------------------------------------------------------
    |
    | Vad som gjordes med uppgifterna, och därmed vilken rad i
    | [[Registerförteckning]] det gällde. `referred_to_controller` används när
    | Mimers är biträde och begäran gäller kundens innehåll (ADR-0095 § 7),
    | och `self_service` när den registrerade hänvisats till eller själv
    | använt en funktion — aldrig som ett fullgörande (ADR-0095 § 9).
    |
    */

    'operations' => [
        'searched',
        'exported',
        'corrected',
        'erased',
        'retained',
        'referred_to_controller',
        'self_service',
    ],

    /*
    |--------------------------------------------------------------------------
    | Skälen att behålla uppgifter
    |--------------------------------------------------------------------------
    |
    | `retained` kräver ett skäl ur listan (ADR-0095 § 7), och skälet hör till
    | raden i [[Registerförteckning]] som behålls. `legal_hold` godtas bara när
    | ärendets konto omfattas av en gällande spärr, se
    | [[ADR-0043 Tre loggar]] § Den rättsliga spärren.
    |
    */

    'retention_reasons' => [
        'legal_obligation',
        'legal_claims',
        'legal_hold',
        'other',
    ],

    /*
    |--------------------------------------------------------------------------
    | De rättsliga grunderna
    |--------------------------------------------------------------------------
    |
    | Art. 6.1. Vilken grund bedömningen vilar på, och därmed vilken rad i
    | [[Registerförteckning]] som gäller för behandlingen.
    |
    */

    'legal_bases' => [
        'contract',
        'legitimate_interest',
        'legal_obligation',
        'consent',
        'not_applicable',
    ],

    /*
    |--------------------------------------------------------------------------
    | Besluten
    |--------------------------------------------------------------------------
    |
    | Bedömningens utfall för de rader i [[Registerförteckning]] som begäran
    | gäller. Ett ärende kan ha flera bedömningar, en per datamängd eller
    | behandling, så att delvis bifall går att dokumentera (ADR-0095 § 7).
    | `partial` är ett delvis bifall, inte ett avslag.
    |
    */

    'decisions' => [
        'grant',
        'partial',
        'refuse',
    ],

    /*
    |--------------------------------------------------------------------------
    | Kommunikationens slag
    |--------------------------------------------------------------------------
    |
    | Vad som skickades till eller togs emot från den registrerade, som en del
    | av hanteringen av raden i [[Registerförteckning]]. Slaget kompletterar
    | riktningen och kanalen; fritexten står i `--note`.
    |
    */

    'communication_kinds' => [
        'acknowledgement',
        'clarification_request',
        'extension_notice',
        'final_response',
        'other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Kanalerna
    |--------------------------------------------------------------------------
    |
    | Var kommunikationen gick, om raden i [[Registerförteckning]] eller om
    | begäran själv. Kanalen säger ingenting om kategorierna ovan; den hör
    | bara till `privacy:communicate`.
    |
    */

    'channels' => [
        'email',
        'post',
        'other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Självbetjäningens funktioner
    |--------------------------------------------------------------------------
    |
    | Vilken funktion den registrerade hänvisats till eller själv använt, för
    | de rader i [[Registerförteckning]] funktionen rör. Det är en anteckning
    | om ett svar, inte ett fullgörande: containerexporten är inte ett
    | fullständigt svar på art. 15 eller 20 (ADR-0095 § 9).
    |
    */

    'self_service_features' => [
        'profile',
        'user_deletion',
        'account_deletion',
        'container_export',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bilagorna
    |--------------------------------------------------------------------------
    |
    | Största filen som `privacy:attach` tar emot (ADR-0095 § 10). En bilaga
    | är ett svar, en sammanställning eller en fullmakt — inte en
    | innehållssamling — och gränsen håller disken och den mänskliga
    | hanteringen rimlig. Förvalet är 25 MB; en större fil vägras och
    | lämnar varken rad eller fil.
    |
    | Bilagorna räknas inte mot någon kvot och hör inte till den
    | innehållsadresserade lagringen. Gränsen är den enda storleksregeln.
    |
    */

    'attachment_max_bytes' => (int) env('PRIVACY_ATTACHMENT_MAX_BYTES', 25 * 1024 * 1024),

];
