<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { formatByteSize } from './attachmentPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Bilagesektionen på itemets detaljvy — se issue 60 § Beslut 2, 4, 5, 7, 8
 * och 9, och issue 60b § Beslut 1–8.
 *
 * **Listan kommer med detaljvyns props, aldrig ur ett eget anrop** (60
 * Beslut 2). Servern har redan sorterat den nyast först, exakt som `/api` gör,
 * och den här filen lägger ingenting ovanpå: ingen egen `fetch`, ingen andra
 * väg till samma lista, ingen omsortering. Varje rad är en bilaga med
 * filnamn, typ, storlek och en nedladdningslänk.
 *
 * **Kön är sekventiell — en fil i taget, i vald ordning** (60b Beslut 1).
 * Inertias router kör ett besök i taget och avbryter det föregående när ett
 * nytt startas, så fyra parallella `router.post` vore tre avbrutna
 * uppladdningar och en som gick igenom — utan att någon av dem syntes som
 * avbruten. `next()` startar därför nästa POST först när den föregående
 * svarat, lyckad eller inte. Sekventiellt är också det ärliga svaret mot en
 * telefon med dålig uppkoppling ([[Backlog]] M10 § 68).
 *
 * **Framdriften kommer ur `onProgress` och ingenting annat** (Beslut 2).
 * `e.percentage` ritar framdriften för den fil som är igång; väntande filer
 * visas som väntande och färdiga som färdiga. Ingen animerad stapel som rör
 * sig utan att veta något — en framdrift som ljuger är värre än ingen.
 *
 * **Ett fel på en fil stoppar inte kön** (Beslut 4). Fil 2 av 4 spränger
 * kvoten: raden får serverns mening ur fältfelet på `file` (60 Beslut 5), och
 * fil 3 och 4 laddas upp ändå. Filens fel är en rad i kön; anropets fel —
 * kontot, takgränsen, ett svar vyn inte känner igen — stannar kön, för nästa
 * fil hade fått samma svar.
 *
 * **En avbruten uppladdning lämnar inget halvt tillstånd** (60 § Klart när).
 * Det gäller både raden i listan — som alltid är serverns svar (Beslut 6) —
 * och raden i kön: ett avbrott på länken är varken ett lyckat eller ett nekat
 * anrop, och `onNetworkError` sätter tillbaka filen som väntande med köns egen
 * mening i stället för att låta den stå kvar som "laddar upp" för alltid.
 *
 * **Det tekniska taket är en prop, och kontrollen i vyn är en artighet**
 * (Beslut 5). `maxUploadBytes` kommer ur `config('files.max_upload_bytes')`
 * genom detaljvyns props, och en fil över taket avvisas här med serverns
 * egen mening, utan att bytena skickas. Vyns kontroll ersätter aldrig
 * serverns: samma fil prövas mot taket i StoreAttachmentRequest och mot
 * planen i AttachmentController.
 *
 * **Listan speglar alltid serverns svar** (Beslut 6). Varje lyckad POST
 * svarar `back()` med detaljvyns färska props — bilagorna kommer med dem —
 * och ingen rad läggs in i listan innan servern skapat den. Det är det som
 * gör att en avbruten uppladdning inte lämnar ett halvt tillstånd i vyn, och
 * köns egna rader är tillstånd i vyn: de försvinner när sidan lämnas.
 *
 * **Takgränsen är ett väntat svar, inte ett haveri** (Beslut 7).
 * `throttle:uploads` släpper igenom 60 anrop per minut och användare, så en
 * kö på hundra filer slår i den. Svaret kommer inte som 429: undantaget blir
 * en omdirigering med fel på `email` av samma closure som gör inloggningens
 * takgräns till ett fältfel (issue 53a § Beslut 6, `bootstrap/app.php`).
 * Meningen där är inloggningens och vore en lögn om en uppladdning, så kön
 * byter den mot sin egen — och en rå 429 (om en sådan någonsin når hit) får
 * samma mening. Kön stannar utan att tappa det som redan gått igenom: filen
 * ligger kvar som väntande och knappen startar den igen.
 *
 * **Två ytor, två flaggor** (60 Beslut 3). `can.create` ritar hela
 * uppladdningsytan — dropzonen, filväljaren och kön — och `can.delete` ritar
 * ta bort-knappen per rad. Båda är presentation: grinden i
 * App\Http\Controllers\AttachmentController prövas på nytt i varje skrivning.
 *
 * **Drag-drop är ett tillägg, aldrig den enda vägen in** (Beslut 3).
 * Dropzonen tar emot `drop` och `dragover`, och filväljaren bredvid är en
 * vanlig `<input type="file" multiple>` — den väg som fungerar med
 * tangentbord, med skärmläsare och på en telefon. Genomgången av
 * tillgängligheten är issue 68, men en yta som bara går att nå med mus byggs
 * inte här för att sedan repareras där.
 *
 * **Ingen svensk sträng i den här filen** (Beslut 8, 60 Beslut 9): varje ord
 * kommer ur `lang/{locale}/ui.php` genom `t()`.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /* Bilagorna ur detaljvyns props, redan sorterade och formaterade. */
    attachments: { type: Array, required: true },
    /* Det tekniska taket på en fil, ur `config('files.max_upload_bytes')`. */
    maxUploadBytes: { type: Number, required: true },
    /* Pärmens ägarkonto — förvalet när användaren är medlem i det. */
    containerAccount: { type: String, default: '' },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const page = usePage();

