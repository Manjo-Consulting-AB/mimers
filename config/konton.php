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

];
