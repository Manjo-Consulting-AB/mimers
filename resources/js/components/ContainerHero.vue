<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ContainerCover from './ContainerCover.vue';
import ContainerCoverSheet from './ContainerCoverSheet.vue';
import UiIcon from './UiIcon.vue';
import UiStat from './UiStat.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Containerns hjälte, se issue 170 · [[ADR-0050 Desktopdesignen]] § 2–3 och
 * [[ADR-0047 Containerns bild]] § Beslut.
 *
 * **Hjälten är containerns huvud över `md:`.** Den visar bilden, arten, namnet
 * och HELA `description` som undertitel. Beskrivningen delas inte upp (issue
 * 88) och kortas inte (ADR-0050 § 2); blir den för lång är det en senare fråga.
 * Är den tom utelämnas undertiteln helt — en tom rad vore ett påstående om att
 * något saknas, samma regel som översiktens huvud.
 *
 * **Bilden är `ContainerCover` och ingenting annat.** Komponenten väljer inte
 * sin egen storlek — den fyller rutan anroparen ställer den i — och ritar den
 * neutrala ytan med containertecknet när containern saknar bild (ADR-0047
 * § Beslut). Hjälten lägger den som ett lager UNDER texten i stället för att
 * bygga en andra väg till samma bild.
 *
 * **Texten står alltid på den täta delen av toningen.** Lagret ovanpå bilden
 * är en toning från `--color-shell` till genomskinnligt, vänster till höger
 * ([[ADR-0057 Ikoner och typsnitt]] § 6), i stället för den jämna
 * mörkläggningen. Toningen håller full täthet en bit in och planar sedan ut:
 * namn, art och beskrivning står i den täta delen med vit text, och bilden
 * syns utan mörkläggning i hjältens högra del. Textspalten är kapad så att
 * den aldrig växer in i den glesa delen. Utan bild ritar `ContainerCover`
 * fortfarande den neutrala ytan ur [[ADR-0047 Containerns bild]] § Beslut —
 * toningen läggs över den som över varje annan yta.
 *
 * **Talen bärs av den höga hjälten och är länkar.** `hero` styr hjältens HÖJD
 * (`min-h-56` / `min-h-32`) och talen: den låga hjälten har toningen men inga
 * tal ([[ADR-0050 Desktopdesignen]] § 2–3). Talen kommer ur den delade
 * proppen `containerCounts` — `{ items, todos }`, byggd i
 * App\Http\Middleware\HandleInertiaRequests::containerCounts() (issue 679) —
 * och hjälten läser den själv ur `page.props`. Hjälten frågar alltså ingenting
 * själv om siffrorna: en räkning här hade varit en andra väg till samma tal,
 * och de två hade glidit isär ([[ADR-0039 Containerns översikt]]
 * § Konsekvenser). Proppen är `null` utanför en container, och då ritas inga
 * tal. *Items* leder till itemlistan och *Open tasks* till uppgiftsfliken —
 * samma grepp som dashboardens brickor fick i issue 654: länken bor i hjälten
 * och inte i `UiStat`, som förblir en form ([[ADR-0042 Designsystemet]]
 * § Beslut). Taltutan ritas i formen `on-image` med ikonerna `item` och
 * `task` (issue 283), så den står på glaset ur ADR-0057 § 6.
 *
 * ***Redigera container* och menyknappen står på glaset.** Knappen leder till
 * inställningssidan ([[ADR-0050 Desktopdesignen]] § 3) och ritas bara när
 * `can.update` är sann — flaggan är presentation, och rutten prövar
 * `ContainerPolicy::update` på nytt (ADR-0047 § Beslut, "Vem som får göra
 * vad"). Flikradens *Settings* och pennan i skalets topprad är andra vägar
 * till samma val. Menyn samlar hjältens sekundära handling — *Byt bild*,
 * samma ark som pennan och inställningssidan öppnar (ADR-0047 § Beslut) —
 * och ritas på samma villkor som knappen, eftersom arket kräver samma
 * behörighet. Den ritas som en `<details>`, samma grepp som radmenyn i
 * `TodoRow`: webbläsaren äger öppet-tillståndet, och Escape stänger den.
 *
 * **Namnet är sidans `<h1>`.** Över `md:` är hjälten sidans huvud, och
 * översikten döljer sitt eget huvud där (se resources/js/pages/Containers/
 * Overview.vue) — rubriken ska finnas på varje bredd, och den här raden är den
 * över brytpunkten.
 *
 * Ingen sträng i JavaScript ([[ADR-0013 Språk och i18n]]): brickornas etiketter
 * kommer ur `t()` med nycklarna `container.overview.items` och
 * `container.overview.todos` — samma nycklar översiktens egna brickor bär — och
 * artens värde skrivs ORDAGRANT, för fältet är fritt
 * ([[ADR-0036 Containerns art]]) och ingen nyckel byggs ur det.
 */
const props = defineProps({
    /*
     * `large` på översikten, `compact` på de andra flikarna — ADR-0050 § 2.
     * Formen styr hjältens HÖJD (`min-h-56` / `min-h-32`) och talen: bara den
     * höga hjälten bär dem. Den här komponenten ritas bara när skalet har ett
     * värde att ge den; frånvaron av en hjälte är skalets beslut och inte
     * hjältens.
     */
    hero: { type: String, required: true },
    /* Containern, ur App\Http\Resources\ContainerResource. */
    container: { type: Object, required: true },
    /*
     * `{ update }` — samma flagga som inställningssidan ritar sitt formulär ur.
     * Den styr *Redigera container* och menyn (ADR-0050 § 3), och rutten prövar
     * samma grind på nytt.
     */
    can: { type: Object, default: null },
});

const page = usePage();

const counts = computed(() => page.props.containerCounts ?? null);

const canUpdate = computed(() => props.can?.update === true);

/*
 * Menyns `<details>`: webbläsaren äger öppet-tillståndet, men raden *Byt bild*
 * öppnar ett ark ovanpå. Stäng menyn innan arket öppnas, annars står den kvar
 * uppfälld när arket stängs.
 */
const menu = ref(null);

function openCover(open) {
    if (menu.value) {
        menu.value.open = false;
    }

    open();
}

const { t } = useTranslations();
</script>

<template>
    <!--
        `overflow-hidden` sitter på bild- och toningslagren och inte på
        sektionen: menyn i hörnet får inte klippas av hjältens kant, men
        bilden ska fortfarande ha hjältens rundning. Sektionens egen bakgrund
        klipps av `rounded-card` ändå.
    -->
    <section
        class="relative rounded-card bg-shell"
        :class="hero === 'large' ? 'min-h-56' : 'min-h-32'"
    >
        <!--
            Bilden, som ett lager under texten. `aria-hidden` för att ytan är
            pynt: containerns namn står intill, och `ContainerCover` bär redan
            `alt=""` av samma skäl (issue 68a § Beslut 8).
        -->
        <span class="absolute inset-0 overflow-hidden rounded-card" aria-hidden="true">
            <ContainerCover :cover="container.cover" variant="medium" />
        </span>

        <!--
            Toningen ur [[ADR-0057 Ikoner och typsnitt]] § 6: tät till vänster
            där texten står, genomskinlig till höger där bilden syns. Den
            håller full täthet en bit in i stället för att jämnt mörklägga
            hela ytan.
        -->
        <span
            class="absolute inset-0 rounded-card bg-linear-to-r from-shell from-40% via-shell/90 to-transparent"
            aria-hidden="true"
        ></span>

        <div class="relative flex flex-col gap-4 p-6 text-ink-on-accent">
            <div class="flex items-start gap-4">
                <!--
                    Textspalten är kapad: namn, art och beskrivning ska stå på
                    den täta delen av toningen och aldrig växa in i den glesa.
                -->
                <div class="min-w-0 max-w-lg flex-1">
                    <!-- Arten är ett fritt textfält och skrivs ordagrant. -->
                    <p v-if="container.kind" class="text-meta">{{ container.kind }}</p>

                    <!--
                        Namnet är sidans `<h1>`: över `md:` är hjälten
                        containerns huvud, och de sidor som ritar den döljer
                        sitt eget.
                    -->
                    <h1 class="text-heading font-semibold">{{ container.name }}</h1>

                    <!--
                        Hela beskrivningen, radbrytningarna bevarade och inget
                        avkortande: `whitespace-pre-line` är samma form som
                        översiktens huvud bar (issue 88).
                    -->
                    <p
                        v-if="container.description"
                        class="mt-1 whitespace-pre-line text-body"
                    >
                        {{ container.description }}
                    </p>
                </div>

                <!--
                    Hjältens två handlingar står på glaset (ADR-0057 § 6), med
                    samma kant och fokusring som de andra ytorna på bilden.
                    Båda kräver `can.update`: arket menyn öppnar prövar samma
                    grind som rutten.
                -->
                <div v-if="canUpdate" class="flex shrink-0 items-center gap-2">
                    <Link
                        :href="`/containers/${container.ulid}/edit`"
                        class="inline-flex min-h-11 items-center gap-2 rounded-control border border-glass-border bg-glass px-4 font-medium text-ink-on-accent outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    >
                        <UiIcon name="edit" :size="20" />
                        {{ t('container.hero.edit') }}
                    </Link>

                    <ContainerCoverSheet :container="container" v-slot="{ open }">
                        <details ref="menu" class="relative">
                            <summary
                                :aria-label="t('nav.menu')"
                                class="inline-flex min-h-11 min-w-11 cursor-pointer list-none items-center justify-center rounded-control border border-glass-border bg-glass text-ink-on-accent outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                            >
                                <UiIcon name="more" :size="20" />
                            </summary>

                            <div
                                class="absolute right-0 z-10 mt-2 w-48 rounded-card border border-border bg-surface p-1 shadow-card"
                            >
                                <button
                                    type="button"
                                    class="flex min-h-11 w-full items-center gap-2 rounded-control px-3 text-left text-body text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                                    @click="openCover(open)"
                                >
                                    <UiIcon name="image" :size="16" />
                                    {{ t('container.cover.edit') }}
                                </button>
                            </div>
                        </details>
                    </ContainerCoverSheet>
                </div>
            </div>

            <!--
                Talen, bara i den höga hjälten (ADR-0050 § 2) och bara när den
                delade proppen finns. Varje taltuta är en länk — samma grepp
                som dashboardens brickor (issue 654): *Items* till itemlistan,
                *Open tasks* till uppgiftsfliken.
            -->
            <div v-if="counts && hero === 'large'" class="mt-2 flex flex-wrap gap-4">
                <Link
                    :href="`/containers/${container.ulid}/items`"
                    class="rounded-card outline-none hover:shadow-sm focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    <UiStat
                        :value="counts.items"
                        :label="t('container.overview.items')"
                        icon="item"
                        tone="on-image"
                    />
                </Link>

                <Link
                    :href="`/containers/${container.ulid}/tasks`"
                    class="rounded-card outline-none hover:shadow-sm focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    <UiStat
                        :value="counts.todos"
                        :label="t('container.overview.todos')"
                        icon="task"
                        tone="on-image"
                    />
                </Link>
            </div>
        </div>
    </section>
</template>
