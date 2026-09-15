<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { formatByteSize } from './attachmentPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Bilagesektionen på itemets detaljvy — se issue 60 § Beslut 2, 4, 5, 7, 8
 * och 9.
 *
 * **Listan kommer med detaljvyns props, aldrig ur ett eget anrop** (Beslut 2).
 * Servern har redan sorterat den nyast först, exakt som `/api` gör, och den
 * här filen lägger ingenting ovanpå: ingen egen `fetch`, ingen andra väg till
 * samma lista, ingen omsortering. Varje rad är en bilaga med filnamn, typ,
 * storlek och en nedladdningslänk.
 *
 * **Nedladdningslänken är `/files/{ulid}` och ingenting annat** (Beslut 8).
 * Rutten från issue 19a levererar alltid `Content-Disposition: attachment`, så
 * en bild öppnas inte i vyn — inline-visning kräver ett eget origin och är
 * issue 61. Ingen miniatyr och ingen förhandsvisning här heller.
 *
 * **Två ytor, två flaggor** (Beslut 3). `can.create` ritar
 * uppladdningsformuläret, `can.delete` ritar ta bort-knappen per rad. Båda är
 * presentation: grinden i App\Http\Controllers\AttachmentController prövas på
 * nytt i varje skrivning, så en `write`-mottagare som postar en DELETE får 403
 * även om knappen aldrig ritades för henne.
 *
 * **Kontot som betalar är ett fält, med pärmens ägarkonto som förval** (Beslut
 * 4). Kontolistan kommer ur den delade propen `auth.accounts` — en egen fråga
 * för samma lista är en fråga för mycket, samma linje som ItemForm. Kvoten
 * räknas på det uppladdande kontot och inte på pärmens ägare ([[Filer och
 * lagring]] § attachment), så raden ovanför säger vilket konto som belastas
 * INNAN filen väljs. Är hon medlem i exakt ett konto ritas ingen väljare —
 * ett val mellan ett alternativ är ingen fråga — bara kontots namn.
 *
 * **Kvot- och storleksfelet är ett fältfel på `file`** (Beslut 5).
 * App\Support\Plan\Entitlements kastar en ApiException som kontrollern gör till
 * en mening med gränsen och filens storlek i läsbar form; den hamnar här,
 * bredvid filväljaren, och aldrig som en rå JSON-kropp mitt i sidan.
 *
 * **Ingen svensk sträng i den här filen** (Beslut 9): varje ord kommer ur
 * `lang/{locale}/ui.php` genom `t()`, också bekräftelsedialogen — den säger
 * papperskorgen och de 30 dagarna, för raderingen är mjuk (Beslut 7).
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /* Bilagorna ur detaljvyns props, redan sorterade och formaterade. */
    attachments: { type: Array, required: true },
    /* Pärmens ägarkonto — förvalet när användaren är medlem i det. */
    containerAccount: { type: String, default: '' },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const page = usePage();

const fileInput = ref(null);

const locale = computed(() => page.props.locale);

/*
 * Kontolistan ur den delade propen `auth.accounts`, samma väg som ItemForm
 * tar (Beslut 4). Förvalet är pärmens ägarkonto när användaren är medlem i
 * det, annars hennes första konto.
 */
const accounts = computed(() => page.props.auth?.accounts ?? []);

const account = computed(() => {
    const owner = accounts.value.find((candidate) => candidate.ulid === props.containerAccount);

    return owner?.ulid ?? accounts.value[0]?.ulid ?? '';
});

/* Ett enda konto ritas som en rad text i stället för en väljare (Beslut 4). */
const singleAccount = computed(() => (accounts.value.length === 1 ? accounts.value[0] : null));

/* Raderna: storleken formaterad och `kind` översatt till ett ord. */
const rows = computed(() => props.attachments.map((attachment) => ({
    ...attachment,
    size: formatByteSize(attachment.byte_size, locale.value),
    kindLabel: t(`item.attachment.kind.${attachment.kind}`),
})));

const form = useForm({
    file: null,
    account: account.value,
});

/*
 * Uppladdningen. Inertia bygger `FormData` själv när ett fält är en `File`
 * (Beslut: inga nya paket, inget uppladdningsbibliotek) — därför ingen
 * `Content-Type` att sätta och ingen egen serialisering.
 *
 * Filväljaren nollställs EFTER en lyckad uppladdning: `form.reset()` sätter
 * fältets värde tillbaka till null, men DOM-elementet behåller filnamnet, och
 * en rad som ser ut att vara vald men inte skickas igen är en fälla för nästa
 * uppladdning.
 */