const fileInput = ref(null);

/*
 * Kontolistan ur den delade propen `auth.accounts`, samma väg som ItemForm
 * tar (60 Beslut 4). Förvalet är pärmens ägarkonto när användaren är medlem i
 * det, annars hennes första konto.
 */
const accounts = computed(() => page.props.auth?.accounts ?? []);

const defaultAccount = computed(() => {
    const owner = accounts.value.find((candidate) => candidate.ulid === props.containerAccount);

    return owner?.ulid ?? accounts.value[0]?.ulid ?? '';
});

const account = ref(defaultAccount.value);

/* Ett enda konto ritas som en rad text i stället för en väljare (60 Beslut 4). */
const singleAccount = computed(() => (accounts.value.length === 1 ? accounts.value[0] : null));

/*
 * Raderna: storleken formaterad och `kind` översatt till ett ord. Storleken
 * speglar serverns Number::fileSize() och är därför en-formaterad oavsett
 * sidans språk — se attachmentPresentation.js.
 */
const rows = computed(() => props.attachments.map((attachment) => ({
    ...attachment,
    size: formatByteSize(attachment.byte_size),
    kindLabel: t(`item.attachment.kind.${attachment.kind}`),
})));

/*
 * Kön. Varje rad är en fil användaren valt, med sin egen status och sitt eget
 * fel — den är tillstånd i vyn och ingenting annat (Beslut 6). `file` behålls
 * för att posten ska kunna skickas när raden blir den första väntande.
 */
const queue = ref([]);

/* Köns mening när hela anropet nekades — kontot eller takgränsen (Beslut 4 och 7). */
const blocked = ref(null);

/* Serverns fältfel på kontot, vid sidan av kön. */
const accountError = ref(null);

/* Sant medan en POST är i luften. */
const running = ref(false);

let nextId = 0;

const uploaded = computed(() => queue.value.filter((entry) => entry.status === 'done').length);

const hasWaiting = computed(() => queue.value.some((entry) => entry.status === 'waiting'));

/*
 * Sammanfattningen står efter kön (Beslut 4): ingen fil väntar, ingen är i
 * luften och inget anrop har nekats. `uploaded` av `queue.length` — de
 * misslyckade raderna ligger kvar med sitt fel tills användaren stänger dem.
 */
const finished = computed(() => queue.value.length > 0
    && !running.value
    && blocked.value === null
    && !queue.value.some((entry) => entry.status === 'waiting' || entry.status === 'uploading'));

function itemUrl() {
    return `/containers/${props.containerUlid}/items/${props.itemUlid}`;
}

/*
 * Filer in i kön. En fil över det tekniska taket får sitt fel HÄR och aldrig
 * ett anrop (Beslut 5): meningen är serverns egen — samma nyckel som
 * AttachmentController lägger på fältet `file` — med gränsen och filens
 * storlek i läsbar form, så vyn säger vilket tak som slog i.
 */
function enqueue(files) {
    blocked.value = null;

    for (const file of Array.from(files)) {
        const entry = {
            id: nextId++,
            file,
            name: file.name,
            size: file.size,
            status: 'waiting',
            percentage: 0,
            error: null,
        };

        if (file.size > props.maxUploadBytes) {
            entry.status = 'failed';
            entry.error = t('error.quota.max_file_size_exceeded', {
                limit_bytes: formatByteSize(props.maxUploadBytes),
                file_bytes: formatByteSize(file.size),
            });
        }

        queue.value.push(entry);
    }
}

