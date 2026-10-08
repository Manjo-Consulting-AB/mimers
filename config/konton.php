<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kontolivscykelns tidsgränser
    |--------------------------------------------------------------------------
    |
    | Tre steg, se [[Planer och kvoter]] § Kontolivscykel och [[ADR-0009
    | Kvoter och livscykel]]: 12 månader utan aktivitet ger en påminnelse,
    | 15 månader stänger kontot (data behålls) och 18 månader raderar det.
    | Stegen byggs i två issues — 29a bygger påminnelsen och stängningen,
    | 29b läser inactivity_delete_months när raderingen byggs. Alla tre tal
    | bor här i stället för som konstanter i konsolklasserna: tre tal som
    | beskriver samma stege hör ihop, och två filer med var sin halva blir
    | två stegar.
    |
    | Gränsen räknas i kalendermånader — koden använder Carbon::subMonths(),
    | aldrig subDays(), så månadslängder blir rätt.
    |
    */

    'inactivity_notice_months' => (int) env('ACCOUNT_INACTIVITY_NOTICE_MONTHS', 12),

    'inactivity_close_months' => (int) env('ACCOUNT_INACTIVITY_CLOSE_MONTHS', 15),

    'inactivity_delete_months' => (int) env('ACCOUNT_INACTIVITY_DELETE_MONTHS', 18),

    /*
    |--------------------------------------------------------------------------
    | Gallringsfrist för registrerings-IP:t
    |--------------------------------------------------------------------------
    |
    | Hur länge account.registration_ip behålls innan App\Console\
    | PrunesRegistrationIps nollar det, räknat i dagar från account.
    | created_at. Nittio dagar för att [[ADR-0017 Missbruksvektorer]]
    | § Konsekvenser säger att trösklarna ska revideras när tre månaders data
    | finns — kortare och den nattliga rapporten (50b) kan inte se det mönster
    | den finns till för, längre och vi behåller en personuppgift utan att
    | kunna säga varför. Se [[Registerförteckning]].
    |
    */

    'registration_ip_retention_days' => (int) env('ACCOUNT_REGISTRATION_IP_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Interna Pro-konton
    |--------------------------------------------------------------------------
    |
    | Adresserna här får Pro utan betalning — två interna användare som ska
    | kunna pröva varje funktion på staging och produktion, se [[M24
    | Desktopdesignen]] § Internt Pro.
    |
    | Pro ges som DATA, inte som ett undantag i planlogiken:
    | App\Actions\Plan\GrantInternalPro skriver en vanlig subscription-rad
    | (plan `pro`, status `active`, external_ref `internal`) åt kontot. Då bär
    | Account::currentPlan(), PlanResource::planFor() och
    | ReportsAbuseSignals::freeAccountsQuery() samma regel som för alla andra
    | konton, utan att någon av dem ändras.
    |
    | Jämförelsen är skiftlägesokänslig. Att ta bort Pro från en adress som
    | lämnar listan görs för hand — actionen och migrationen lägger bara till.
    |
    */

    'internal_pro_emails' => ['tony@manjo.me', 'mia@manjo.me'],

    /*
    |--------------------------------------------------------------------------
    | Registreringens läge
    |--------------------------------------------------------------------------
    |
    | `open` eller `invite_only`, se [[ADR-0055 Inbjudningskoder och stängd
    | registrering]] § 1. Förvalet är `invite_only`: Mimers är i privat beta,
    | och den som registrerar sig behöver en voucher eller en utestående
    | containerinbjudan. Att öppna registreringen är att byta det här värdet
    | och ingenting annat.
    |
    | Läget prövas på ett ställe, App\Actions\Auth\AdmitRegistration, som
    | både webbens och API:ets registrering går genom. Sätts i staging och
    | produktion via ACCOUNT_REGISTRATION i shared/.env.
    |
    */

    'registration' => env('ACCOUNT_REGISTRATION', 'invite_only'),

];
