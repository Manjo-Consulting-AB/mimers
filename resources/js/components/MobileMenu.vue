<script setup>
import { nextTick, ref, watch } from 'vue';
import RecentVisitList from './RecentVisitList.vue';
import ShellContainerList from './ShellContainerList.vue';
import ShellSections from './ShellSections.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Sidomenyn bakom *Meny*, se [[ADR-0048 Mobilen och plusknappen]] § 1 och
 * [[M23 Mobilen och kartan]] § 151.
 *
 * **Innehållet är skalets sektioner och ingenting eget.** Raderna ritas av
 * ShellSections, containerlistan av ShellContainerList och de senast besökta
 * av RecentVisitList — samma komponenter som desktopens sidopanel — så menyn
 * och panelen visar samma sektioner i samma ordning ur samma data
 * ([[ADR-0050 Desktopdesignen]] § 1). Favoriterna är samma sektion med samma
 * anrop.
 *
 * **Containerlistan kom med issue 169.** Under `md:` finns ingen sidopanel,
 * och en sektion som fanns i den ena ytan och saknades i den andra vore ett
 * fel ([[ADR-0048 Mobilen och plusknappen]] § 1): menyn är den yta som bär
 * skalets listor på en telefon, precis som den bär *Nyligen besökta* sedan
 * issue 160.
 *
 * **Att öppna menyn hämtar *Nyligen besökta*** (issue 160 · [[ADR-0049
 * Nyligen besökta]] § Beslut). Listan är en optional prop i skalet, och menyn
 * är den yta som bär den under `md:`: `:load="props.open"` gör att frågan
 * ställs när menyn öppnas och aldrig annars. Komponenten ser själv till att
 * den bara ställs en gång, så en stängd och åter öppnad meny inte frågar
 * servern igen om samma lista.
 *
 * **Menyn är webbläsarens `<dialog>`** (issue 68b § Beslut 2): `showModal()`
 * ger fokusfällan, Esc stänger, och fokus lämnas tillbaka till det element som
 * hade det innan menyn öppnades — *Meny*-knappen, som skickar med sig sig
 * själv (MobileTabBar). Ingen egen tabbhanterare och inget `aria-modal`: den
 * fällan är den som fastnar.
 *
 * **Stängningen har tre vägar, och alla går genom `close()`:** knappen i
 * menyn, Esc (webbläsarens `close`-händelse) och tryck utanför. Den sista är
 * en `<button>` över ytan vid sidan av panelen — dialogen täcker hela
 * skärmen, så det som ligger utanför panelen är ett element och inte en
 * bakgrund utan mål. En `<div>` med `@click` hade varit en yta ingen når med
 * tabb (issue 68a § Beslut 1).
 *
 * **Fokus återlämnas uttryckligen.** `<dialog>` gör det av sig själv, men bara
 * till det element som hade fokus när den öppnades — och det vet bara den som
 * öppnade den. Samma form som bildvisaren i ItemAttachmentSection.
 *
 * **Menyn bär ingen `md:hidden`.** Den kunde bära den — menyn är mobilens —
 * men en dialog som är öppen och samtidigt dold är en fokusfälla ingen ser:
 * `showModal()` håller kvar fokus i ett element som inte ritas, och den som
 * drar i fönsterkanten med menyn öppen har tappat tangentbordet. Stängningen
 * sköts i stället av att knappen som öppnar den är `md:hidden`: över `md:`
 * finns ingen väg in, och en stängd dialog ritas inte alls.
 */
const props = defineProps({
    /* Sant medan menyn är öppen. AppLayout äger tillståndet. */
    open: { type: Boolean, default: false },
    /* Elementet som öppnade menyn, så att fokus kan lämnas tillbaka. */
    trigger: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const { t } = useTranslations();

const menuElement = ref(null);

watch(
    () => props.open,
    async (open) => {
        if (open) {
            // Innehållet finns i DOM:en redan, men `autofocus` läses av
            // `showModal()` och dialogen ska vara öppen först.
            await nextTick();

            menuElement.value?.showModal();

            return;
        }

        menuElement.value?.close();
    },
);

function close() {
    menuElement.value?.close();
}

/*
 * Stängningen, oavsett väg in: Esc fyrar `close` precis som knappen gör.
 * Fokus tillbaka till *Meny* — utan den står fokus kvar i en dialog som inte
 * finns.
 */
function onClosed() {
    emit('close');

    props.trigger?.focus();
}
</script>

<template>
    <dialog
        id="sidomenyn"
        ref="menuElement"
        :aria-label="t('nav.menu')"
        class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-slate-900/50"
        @close="onClosed"
    >
        <div class="flex h-full w-full">
            <aside class="flex h-full w-72 max-w-[85%] flex-col gap-2 overflow-y-auto bg-surface p-4">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-lg font-semibold">{{ t('common.brand') }}</p>

                    <button
                        type="button"
                        class="inline-flex min-h-11 min-w-11 items-center justify-center rounded border border-border text-ink-muted"
                        :aria-label="t('nav.menu_close')"
                        @click="close"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="h-5 w-5"
                            aria-hidden="true"
                        >
                            <path d="m6 6 12 12"></path>
                            <path d="m18 6-12 12"></path>
                        </svg>
                    </button>
                </div>

                <ShellSections />

                <!--
                    Containerlistan kom med issue 169. Den är skalets tredje
                    sektion och står i samma ordning i den här ytan som i
                    sidopanelen — sektionerna, containrarna, de senast
                    besökta, favoriterna ([[ADR-0050 Desktopdesignen]] § 1).
                    Är den sann en enda gång ställs frågan, och den hämtas av
                    samma `open` som de senast besökta: menyn är ytan som bär
                    båda under `md:`.
                -->
                <ShellContainerList :load="props.open" />

                <RecentVisitList :load="props.open" />

                <ShellSections part="favorites" />
            </aside>

            <!--
                Ytan utanför panelen. Dialogen täcker hela skärmen, så trycket
                utanför landar här och inte i en bakgrund utan mål.
            -->
            <button
                type="button"
                class="h-full flex-1"
                :aria-label="t('nav.menu_close')"
                @click="close"
            ></button>
        </div>
    </dialog>
</template>