/*
 * Filväljaren tömmer sitt DOM-värde direkt: kön äger filerna efter det här,
 * och ett fält som ser ut att ha en fil vald men inte skickar den igen är en
 * fälla för nästa val.
 */
function onSelect(event) {
    enqueue(event.target.files);
    event.target.value = '';
}

/* Dropzonen. `@dragover.prevent` krävs för att `drop` alls ska fyra. */
function onDrop(event) {
    enqueue(event.dataTransfer?.files ?? []);
}

/*
 * Knappen kör kön. Den är avstängd när ingenting väntar, så en kö som stannat
 * på takgränsen startas om med samma knapp — filen ligger kvar som väntande.
 */
function submit() {
    blocked.value = null;
    accountError.value = null;
    next();
}

/* Nästa väntande fil, eller ingenting: då är kön klar. */
function next() {
    const entry = queue.value.find((candidate) => candidate.status === 'waiting');

    if (!entry) {
        running.value = false;

        return;
    }

    running.value = true;
    upload(entry);
}

/*
 * Ett anrop för EN fil (issue 16a § Beslut 2) — flera filer är flera anrop,
 * aldrig ett anrop med en array. `forceFormData` låter Inertia bygga
 * `FormData` själv, så ingen `Content-Type` sätts och ingen serialisering
 * skrivs (Beslut 1 och 2: inga nya paket).
 *
 * `preserveState` är Inertias förval för POST, alltså behåller komponenten
 * sitt tillstånd när omdirigeringen kommer tillbaka — kön lever vidare.
 */
function upload(entry) {
    entry.status = 'uploading';
    entry.percentage = 0;
    entry.error = null;

    router.post(`${itemUrl()}/attachments`, { file: entry.file, account: account.value }, {
        forceFormData: true,
        preserveScroll: true,
        onProgress: (event) => {
            entry.percentage = event.percentage ?? 0;
        },
        onSuccess: () => {
            entry.status = 'done';
            accountError.value = null;
            next();
        },
        onError: (errors) => onRejected(errors, entry),
        onHttpException: (response) => onHttpFailure(response, entry),
        onNetworkError: () => onConnectionLost(entry),
    });
}

/*
 * Serverns svar på ett anrop. Fältfelet på `file` hör till filen och kön
 * fortsätter; allt annat gäller anropet, och då stannar kön.
 *
 * `focusFirstError` flyttar fokus till felet när det hör till ett renderat
 * fält — kontot har ett, kön har sina egna rader.
 */
function onRejected(errors, entry) {
    focusFirstError(errors);

    if (errors.file) {
        entry.status = 'failed';
        entry.error = errors.file;
        next();

        return;
    }

    accountError.value = errors.account ?? null;
    entry.status = 'waiting';
    stop(stopMessage(errors));
}

/*
 * Meningen som stannar kön. Kontots fel är kontots. `throttle:uploads` kastar
 * ThrottleRequestsException, och bootstrap/app.php gör den till en
 * omdirigering med fel på `email` — samma closure som gör inloggningens
 * takgräns till ett fältfel (issue 53a § Beslut 6). Meningen där är
 * inloggningens, och den vore en lögn om en uppladdning, så vyn byter den mot
 * köns egen (Beslut 7). Vilket annat svar som helst är oväntat och får
 * `error.generic` — en begriplig mening, aldrig en rå kod på skärmen.
 */
function stopMessage(errors) {
    if (errors.account) {
        return errors.account;
    }

    return errors.email ? t('item.attachment.throttled') : t('error.generic');
}

/*
 * Ett svar som inte är ett Inertia-svar gäller anropet och inte filen: kön
 * stannar, och filen ligger kvar som väntande så att ingenting tappas.
 * `false` stänger av Inertias modalfönster — raden bär felet i stället för en
 * ruta över sidan. En rå 429 får köns egen mening (Beslut 7).
 */
function onHttpFailure(response, entry) {
    entry.status = 'waiting';
    stop(response.status === 429 ? t('item.attachment.throttled') : t('error.generic'));

    return false;
}

function stop(message) {
    blocked.value = message;
    running.value = false;
}

/*
 * En avbruten uppladdning — uppkopplingen bröt innan svaret kom. Utan den här
 * grenen blir raden stående som "laddar upp" med sin sista procent, och kön
 * står still: ett halvt tillstånd i vyn, vilket är precis vad 60 § Klart när
 * förbjuder (Beslut 6). Filen ligger kvar som väntande, för anropet kan ha
 * nått fram ändå — serverns lista är den som vet — och kön stannar, för en
 * bruten länk får nästa fil att gå samma väg.
 */
