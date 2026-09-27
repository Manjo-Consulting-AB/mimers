<script setup>
import { nextTick, ref, watch } from 'vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Arket, se [[ADR-0048 Mobilen och plusknappen]] § 2 och
 * [[M23 Mobilen och kartan]] § 152.
 *
 * **Ett ark som glider upp nerifrån på mobil och en meny under knappen på
 * desktop.** Den ena ytan är den andra ytans form: samma panel, samma rubrik,
 * samma innehåll, och bara fästet skiljer. Under `md:` ligger panelen i
 * botten av skärmen (`inset-x-0 bottom-0`, rundade hörn uppåt); över `md:`
 * ligger den under elementet som öppnade den, i den storlek en meny har.
 *
 * **Fästet över `md:` räknas ur öppnarens rektangel och inte ur en klasstext.**
 * Panelens `top` och `right` är CSS-variabler som sätts när arket öppnas, och
 * klasserna läser dem bara i sin `md:`-form — under brytpunkten vinner
 * bottenfästet, och variablerna rör ingenting. En klass som räknade fram
 * sidhuvudets högerkant hade varit samma tal skrivet i två filer: flyttar
 * knappen i sidhuvudet en rad pekar menyn på fel ställe, utan att något prov
 * faller.
 *
 * **Fokusfällan är webbläsarens** (issue 68b § Beslut 2): `showModal()` håller
 * fokus i arket medan det är öppet och Esc fyrar `close`, precis som i
 * MobileMenu och bildvisaren i ItemAttachmentSection. Ingen egen
 * tabbhanterare och inget `aria-modal` — den fällan är den som fastnar.
 *
 * **Fokus lämnas tillbaka uttryckligen.** `<dialog>` lämnar tillbaka till det
 * element som hade fokus när den öppnades, och det elementet känner bara den
 * som öppnade arket: `trigger` är det, och samma prop bär fästet.
 *
 * **Stängningen har tre vägar, och alla går genom `close()`:** knappen i
 * rubrikraden, Esc (webbläsarens `close`-händelse) och ett tryck utanför.
 * Dialogen täcker hela skärmen, så det som ligger utanför panelen är ett
 * element och inte en bakgrund utan mål — samma `<button>` som i MobileMenu.
 * En `<div>` med `@click` hade varit en yta ingen når med tabb (issue 68a
 * § Beslut 1).
 *
 * **Bakgrunden är mörk under `md:` och genomskinlig över.** På en telefon är
 * arket en modal och ska säga det; på en desktop är samma yta en meny, och en
 * nedtonad sida bakom en meny hade läst som en modal man måste svara på.
 * Trycket utanför stänger i båda fallen.
 *
 * Rubriken är en prop och ingen slot: arket bär ett namn, och en slot hade
 * låtit två anropare ge samma yta två olika rubriker. Texten kommer ur
 * anroparens `t()` — arket är en `Ui*.vue` och bär ingen egen mening.
 */
const props = defineProps({
    /* Sant medan arket är öppet. Anroparen äger tillståndet. */
    open: { type: Boolean, default: false },
    /* Arkets rubrik, färdigöversatt av anroparen. */
    heading: { type: String, required: true },
    /* Elementet som öppnade arket: fästet över `md:` och fokus vid stängning. */
    trigger: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const { t } = useTranslations();

const sheetElement = ref(null);

/*
 * Panelens fäste över `md:`, som `--sheet-top` och `--sheet-right`. Tomma när
 * ingen öppnare finns — då faller panelen tillbaka på sin statiska plats, och
 * mobilens bottenfäste gäller oavsett.
 */
const anchor = ref({ top: '', right: '' });

watch(
    () => props.open,
    async (open) => {
        if (open) {
            const rect = props.trigger?.getBoundingClientRect?.();

            anchor.value = rect
                ? {
                    top: `${Math.round(rect.bottom + 8)}px`,
                    right: `${Math.round(window.innerWidth - rect.right)}px`,
                }
                : { top: '', right: '' };

            // Innehållet finns i DOM:en redan, men arket ska vara öppet innan
            // fokus flyttas in i det.
            await nextTick();

            sheetElement.value?.showModal();

            return;
        }

        sheetElement.value?.close();
    },
);

function close() {
    sheetElement.value?.close();
}

/*
 * Stängningen, oavsett väg in: Esc fyrar `close` precis som knapparna gör.
 * Fokus tillbaka till öppnaren — utan den står fokus kvar i ett ark som inte
 * finns.
 */
function onClosed() {
    emit('close');

    props.trigger?.focus();
}
</script>

<template>
    <dialog
        ref="sheetElement"
        :aria-label="heading"
        class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-ink/50 md:backdrop:bg-transparent"
        :style="{ '--sheet-top': anchor.top, '--sheet-right': anchor.right }"
        @close="onClosed"
    >
        <div class="flex h-full w-full flex-col justify-end">
            <!--
                Ytan utanför panelen. Dialogen täcker hela skärmen, så trycket
                utanför landar här och inte i en bakgrund utan mål.
            -->
            <button
                type="button"
                class="absolute inset-0 min-h-11"
                :aria-label="t('create.close')"
                @click="close"
            ></button>

            <div
                class="relative flex max-h-full flex-col gap-2 overflow-y-auto rounded-t-card bg-surface p-4 md:fixed md:top-[var(--sheet-top)] md:right-[var(--sheet-right)] md:max-h-[70vh] md:w-72 md:rounded-card md:border md:border-border"
            >
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-title font-semibold text-ink">{{ heading }}</h2>

                    <!--
                        `autofocus` är den fälla som styr var fokus landar när
                        `showModal()` öppnar arket. Utan den tar webbläsaren
                        första tabbbara element — och det är tryckytan utanför
                        panelen, alltså en stängknapp i full skärmstorlek som
                        ingen ser. Härifrån når tangentbordet innehållet med ett
                        Tab.
                    -->
                    <button
                        type="button"
                        autofocus
                        class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control border border-border text-ink-muted"
                        :aria-label="t('create.close')"
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

                <slot />
            </div>
        </div>
    </dialog>
</template>
