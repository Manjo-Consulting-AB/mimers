<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { attachmentPreview, formatByteSize } from './attachmentPresentation.js';
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
 *
 * **Visningen är tre ytor och en flagga** (issue 61b § Beslut 1–5):
 * miniatyren i raden, bildvisaren och PDF-ramen. Vilken rad som får vilken
 * avgörs av `attachmentPreview()` i attachmentPresentation.js — den läser
 * `variants` och `inlineEnabled` och ingenting annat, så mallen grenar på
 * ett enda värde och komponenten gissar aldrig vad servern har.
 *
 * **Bildvisaren är webbläsarens `<dialog>`** (Beslut 3): Esc stänger, fokus
 * går tillbaka till miniatyren, och `alt` är filnamnet — ur datan och inte ur
 * `lang/`. Ingen karusell, ingen zoom, inget paket: det här är "se bilden".
 * Källan är `medium`, och originalet när den varianten saknas.
 *
 * **PDF:en ritas i en `<iframe>` mot filoriginet** (Beslut 4), och ramen
 * ligger överst i raden så att nedladdningslänken står kvar UNDER den.
 * Rutan bär filnamnet som `title`, och meningen under den säger vad läsaren
 * gör om webbläsaren inte har en egen läsare — samma nedladdningslänk som
 * varje rad alltid har (Beslut 5).
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /* Bilagorna ur detaljvyns props, redan sorterade och formaterade. */
    attachments: { type: Array, required: true },
    /*
     * Bilagans ULID → de derivatvarianter som finns, ur detaljvyns props
     * (61b § Beslut 1). Byggd på servern bredvid resursen och eager-laddad,
     * så uppslaget är en tabell och aldrig en gissning.
     */
    variants: { type: Object, required: true },
    /*
     * Sant när användarfiler levereras från en egen origin (61b § Beslut 2).
     * Falsk betyder att allt levereras som `attachment`, och då ritas varken
     * bildvisaren eller PDF-ramen — sektionen ser ut som i 60a.
     */
    inlineEnabled: { type: Boolean, required: true },
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
    preview: attachmentPreview(attachment, props.variants, props.inlineEnabled),
})));

/*
 * Bildvisaren (Beslut 3). Ett enda `<dialog>` för hela sektionen och inte ett
 * per rad: raden är en länk, dialogen är en yta, och tio dolda dialoger hade
 * varit tio element som ingen ser.
 *
 * `viewer` är bilagan som visas, `viewerTrigger` miniatyren som öppnade den.
 * Fokus tillbaka till den är vad `<dialog>` gör av sig själv — men bara när
 * elementet den återlämnar till fortfarande finns, och det vet bara den här
 * filen. Därför sätts fokus uttryckligen i stället för att litas på.
 */
const viewer = ref(null);
const viewerTrigger = ref(null);
const viewerElement = ref(null);

function openViewer(attachment, event) {
    viewer.value = attachment;
    viewerTrigger.value = event.currentTarget;
    viewerElement.value?.showModal();
}

/*
 * Stänger visaren. Anropas av stängknappen; Esc går samma väg genom dialogens
 * `close`-händelse, som webbläsaren fyrt av även då.
 */
function closeViewer() {
    viewerElement.value?.close();
}

function onViewerClosed() {
    viewer.value = null;
    viewerTrigger.value?.focus();
    viewerTrigger.value = null;
}

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
 *
 * `pending` är raderingens vänteläge (issue 68a § Beslut 4 och 5): knappen är
 * stängd och byter ord medan servern svarar. Den bär den klickade radens ULID,
 * inte en delad boolean — annars stänger en rad alla listans rader. Uppladdningen
 * har sin egen flagga i köns `running` — de två flödena har olika varaktighet
 * och delar därför inte vänteläge.
 */
const pending = ref(null);

