<?php

// rott-pa-basen: flytten — före ändringen finns inget innehåll i den nya formen, så HelpValidator har inget att pröva och ger noll fel även på basen. Efter flytten prövar samma anrop alla 66 artiklar, kategorierna och sammanhangen.

use App\Support\Help\HelpValidator;

/*
 * M80 · issue 461 — hjälpinnehållet i lang/. HelpValidator prövar front mattern,
 * kategorierna och sammanhangen, och ett enda fel fäller PR:en. Roten är
 * `lang/`, katalogen som innehåller `en/help/`.
 */

it('har giltigt hjälpinnehåll', function () {
    expect((new HelpValidator)->validate(lang_path()))->toBe([]);
});
