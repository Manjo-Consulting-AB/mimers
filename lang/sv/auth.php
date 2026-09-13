<?php

/*
 * Ramverket har ingen svensk katalog, så den här filen är komplett och inte
 * ett tillägg — till skillnad från lang/en/auth.php, se issue 52 § Beslut 7.
 * Nyckeluppsättningen är ramverkets; en nyckel som heter något annat tystnar
 * i stället för att felas.
 */
return [
    'failed' => 'Uppgifterna stämmer inte med våra register.',
    'password' => 'Lösenordet är fel.',
    'throttle' => 'För många inloggningsförsök. Försök igen om :seconds sekunder.',

    // Saknas i ramverket på båda språken — se lang/en/auth.php.
    'totp_required' => 'Ange koden från din autentiseringsapp.',
    'totp_invalid' => 'Koden är inte giltig. Försök igen.',
];
