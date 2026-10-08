<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import UiCheckbox from './UiCheckbox.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Filterkolumnen på den globala dokumentlistan, se M28 · issue 255 · Beslut 3
 * och resources/js/pages/Documents/Index.vue.
 *
 * **Egen komponent och inte DocumentFilterColumn.** Flikens kolumn filtrerar
 * en containers filer på typ, item, uppladdare och datum; den här ytan listar
 * HELA kontot och har två grupper — containern och typen — eftersom det är de
 * två frågorna adressen kan svara på (`container[]` och `kind[]`,
 * App\Http\Controllers\DocumentController § filter()). Att bygga om flikens
 * kolumn hade gett den grupper den aldrig visar och en rutt den inte känner.
 *
 * **Samma form som DocumentFilterColumn, av samma skäl.** Kryssrutorna
 * skickar listor (`container[]=…&kind[]=…`), en ändring görs direkt utan
 * *Apply*-knapp, och servern är den som filtrerar — komponenten sållar
 * ingenting. `sort` följer med i varje anrop ur `filter`-proppen, så en
 * filtrering inte tyst byter ordning.
 *
 * **Inboxen står först och bara när den finns.** Servern skickar den som
 * `{ulid: 'inbox', count}` — ordet och ingen ULID, för inboxens container är
 * dold ([[ADR-0054 Inboxen]] § 1) — och bara när den har filer. Raden ritas
 * därför som vilken container som helst, med `documents.inbox` som namn.
 *
 * **Adressen stavas ut.** Komponenten känner inte sin egen rutt — samma skäl
 * som i DocumentFilterColumn och ItemFilterBar — och bygger därför
 * `/documents?…` själv.
 *
 * **Ett filter utan alternativ ritas inte.** Har användaren inga containrar
 * (och ingen inbox med filer) finns ingen containerkolumn att välja i; typen
 * ritas alltid, för dess tre värden är fasta och en träffbild utan bilder är
 * ett giltigt svar.
 *
 * **Ingen sträng i JavaScript** ([[ADR-0013 Språk och i18n]]): varje text
 * kommer ur `t()` under `documents.*`. Typens tre ord är
 * `item.attachment.kind.*` — samma ord som raden bär.
 *
 * Fälten speglas ur `filter`-proppen med `watch`, inte bara vid montering: en
 * bakåtknapp eller en omladdning ska visa det filter servern tillämpade och
 * inte det användaren kryssade sist.
 */
const props = defineProps({
    /*
     * Filtret så som servern tillämpade det: `{container, kind, sort}` —
     * listorna är `[]` när gruppen inte filtrerar.
     */
    filter: { type: Object, required: true },
    /*
     * Containerväljaren med antalen: `{ulid, name?, count}`, inboxen först som
     * `{ulid: 'inbox', count}` när den har filer (DocumentController).
     */
    containers: { type: Array, required: true },
    /* Typantalen: `{image, document, other}`, samma tre nycklar som raden. */
    kinds: { type: Object, required: true },
});

const { t } = useTranslations();

/*
 * Typerna, i `attachment.kind`s egen ordning. Listan är fast och inte en
 * propp: `kind` kan bara anta de tre värdena, och en grupp byggd ur raderna
 * hade tappat en typ ingen laddat upp ännu — samma skäl som i
 * DocumentFilterColumn. Namnet skiljer den från `kinds`-proppen, som bär
 * antalen.
 */
const kindValues = ['image', 'document', 'other'];

/* Inboxens värde i `container[]` — ordet och inte en ULID (Beslut 2). */
const INBOX = 'inbox';

const container = ref([]);
const kind = ref([]);

/* Vänteläget för hela kolumnen: varje kryss och *Rensa alla* gör samma sorts
   anrop — en GET — så en enda flagga räcker. Stängs medan svaret är på väg. */
const pending = ref(false);

watch(
    () => props.filter,
    (filter) => {
        container.value = [...filter.container];
        kind.value = [...filter.kind];
    },
    { immediate: true, deep: true },
);

