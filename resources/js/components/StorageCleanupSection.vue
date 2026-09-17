<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { formatByteSize } from './attachmentPresentation.js';
import { exceedsLimit, remainingBytes, selectedBytes, MAX_SELECTION } from './storageSelection.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Urvalslistan på lagringsytan — nedgraderingens steg 2, se issue 66b § Beslut
 * 2–7, [[Planer och kvoter]] § Nedgradering och [[ADR-0009 Kvoter och
 * livscykel]].
 *
 * **Listan är serverns och ordningen är serverns** (Beslut 2). Bilagorna
 * kommer ur sidans props, redan sorterade på storlek fallande av
 * App\Http\Controllers\Settings\StorageController, och den här filen lägger
 * ingenting ovanpå: ingen egen `fetch`, ingen omsortering, ingen paginering.
 * Varje rad bär filnamn, storlek i läsbar form, container och item — utan
 * sammanhanget går valet inte att göra, och "de fyrtio semesterbilderna" sitter
 * på samma item.
 *
 * **Urvalet är lokalt tillstånd bredvid serverns lista** (Beslut 4), samma
 * mönster som uppladdningskön i ItemAttachmentSection.vue (60b): kryssen är
 * tillstånd i vyn och ingenting annat, och de försvinner när sidan lämnas.
 * Summan räknas medan man väljer (storageSelection.js) och är en
 * FÖRHANDSVISNING — förbrukningen efter en genomförd rensning kommer ur
 * serverns svar, aldrig ur klientens subtraktion.
 *
 * **Taket är 100 per anrop och vyn respekterar det** (Beslut 5). Fler valda än
 * så stänger av knappen och säger det innan något skickas; servern nekar ändå
 * hela begäran, och en halv rensning finns inte.
 *
 * **Bekräftelsen är webbläsarens egen dialog med serverns mening ur `lang/`**
 * (Beslut 6), samma mönster som detaljvyns radering och uppladdningsköns:
 * ingen modal komponent och ingen sträng i JavaScript. Texten säger antalet
 * filer och det frigjorda utrymmet, pekar på papperskorgen och de 30 dagarna —
 * och säger aldrig "raderas permanent", för bilagorna mjukraderas och kan
 * återställas ur containerns papperskorg (62a).
 *
 * **Bilagor vars item eller container ligger i papperskorgen syns och är
 * markerade** (Beslut 3): de räknas fortfarande mot kontot och ska gå att
 * rensa bort. Utan markeringen ser summan ut att vara fel.
 *
 * **Ingen sträng står i den här filen** (Beslut 9): varje mening kommer ur
 * `t()` med en nyckel under `storage.*`.
 *
 * `router.delete` och inte en `<Link method="delete">`: bekräftelsen måste
 * kunna AVBRYTA anropet, och kontot står i ruttens sökväg — samma skäl som
 * App\Http\Controllers\Settings\StorageController anger.
 */
const props = defineProps({
    /* Det valda kontots ULID — kontot i DELETE-ruttens sökväg. */
    accountUlid: { type: String, required: true },

    /*
     * Kontots levande bilagor, `{ulid, filename, byte_size, kind, container,
     * item, created_at, inTrash}`, sorterade på storlek fallande av servern.
     */
    attachments: { type: Array, required: true },

    /* Kontots förbrukning just nu, ur `usage_counter` — serverns tal. */
    usedBytes: { type: Number, required: true },
});

const { t } = useTranslations();
const page = usePage();

const selected = ref([]);

/* Vänteläget på rensningen: en DELETE som är på väg ska säga det. */
const pending = ref(false);

/*
 * Raderna: storleken formaterad och `formatByteSize` — samma formatering som
 * serverns Number::fileSize(), samma modul som bilagelistan använder
 * (attachmentPresentation.js). Raden visar container och item med en nyckel och
 * inte med ett skiljetecken i mallen, så ordningen går att översätta.
 */
const rows = computed(() => props.attachments.map((attachment) => ({
    ...attachment,
    size: formatByteSize(attachment.byte_size),
})));

const freedBytes = computed(() => selectedBytes(props.attachments, selected.value));

const remaining = computed(() => remainingBytes(props.usedBytes, freedBytes.value));

const tooMany = computed(() => exceedsLimit(selected.value));

/* Serverns fältfel. `attachments` för hela listan (taket), `attachments.N`
 * för en enskild ULID — båda hör till det här formuläret och ingen annan. */
const errors = computed(() => {
    const alla = page.props.errors ?? {};

    return Object.keys(alla)
        .filter((nyckel) => nyckel === 'attachments' || nyckel.startsWith('attachments.'))
        .map((nyckel) => alla[nyckel]);
});

