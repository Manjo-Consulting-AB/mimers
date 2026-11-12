<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import UiButton from './UiButton.vue';
import UiSelect from './UiSelect.vue';
import { formatByteSize } from './attachmentPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Uppladdningskön som egen komponent (M55 · issue 367, [[ADR-0074
 * Registrering i vyn]] § 7). Utbruten ur ItemAttachmentSection.vue utan
 * synlig ändring: filväljaren, släppytan, kontovalet, kön, felen per fil och
 * sammanfattningen. Beslutsnumren i kommentarerna nedan är issue 60 och 60b:s,
 * där reglerna kom till.
 *
 * Adressen byggs ur `containerUlid` och `itemUlid`, så att containerns
 * Documents-flik kan ladda upp till olika items med samma kö. `itemUlid =
 * null` betyder att inget item är valt: väljaren och släppytan är avstängda
 * och komponenten ber användaren välja ett item först.
 *
 * Händelser: `busy` (sann medan en fil väntar eller laddas upp), `uploaded`
 * (filens namn, efter varje lyckad fil) och `finished` (kön är klar och inget
 * anrop har nekats). Listan över bilagor äger föräldern — den kommer med
 * serverns svar (Beslut 6) och kön lägger aldrig in en rad i den.
 *
 * Ingen svensk sträng i den här filen: varje ord kommer ur `lang/` genom `t()`.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    /* Null: inget item valt, och väljaren och släppytan är avstängda. */
    itemUlid: { type: String, default: null },
    /* Det tekniska taket på en fil, ur `config('files.max_upload_bytes')`. */
    maxUploadBytes: { type: Number, required: true },
    /* Containerns ägarkonto — förvalet när användaren är medlem i det. */
    containerAccount: { type: String, default: '' },
    /* Sant när containern är en inbox. Kön ändrar inget av den, men föräldern skickar värdet. */
    containerIsInbox: { type: Boolean, default: false },
});

const emit = defineEmits(['busy', 'uploaded', 'finished']);

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const page = usePage();

const fileInput = ref(null);

/* Inget item valt: väljaren och släppytan är avstängda. */
const disabled = computed(() => props.itemUlid === null);

/*
 * Kontolistan ur den delade propen `auth.accounts`, samma väg som ItemForm
 * tar (60 Beslut 4). Förvalet är containerns ägarkonto när användaren är medlem i
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

/* Adressen byggs ur props, så att samma kö kan ladda upp till olika items. */
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
    if (disabled.value) {
        return;
    }

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
            emit('uploaded', entry.name);
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

/* Sann medan en fil väntar eller laddas upp — föräldern kan då varna vid navigering. */
const busy = computed(() => queue.value.some((entry) => entry.status === 'waiting' || entry.status === 'uploading'));

watch(busy, (value) => emit('busy', value));

/* Kön är klar och inget anrop har nekats: föräldern kan läsa om sin lista. */
watch(finished, (value) => {
    if (value) {
        emit('finished');
    }
});
</script>

<template>
    <div>
    <!-- Vilket konto som betalar står före filen, inte efteråt: kvoten
         räknas på det uppladdande kontot (60 Beslut 4). -->
    <p class="mt-1 text-sm text-ink-muted">{{ t('item.attachment.billing_note') }}</p>

    <form class="mt-4 flex max-w-lg flex-col gap-4" @submit.prevent="submit">
        <!-- Ett enda konto: värdet är förvalt och visas som text. -->
        <div v-if="singleAccount" class="flex flex-col gap-1">
            <p class="text-sm font-medium text-ink">{{ t('item.attachment.account') }}</p>
            <p>{{ singleAccount.name }}</p>
        </div>

        <FormField
            v-else
            v-slot="{ describedBy }"
            :label="t('item.attachment.account')"
            id="account"
            :error="accountError"
        >
            <UiSelect
                id="account"
                v-model="account"
                :described-by="describedBy"
                name="account"
                required
                class="self-start"
            >
                <option v-for="candidate in accounts" :key="candidate.ulid" :value="candidate.ulid">
                    {{ candidate.name }}
                </option>
            </UiSelect>
        </FormField>

        <!--
            Dropzonen, med filväljaren i sig (Beslut 3). Väljaren är
            den väg som fungerar med tangentbord, skärmläsare och på en
            telefon; dropzonen är bekvämligheten ovanpå.
        -->
        <div
            class="rounded border border-dashed border-border bg-surface-sunken px-4 py-6"
            @dragover.prevent
            @drop.prevent="onDrop"
        >
            <p class="text-sm text-ink-muted">{{ t('item.attachment.dropzone') }}</p>

            <label for="attachment-file" class="mt-3 block text-sm font-medium text-ink">
                {{ t('item.attachment.file') }}
            </label>

            <input
                id="attachment-file"
                ref="fileInput"
                type="file"
                name="file"
                multiple
                :disabled="disabled"
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
                class="flex flex-col gap-1 rounded border border-border bg-surface px-4 py-2"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-medium text-ink">{{ entry.name }}</span>
                    <span class="text-sm text-ink-muted">{{ formatByteSize(entry.size) }}</span>
                    <span class="text-sm text-ink-muted">{{ t(`item.attachment.status.${entry.status}`) }}</span>
                    <span v-if="entry.status === 'uploading'" class="text-sm text-ink-muted">{{ entry.percentage }} %</span>

                    <button
                        v-if="entry.status === 'failed'"
                        type="button"
                        class="inline-flex min-h-11 items-center text-sm text-danger hover:underline"
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

                <!--
                    Felraden bär sitt eget id och `role="alert"`: kön
                    har inget FormField, så `focusFirstError` hittar
                    inget `file-error` att flytta fokus till. Felet
                    annonseras i stället när raden ritas, utan att
                    fokus rycks in i en bakgrundsrad (issue 60b).
                -->
                <p
                    v-if="entry.error"
                    :id="`attachment-error-${entry.id}`"
                    role="alert"
                    class="text-sm text-danger"
                >
                    {{ entry.error }}
                </p>
            </li>
        </ul>

        <!-- Inget item valt: väljaren och släppytan är avstängda. -->
        <p v-if="disabled" class="text-sm text-ink-muted">{{ t('item.attachment.choose_item_first') }}</p>

        <!-- Sammanfattningen efter kön (Beslut 4): antalet som kom
             fram, och de misslyckade raderna ligger kvar ovanför.
             Talen är två och orden är nycklar — meningen står i
             `lang/` och är formulerad så att den är rätt för både en
             och fyra filer. -->
        <p v-if="finished" class="text-sm text-ink-muted">
            {{ t('item.attachment.summary', { uploaded, total: queue.length }) }}
        </p>

        <!-- Köns egen mening när hela anropet nekades — kontot eller
             takgränsen (Beslut 4 och 7). -->
        <p v-if="blocked" class="text-sm text-danger">{{ blocked }}</p>

        <UiButton type="submit" :pending="running || !hasWaiting || disabled" class="self-start">
            {{ running ? t('common.pending.upload') : t('item.attachment.submit') }}
        </UiButton>
    </form>
    </div>
</template>
