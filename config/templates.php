<?php

/*
 * Gränserna för mallar, se [[ADR-0089 Egna fält på items]] § Riktning 9 och
 * [[Items och organisation]] § container_template.
 *
 * Gränsen prövas i applikationen (App\Actions\Template\SaveContainerTemplate),
 * aldrig i schemat, och är densamma för alla planer: det som kostar är
 * lagringen, och den räknas redan mot kvoten
 * ([[ADR-0081 All lagrad data räknas mot kvoten]]).
 *
 * Den 51:a mallen ger 422 `template.limit_reached` med gränsen i `data`.
 * Bara mallar som inte ligger i papperskorgen räknas.
 *
 * `max_categories` prövas av App\Actions\Template\ApplyTemplate före den
 * första skrivningen, så en tillämpning över taket ger 422
 * `template.max_categories_exceeded` med gränsen i `data` och ingenting
 * ändras — oavsett om innehållet kom ur en sparad mall eller skickades som
 * `template_content`.
 */
return [
    // Mallar per konto.
    'max_per_account' => 50,

    // Kategorinoder i en malls innehåll, räknat över hela trädet och alla
    // nivåer. En tillämpning skapar en rad per nod, så taket stänger samma
    // missbruksvektor som App\Http\Requests\Category\StoreCategoryPresetRequest
    // gör för kategoriförslagen ([[ADR-0017 Missbruksvektorer]]) och håller
    // transaktionen kort. 100 räcker med marginal för ett träd som går att
    // skapa för hand — kategoriförslagen har 12 rötter och 12 barn.
    'max_categories' => 100,
];
