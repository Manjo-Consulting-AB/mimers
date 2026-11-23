<?php

use App\Support\Help\HelpLibrary;
use Illuminate\Support\Facades\File;

/*
 * M81 · issue 469 — hjälplänkarna i appens vyer, [[ADR-0094 Hjälpcentret]] § 6.
 *
 * Varje vy i issuens tabell länkar till hjälpen med det sammanhang dess
 * innehåll bär, och ingen annan väg finns: `HelpLink` är komponenten 468
 * byggde, och `SammanhangTest` fäller ett sammanhang som inte finns i
 * `lang/en/help/articles/`. Här prövas att varje vy faktiskt använder den —
 * importen och två länkar, en för telefonens ikon och en för den bredare
 * ytans ikon med etiketten *Help*.
 *
 * **Två länkar och inte en.** `iconOnly` är en propp, inte en brytpunkt, och
 * `HelpLink` ligger utanför rutan. Vyn ritar därför ikonen under `sm:` och
 * ikonen med etiketten däröver, med samma sammanhang i båda. `SammanhangTest`
 * läser båda och finner samma sammanhang i innehållet.
 *
 * **Raden är rubrikens.** Länken står i samma flexrad som rubriken — `<h1>`
 * på containersidorna, `<h2>` på inställningssidorna och beskrivningen under
 * `AppHero` på `/trash` — så att rubrikens text, nivå och avstånd är orörda.
 * Formen prövas för hand på mobilbredd (PR:en), inte här: en källkodsläsning
 * kan inte mäta om länken ryms.
 */

/**
 * Vyerna med sina sammanhang — issuens tabell, en rad per vy.
 *
 * @return array<string, string>
 */
function hjalplankarVyer(): array
{
    return [
        'resources/js/pages/Containers/Sharing.vue' => 'container.sharing',
        'resources/js/pages/Containers/Transfers.vue' => 'container.transfer',
        'resources/js/pages/Containers/Agents.vue' => 'container.agents',
        'resources/js/pages/Containers/Tasks.vue' => 'container.tasks',
        'resources/js/pages/Containers/CalendarFeed.vue' => 'container.calendar',
        'resources/js/pages/Containers/Fields.vue' => 'container.fields',
        'resources/js/pages/Containers/Export.vue' => 'container.export',
        'resources/js/pages/Containers/Trash.vue' => 'trash',
        'resources/js/pages/Trash/Index.vue' => 'trash',
        'resources/js/pages/Settings/Templates.vue' => 'account.templates',
        'resources/js/pages/Settings/Security.vue' => 'account.security',
        'resources/js/pages/Settings/Storage.vue' => 'account.storage',
        'resources/js/pages/Settings/Plan.vue' => 'account.plan',
        'resources/js/pages/Settings/Agents.vue' => 'account.agents',
    ];
}

it('importerar och ritar HelpLink med sitt sammanhang i varje vy', function () {
    $fynd = [];

    foreach (hjalplankarVyer() as $fil => $sammanhang) {
        $kod = File::get(base_path($fil));

        if (! str_contains($kod, "import HelpLink from '../../components/HelpLink.vue';")) {
            $fynd[] = "{$fil} importerar inte HelpLink";
        }

        // Telefonens ikon och den bredare radens ikon med etiketten.
        //
        // Döljningen måste vara en variantregel (`max-sm:` respektive `sm:`),
        // inte `hidden`: `HelpLink` bär själv `inline-flex`, och i den byggda
        // stilen står den regeln efter `.hidden` med samma specificitet. Ett
        // naket `hidden` förlorar därför och båda länkarna syntes på telefon.
        foreach ([
            sprintf('<HelpLink context="%s" icon-only class="sm:hidden" />', $sammanhang),
            sprintf('<HelpLink context="%s" class="max-sm:hidden" />', $sammanhang),
        ] as $tagg) {
            if (! str_contains($kod, $tagg)) {
                $fynd[] = "{$fil} ritar inte {$tagg}";
            }
        }
    }

    expect($fynd)->toBe([], "Hjälplänken saknas i en vy:\n".implode("\n", $fynd));
});

it('använder alla tretton sammanhang i tabellen', function () {
    $sammanhang = array_values(hjalplankarVyer());

    expect(hjalplankarVyer())->toHaveCount(14)
        ->and(array_unique($sammanhang))->toHaveCount(13)
        ->and($sammanhang)->toContain('trash');
});

it('använder bara sammanhang som finns i innehållet', function () {
    // Samma upplysning som SammanhangTest ger, men per rad i tabellen: en vy
    // som byter till ett sammanhang ingen artikel bär fälls här med filnamnet
    // i svaret i stället för med en rad i den stora svepningen.
    $katalog = (new HelpLibrary)->catalog('en');
    $fynd = [];

    foreach (hjalplankarVyer() as $fil => $sammanhang) {
        if ($katalog->byContext($sammanhang) === null) {
            $fynd[] = "{$fil} använder sammanhanget \"{$sammanhang}\", som inte finns i HelpLibrary::catalog('en')";
        }
    }

    expect($fynd)->toBe([], "Hjälplänken pekar på ett sammanhang som inte finns:\n".implode("\n", $fynd));
});