function destroy(attachment) {
    if (! window.confirm(t('item.attachment.destroy_confirm'))) {
        return;
    }

    router.delete(`${itemUrl()}/attachments/${attachment.ulid}`, {
        preserveScroll: true,
        onStart: () => { pending.value = attachment.ulid; },
        onFinish: () => { pending.value = null; },
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
                class="flex flex-col gap-3 rounded border border-slate-300 bg-white px-4 py-2"
            >
                <!--
                    PDF-ramen ÖVERST i raden (61b § Beslut 4), så att
                    nedladdningslänken står kvar under den. Webbläsarens egen
                    läsare ritar den: inget paket, ingen andra renderare att
                    hålla i takt. `title` är filnamnet ur datan.

                    Ingen `sandbox` här: det är 61a:s CSP som stänger av
                    skriptet i dokumentet, och en sandbox på ramen hade slagit
                    av webbläsarens egen läsare med.
                -->
                <template v-if="attachment.preview.frame">
                    <iframe
                        :src="attachment.preview.frame"
                        :title="attachment.filename"
                        loading="lazy"
                        class="h-96 w-full rounded border border-slate-200"
                    ></iframe>

                    <p class="text-sm text-slate-600">{{ t('item.attachment.pdf_fallback') }}</p>
                </template>

                <div class="flex flex-wrap items-center gap-3">
                    <!--
                        Miniatyren (Beslut 1 och 3). Klicket öppnar bilden i
                        sidan; knappen är knapp och inte en div, så den går
                        att nå med tangentbord.
                    -->
                    <button
                        v-if="attachment.preview.display === 'thumb'"
                        type="button"
                        class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded border border-slate-300"
                        @click="openViewer(attachment, $event)"
                    >
                        <img
                            :src="attachment.preview.thumbnail"
                            :alt="attachment.filename"
                            class="h-16 w-16 rounded object-cover"
                        >
                    </button>

                    <!--
                        Filikonen (Beslut 1). En bilaga utan `thumb` — nyss
                        uppladdad, eller en PDF — ritas så här och ALDRIG som
                        en trasig bild: `?variant=thumb` mot en bilaga utan
                        derivat är 404.
                    -->
                    <span
                        v-else-if="attachment.preview.display === 'file'"
                        role="img"
                        :aria-label="t('item.attachment.file_icon')"
                        class="flex h-16 w-16 shrink-0 items-center justify-center rounded border border-slate-300 bg-slate-50 text-slate-600"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="h-8 w-8"
                            aria-hidden="true"
                        >
                            <path d="M14 3v5h5M6 3h8l5 5v13H6z"></path>
                        </svg>
                    </span>

                    <span class="font-medium text-slate-900">{{ attachment.filename }}</span>
                    <span class="text-sm text-slate-600">{{ attachment.kindLabel }}</span>
                    <span v-if="attachment.size" class="text-sm text-slate-600">{{ attachment.size }}</span>

                    <!--
                        Nedladdningen går alltid genom appen (issue 19a):
                        rutten kontrollerar `view` på itemet före leveransen.
                        Den står på VARJE rad, också de som ritas inline — att
                        se en faktura är inte att ha den (Beslut 5), och den är
                        samtidigt vägen vidare när webbläsaren inte kan visa
                        PDF:en.
                    -->
                    <a
                        :href="`/files/${attachment.ulid}`"
                        class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                    >
                        {{ t('item.attachment.download') }}
                    </a>

                    <button
                        v-if="can.delete"
                        type="button"
                        :disabled="pending === attachment.ulid"
                        class="inline-flex min-h-11 items-center text-sm text-red-700 hover:underline"
                        @click="destroy(attachment)"
                    >
                        {{ pending === attachment.ulid ? t('common.pending.default') : t('item.attachment.destroy') }}
                    </button>
                </div>
            </li>
        </ul>

        <!--
            Bildvisaren (Beslut 3). Webbläsarens egen `<dialog>`: Esc stänger,
            fokus lämnas tillbaka till miniatyren. Källan är `medium` och
            originalet när den varianten saknas — `image` är alltid en giltig
            URL.
        -->
        <dialog
            ref="viewerElement"
            class="m-auto max-h-[90vh] max-w-full overflow-auto rounded border border-slate-300 bg-white p-4 backdrop:bg-slate-900/50"
            @close="onViewerClosed"
        >
            <div v-if="viewer" class="flex max-w-3xl flex-col gap-3">
                <h3 class="text-lg font-semibold">{{ t('item.attachment.viewer_heading') }}</h3>

                <img
                    :src="viewer.preview.image"
                    :alt="viewer.filename"
                    class="max-h-[70vh] max-w-full object-contain"
                >

                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-medium text-slate-900">{{ viewer.filename }}</span>

                    <a
                        :href="`/files/${viewer.ulid}`"
                        class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                    >
                        {{ t('item.attachment.download') }}
                    </a>

                    <button
                        type="button"
                        class="ml-auto inline-flex min-h-11 items-center text-sm font-medium text-slate-700 hover:underline"
                        @click="closeViewer"
                    >
                        {{ t('item.attachment.viewer_close') }}
                    </button>
                </div>
            </div>
        </dialog>

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
                                class="inline-flex min-h-11 items-center text-sm text-red-700 hover:underline"
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
                            class="text-sm text-red-700"
                        >
                            {{ entry.error }}
                        </p>
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
                    class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                >
                    {{ running ? t('common.pending.upload') : t('item.attachment.submit') }}
                </button>
            </form>
        </template>
    </section>
</template>