/* Containerns namn, eller *Inbox* — servern skickar ingen ULID för inboxen. */
const containerName = (option) => (option.ulid === INBOX
    ? t('documents.inbox')
    : option.name);

/*
 * Skickar filtret som querysträng. `sort` kommer ur `props.filter` och följer
 * med utan att nämnas, och ett nytt filter börjar på sida ett — sidnumret
 * finns inte i strängen. `preserveScroll` för att listan inte ska hoppa till
 * toppen, och `preserveState` för att rutorna ska stå kvar.
 */
function apply(overrides) {
    const next = { ...props.filter, ...overrides };
    const params = new URLSearchParams();

    for (const value of next.container ?? []) {
        params.append('container[]', value);
    }

    for (const value of next.kind ?? []) {
        params.append('kind[]', value);
    }

    if (next.sort !== null && next.sort !== undefined && next.sort !== 'newest') {
        params.set('sort', next.sort);
    }

    const query = params.toString();

    router.get(query === '' ? '/documents' : `/documents?${query}`, {}, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/*
 * Rutorna bär sina grupper som arrayer. Den lokala raden sätts FÖRST och
 * anropet efter: rutan svarar direkt i handen, och `watch` rättar den mot
 * serverns svar när det kommer — samma ordning som i DocumentFilterColumn.
 */
const containerModel = computed({
    get: () => container.value,
    set: (values) => {
        container.value = values;
        apply({ container: values });
    },
});

const kindModel = computed({
    get: () => kind.value,
    set: (values) => {
        kind.value = values;
        apply({ kind: values });
    },
});

/* *Rensa alla* tömmer båda grupperna och BEHÅLLER `sort`: ordningen är inget
   filter, och en rensning ska inte byta den. */
function clearAll() {
    container.value = [];
    kind.value = [];

    apply({ container: [], kind: [] });
}
</script>

<template>
    <aside class="rounded-card border border-border bg-surface p-4">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-body font-semibold text-ink">
                {{ t('documents.filter_heading') }}
            </h2>

            <button
                type="button"
                :disabled="pending"
                class="inline-flex min-h-11 items-center text-meta text-accent underline disabled:opacity-50"
                @click="clearAll"
            >
                {{ pending ? t('common.pending.default') : t('documents.filter_clear_all') }}
            </button>
        </div>

        <!--
            Containern (Beslut 3): varje container användaren når, med antalet
            filer hon ser i den. Inboxen står först och bär sitt eget ord —
            servern skickar den bara när den har filer.
        -->
        <details v-if="containers.length > 0" open class="mt-2">
            <summary class="cursor-pointer text-meta font-semibold text-ink">
                {{ t('documents.container') }}
            </summary>

            <ul class="mt-1 flex flex-col">
                <li v-for="option in containers" :key="option.ulid">
                    <UiCheckbox
                        :id="`document-scope-container-${option.ulid}`"
                        v-model="containerModel"
                        :value="option.ulid"
                        :disabled="pending"
                    >
                        {{ containerName(option) }}
                        <span class="ml-auto text-meta text-ink-subtle">{{ option.count }}</span>
                    </UiCheckbox>
                </li>
            </ul>
        </details>

        <!-- Typen (Beslut 3): de tre typerna, var och en med sitt antal. -->
        <details open class="mt-2">
            <summary class="cursor-pointer text-meta font-semibold text-ink">
                {{ t('documents.type') }}
            </summary>

            <ul class="mt-1 flex flex-col">
                <li v-for="value in kindValues" :key="value">
                    <UiCheckbox
                        :id="`document-scope-kind-${value}`"
                        v-model="kindModel"
                        :value="value"
                        :disabled="pending"
                    >
                        {{ t(`item.attachment.kind.${value}`) }}
                        <span class="ml-auto text-meta text-ink-subtle">{{ kinds[value] }}</span>
                    </UiCheckbox>
                </li>
            </ul>
        </details>
    </aside>
</template>
