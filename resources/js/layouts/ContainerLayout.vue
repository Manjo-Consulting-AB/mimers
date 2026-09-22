<script setup>
import { computed } from 'vue';
import AppLayout from './AppLayout.vue';
import UiTabs from '../components/UiTabs.vue';
import { containerTabs } from './containerSections.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Containerns skal, se issue 54 § Beslut 7 och issue 101 ·
 * [[ADR-0042 Designsystemet]].
 *
 * Samma konstruktion som SettingsLayout och av samma skäl: 55a (delning),
 * 56a (kategorier och taggar), 57 (items), 62 (papperskorg) och 63 (scheman)
 * får alla en sida per container, och varje sida wrappar sitt innehåll i den här:
 *
 *   <ContainerLayout> ... </ContainerLayout>
 *
 * PROPKONTRAKTET: varje sida under den här layouten skickar en prop
 * `container` ur App\Http\Resources\ContainerResource — `{ ulid, name, kind,
 * account, created_at, updated_at }`. Rubriken läser `container.name` och
 * flikarnas href byggs ur `container.ulid`. Kontrollern gör
 * `ContainerResource::make($container)->resolve($request)`.
 *
 * Den aktiva containern läses INTE här. Den delade propen `activeContainer` bär
 * ULID:t och ingenting annat (issue 51 § Beslut 4) — en sida som behöver
 * containerns namn får det som sin egen `container`-prop, inte ur en utökad
 * delad prop. AppLayout har av samma skäl ingen containerväljare: listan på
 * /containers är ytan där containers byts.
 *
 * **Sektionsmenyn är en flikrad** (issue 101). Fram till dess renderade den här
 * layouten nio länkar i en kolumn ur `containerSections` — en vägg man mötte på
 * varje sida, och en navigering som sade *vad som finns* i stället för *var man
 * är*. Nu renderas flikraden av `UiTabs` (issue 100), som äger formen,
 * tangentbordet och den aktiva fliken; hit hör bara VILKA flikar som finns och
 * vad de heter. De sju sektionerna som inte fick plats — kategorier, taggar,
 * delning, kalender, export, papperskorg och överlåtelse — samlas på
 * inställningssidan, som är en av flikarna, så ingen av dem tappar sin väg.
 * Listan bor i containerSections.js; en ny flik är en ny rad där och ingen
 * ändring här.
 *
 * **Den aktiva fliken läses ur adressen och inte här.** `UiTabs` jämför
 * `page.url` med flikarnas egna href, så den här filen håller inget tillstånd
 * och ingen jämförelse: en flik man kan länka till är en flik man kan dela
 * (issue 100, issue 59a § Beslut 1).
 *
 * **Flikarnas etikett kommer ur `t()` med nyckeln `container.nav.<key>`,**
 * aldrig ur en sträng i den här filen ([[ADR-0013 Språk och i18n]]): texten
 * formuleras på servern och slås bara upp på klienten, precis som förut.
 * `label` är tablistens tillgängliga namn och är containerns namn — samma namn
 * den gamla sektionsmenyn bar, och det som säger vilken container raden hör
 * till.
 *
 * **Ingen ihopfällning längre** (issue 68a § Beslut 2). Den fällda menyn fanns
 * för att nio rader ovanför innehållet är nio rader man skrollar förbi; flikraden
 * är ett enda band som bryter (`flex-wrap`) i stället för att skrolla i sidled,
 * och då behövs ingen meny att fälla upp. Träffytan är `min-h-11` — 44 px ur
 * issue 68a § Beslut 3 — och den bor i `UiTabs`, som i varje annan radåtgärd.
 */
const props = defineProps({
    container: { type: Object, required: true },
});

const { t } = useTranslations();

const heading = computed(() => props.container.name);

/*
 * Flikarna i den form `UiTabs` vill ha: `{ key, label, href, count }`. `count`
 * är `null` och inte `0`: ingen av flikarna bär ett tal i bilden, och en nolla
 * hade varit ett påstående om innehållet som den här layouten inte har gjort
 * (issue 100). Servern räknar talen, och den som en dag vill visa ett skickar
 * in det.
 */
const tabs = computed(() =>
    containerTabs.map((tab) => ({
        key: tab.key,
        label: t(`container.nav.${tab.key}`),
        href: tab.href(props.container.ulid),
        count: null,
    })),
);
</script>

<template>
    <AppLayout>
        <div class="flex flex-col gap-8">
            <div>
                <p class="px-3 py-2 font-medium text-title">{{ heading }}</p>

                <UiTabs :tabs="tabs" :label="heading" />
            </div>

            <div class="min-w-0">
                <slot />
            </div>
        </div>
    </AppLayout>
</template>