/*
 * Rensningen. Bekräftelsen formuleras med det valda antalet och det frigjorda
 * utrymmet — två nycklar och inte en, för `t()` har ingen pluralisering
 * (issue 52 § Beslut 4).
 *
 * `preserveScroll` behåller platsen i listan när svaret kommer tillbaka, och
 * `onSuccess` tömmer urvalet: sidan ritas om ur serverns svar, och kryssen
 * tillhör den listan.
 */
function submit() {
    if (selected.value.length === 0 || tooMany.value) {
        return;
    }

    const freed = formatByteSize(freedBytes.value);

    const message = selected.value.length === 1
        ? t('storage.confirm.one', { freed })
        : t('storage.confirm.many', { count: selected.value.length, freed });

    if (! window.confirm(message)) {
        return;
    }

    router.delete(`/settings/storage/${props.accountUlid}`, {
        data: { attachments: selected.value },
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
        onSuccess: () => {
            selected.value = [];
        },
    });
}
</script>

<template>
    <section class="mt-8">
        <h2 class="text-lg font-semibold">{{ t('storage.list_heading') }}</h2>
        <p class="mt-2 text-sm text-slate-700">{{ t('storage.list_intro') }}</p>

        <!-- En tom yta säger att det inte finns någon rad att välja — den
             hittar inte på en. -->
        <p v-if="rows.length === 0" class="mt-2 text-sm text-slate-600">
            {{ t('storage.empty') }}
        </p>

        <form v-else class="mt-4 flex flex-col gap-4" @submit.prevent="submit">
            <ul class="flex flex-col gap-2">
                <li
                    v-for="row in rows"
                    :key="row.ulid"
                    class="rounded border border-slate-300 bg-white px-4 py-2"
                >
                    <label :for="`storage-${row.ulid}`" class="flex min-h-11 items-start gap-3">
                        <input
                            :id="`storage-${row.ulid}`"
                            v-model="selected"
                            type="checkbox"
                            :value="row.ulid"
                            class="mt-1 h-4 w-4 shrink-0"
                        >

                        <span class="flex min-w-0 flex-col gap-1">
                            <span class="flex flex-wrap items-center gap-3">
                                <span class="font-medium text-slate-900">{{ row.filename }}</span>
                                <span v-if="row.size" class="text-sm text-slate-600">{{ row.size }}</span>
                                <span class="text-sm text-slate-600">
                                    {{ t('storage.row.location', { container: row.container.name, item: row.item.name }) }}
                                </span>
                            </span>

                            <!-- Bilagan räknas fortfarande mot kontot när itemet
                                 eller containern ligger i papperskorgen (Beslut 3),
                                 och raden ska säga det — annars ser summan ut
                                 att vara fel. -->
                            <span v-if="row.inTrash" class="text-sm text-amber-800">
                                {{ t('storage.row.trashed') }}
                            </span>
                        </span>
                    </label>
                </li>
            </ul>

            <!-- Urvalets förhandsvisning (Beslut 4): vad kryssen frigör och vad
                 som återstår. Räknad i vyn ur `byte_size` på de valda raderna,
                 för det är ett urval och inte förbrukningen. -->
            <p v-if="selected.length > 0" class="text-sm text-slate-900">
                {{ selected.length === 1
                    ? t('storage.preview.one', {
                        freed: formatByteSize(freedBytes),
                        remaining: formatByteSize(remaining),
                    })
                    : t('storage.preview.many', {
                        count: selected.length,
                        freed: formatByteSize(freedBytes),
                        remaining: formatByteSize(remaining),
                    }) }}
            </p>

            <!-- Taket (Beslut 5): vyn säger det innan något skickas, och
                 servern nekar hela begäran om den ändå skickas. -->
            <p v-if="tooMany" role="alert" class="text-sm text-red-700">
                {{ t('storage.limit_exceeded', { max: MAX_SELECTION, count: selected.length }) }}
            </p>

            <!-- Serverns fältfel ur valideringen: en ULID som inte längre
                 tillhör kontot, eller fler än taket. Felet hör till urvalet,
                 inte till en enskild rad. -->
            <p
                v-for="(error, index) in errors"
                :key="index"
                role="alert"
                class="text-sm text-red-700"
            >
                {{ error }}
            </p>

            <button
                type="submit"
                :disabled="pending || selected.length === 0 || tooMany"
                class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ pending ? t('common.pending.default') : t('storage.submit') }}
            </button>
        </form>
    </section>
</template>