function onConnectionLost(entry) {
    entry.status = 'waiting';
    entry.percentage = 0;
    stop(t('item.attachment.interrupted'));

    return false;
}

/* Stänger en misslyckad rad (Beslut 4) — den ligger i vyn och ingenting annat. */
function dismiss(entry) {
    queue.value = queue.value.filter((candidate) => candidate.id !== entry.id);
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

    router.delete(`${itemUrl()}/attachments/${attachment.ulid}`, {
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

        <!--
            Uppladdningsytan i sin helhet bakom `can.create` (60 Beslut 3):
            dropzonen, filväljaren och kön. En `read`-mottagare ser varken
            dropzon eller filväljare, och servern nekar posten ändå.
        -->
        <template v-if="can.create">
            <h3 class="mt-8 text-base font-semibold">{{ t('item.attachment.upload_heading') }}</h3>

            <!-- Vilket konto som betalar står före filen, inte efteråt: kvoten
                 räknas på det uppladdande kontot (60 Beslut 4). -->
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
                    :error="accountError"
                >
                    <select
                        id="account"
                        v-model="account"
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

                <!--
                    Dropzonen, med filväljaren i sig (Beslut 3). Väljaren är
                    den väg som fungerar med tangentbord, skärmläsare och på en
                    telefon; dropzonen är bekvämligheten ovanpå.
                -->
                <div
                    class="rounded border border-dashed border-slate-400 bg-slate-50 px-4 py-6"
                    @dragover.prevent
                    @drop.prevent="onDrop"
                >
                    <p class="text-sm text-slate-700">{{ t('item.attachment.dropzone') }}</p>

                    <label for="attachment-file" class="mt-3 block text-sm font-medium text-slate-800">
                        {{ t('item.attachment.file') }}
                    </label>

                    <input
                        id="attachment-file"
                        ref="fileInput"
                        type="file"
                        name="file"
                        multiple
                        class="mt-1 block w-full text-sm"
                        @change="onSelect"
                    >
                </div>

                <!--
                    Kön: en rad per vald fil (Beslut 1 och 2). Framdriften är
                    `onProgress`-siffran och ingenting annat, och en rad som
                    väntar visas som väntande.
                -->
                <ul v-if="queue.length > 0" class="flex flex-col gap-2">
                    <li
                        v-for="entry in queue"
                        :key="entry.id"
                        class="flex flex-col gap-1 rounded border border-slate-300 bg-white px-4 py-2"
                    >
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="font-medium text-slate-900">{{ entry.name }}</span>
                            <span class="text-sm text-slate-600">{{ formatByteSize(entry.size) }}</span>
                            <span class="text-sm text-slate-600">{{ t(`item.attachment.status.${entry.status}`) }}</span>
                            <span v-if="entry.status === 'uploading'" class="text-sm text-slate-600">{{ entry.percentage }} %</span>

                            <button
                                v-if="entry.status === 'failed'"
                                type="button"
                                class="text-sm text-red-700 hover:underline"
                                @click="dismiss(entry)"
                            >
                                {{ t('item.attachment.dismiss') }}
                            </button>
                        </div>

                        <progress
                            v-if="entry.status === 'uploading'"
                            :value="entry.percentage"
                            max="100"
                            class="h-1 w-full"
                        ></progress>

                        <p v-if="entry.error" class="text-sm text-red-700">{{ entry.error }}</p>
                    </li>
                </ul>

                <!-- Sammanfattningen efter kön (Beslut 4): antalet som kom
                     fram, och de misslyckade raderna ligger kvar ovanför.
                     Talen är två och orden är nycklar — meningen står i
                     `lang/` och är formulerad så att den är rätt för både en
                     och fyra filer. -->
                <p v-if="finished" class="text-sm text-slate-700">
                    {{ t('item.attachment.summary', { uploaded, total: queue.length }) }}
                </p>

                <!-- Köns egen mening när hela anropet nekades — kontot eller
                     takgränsen (Beslut 4 och 7). -->
                <p v-if="blocked" class="text-sm text-red-700">{{ blocked }}</p>

                <button
                    type="submit"
                    :disabled="running || !hasWaiting"
                    class="self-start rounded bg-blue-700 px-4 py-2 font-medium text-white disabled:opacity-50"
                >
                    {{ t('item.attachment.submit') }}
                </button>
            </form>
        </template>
    </section>
</template>