function submit() {
    form.post(`/containers/${props.containerUlid}/items/${props.itemUlid}/attachments`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
        onError: focusFirstError,
    });
}

/*
 * Raderingen. Bekräftelsen är webbläsarens egen dialog med serverns mening ur
 * `lang/` — ingen modal komponent och ingen sträng i JavaScript, samma mönster
 * som detaljvyns radering och upp-knytningen i ItemLinkSection.
 *
 * `router.delete` och inte en <Link method="delete">: bekräftelsen måste kunna
 * AVBRYTA navigeringen.
 */
function destroy(attachment) {
    if (! window.confirm(t('item.attachment.destroy_confirm'))) {
        return;
    }

    router.delete(`/containers/${props.containerUlid}/items/${props.itemUlid}/attachments/${attachment.ulid}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <section class="mt-10">
        <h2 class="text-lg font-semibold">{{ t('item.attachment.heading') }}</h2>

        <!-- En tom pärm och ett item utan bilagor säger samma sak: det finns
             ingen rad att visa, och vyn hittar inte på en. -->
        <p v-if="rows.length === 0" class="mt-2 text-sm text-slate-600">
            {{ t('item.attachment.empty') }}
        </p>

        <ul v-else class="mt-2 flex flex-col gap-2">
            <li
                v-for="attachment in rows"
                :key="attachment.ulid"
                class="flex flex-wrap items-center gap-3 rounded border border-slate-300 bg-white px-4 py-2"
            >
                <span class="font-medium text-slate-900">{{ attachment.filename }}</span>
                <span class="text-sm text-slate-600">{{ attachment.kindLabel }}</span>
                <span v-if="attachment.size" class="text-sm text-slate-600">{{ attachment.size }}</span>

                <!-- Nedladdningen går alltid genom appen (issue 19a): rutten
                     kontrollerar `view` på itemet före leveransen. -->
                <a
                    :href="`/files/${attachment.ulid}`"
                    class="font-medium text-blue-700 hover:underline"
                >
                    {{ t('item.attachment.download') }}
                </a>

                <button
                    v-if="can.delete"
                    type="button"
                    class="text-sm text-red-700 hover:underline"
                    @click="destroy(attachment)"
                >
                    {{ t('item.attachment.destroy') }}
                </button>
            </li>
        </ul>

        <template v-if="can.create">
            <h3 class="mt-8 text-base font-semibold">{{ t('item.attachment.upload_heading') }}</h3>

            <!-- Vilket konto som betalar står före filen, inte efteråt: kvoten
                 räknas på det uppladdande kontot (Beslut 4). -->
            <p class="mt-1 text-sm text-slate-600">{{ t('item.attachment.billing_note') }}</p>

            <form class="mt-4 flex max-w-lg flex-col gap-4" @submit.prevent="submit">
                <!-- Ett enda konto: värdet är förvalt och visas som text. -->
                <div v-if="singleAccount" class="flex flex-col gap-1">
                    <p class="text-sm font-medium text-slate-800">{{ t('item.attachment.account') }}</p>
                    <p>{{ singleAccount.name }}</p>
                </div>

                <FormField
                    v-else
                    v-slot="{ describedBy }"
                    :label="t('item.attachment.account')"
                    id="account"
                    :error="form.errors.account"
                >
                    <select
                        id="account"
                        v-model="form.account"
                        :aria-describedby="describedBy"
                        name="account"
                        required
                        class="self-start rounded border border-slate-300 bg-white px-3 py-2"
                    >
                        <option v-for="candidate in accounts" :key="candidate.ulid" :value="candidate.ulid">
                            {{ candidate.name }}
                        </option>
                    </select>
                </FormField>

                <!-- Filen. Felet på `file` är kvot- eller storleksfelet ur
                     App\Support\Plan\Entitlements, formulerat av
                     App\Support\Frontend\ApiErrorTranslator med gräns och
                     värde i läsbar form (Beslut 5 och 6). -->
                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.attachment.file')"
                    id="file"
                    :error="form.errors.file"
                >
                    <input
                        id="file"
                        ref="fileInput"
                        :aria-describedby="describedBy"
                        type="file"
                        name="file"
                        required
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                        @change="form.file = $event.target.files[0]"
                    >
                </FormField>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="self-start rounded bg-blue-700 px-4 py-2 font-medium text-white disabled:opacity-50"
                >
                    {{ t('item.attachment.submit') }}
                </button>
            </form>
        </template>
    </section>
</template>
