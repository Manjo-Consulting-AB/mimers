<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AttachmentRowActions from './AttachmentRowActions.vue';
import AttachmentViewer from './AttachmentViewer.vue';
import AttachmentUploadQueue from './AttachmentUploadQueue.vue';
import ItemTargetPicker from './ItemTargetPicker.vue';
import UiButton from './UiButton.vue';
import UiEmptyState from './UiEmptyState.vue';
import UiIcon from './UiIcon.vue';
import { attachmentPreview, formatByteSize } from './attachmentPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kön, dropzonen, kontovalet och felen per fil bor i AttachmentUploadQueue.vue
 * (M55 · issue 367); reglerna nedan om kön gäller den komponenten.
 *
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
 * miniatyren i raden, förhandsvisningsknappen och visaren. Vilken rad som får
 * vilken avgörs av `attachmentPreview()` i attachmentPresentation.js — den
 * läser `variants` och `inlineEnabled` och ingenting annat, så mallen grenar
 * på ett enda värde och komponenten gissar aldrig vad servern har. Modulen
 * rörs inte av M24 (Beslut 5): `display`, `image` och `frame` betyder samma
 * sak som förut, och det är bara mallen som väljer var de ritas.
 *
 * **Visaren är webbläsarens dialog, i AttachmentViewer.vue** (Beslut 3 och 4; M57): Esc stänger, fokus
 * går tillbaka till den som öppnade, och `alt` är filnamnet — ur datan och inte
 * ur `lang/`. Ingen karusell, ingen zoom, inget paket: det här är "se bilden".
 * Källan är `medium`, och originalet när den varianten saknas. Samma dialog
 * visar en PDF, i en ram mot filoriginet — ramen skapas först när dialogen
 * öppnas, så ingen förfrågan går mot filoriginet förrän användaren ber om
 * den.
 *
 * **Två testarfynd 2026-10-02 flyttade ytorna** (M24, Beslut 1 och 2).
 * Uppladdningen stod under listan, och med en PDF-ram på 24 rem per PDF fick
 * man skrolla förbi allt för att ladda upp nästa fil — blocket står därför
 * FÖRE listan nu, med innehållet oförändrat. Och PDF:en ritas inte längre i
 * raden: den ritade en ram per rad och gjorde listan svår att överblicka.
 * Raden ritar filikonen — samma `<span role="img">` som
 * `display === 'file'` ritar — och en *Preview*-knapp för varje bilaga som
 * har något att visa (`image` eller `frame`), och PDF:en ritas i visaren.
 *
 * **Färgerna är roller och inte palettfärger** (issue 182 · [[ADR-0042
 * Designsystemet]] § Beslut): `text-ink`, `text-ink-muted`, `text-accent` och
 * `text-danger` ur `@theme` i `resources/css/app.css`. Kontosektionen är
 * `FormField` med `UiSelect` i slotten och skicka-knappen är `UiButton` —
 * samma komponenter som de fem formulären i issue 425 består av. De tre
 * knappar som bär en `@click` står kvar som rå `<button>`: GenomgangTest
 * tillåter bara webbläsarens egna element som klickbar yta, och en
 * `<UiButton>` hade fallit på den regeln. Filväljaren är kvar som rå
 * `<input type="file">` med flit — `v-model` är inte hur en fil väljs, och
 * `UiInput` bär just en sådan. Beteendet är oförändrat.
 *
 * **Raden får *Move…* och *Copy…*** (issue 243 · [[ADR-0053 Flytt och
 * kopiering]] § 2). Båda öppnar ItemTargetPicker med itemet som
 * `excludeItem` — att flytta något till sig självt är inget mål — och postar
 * valet som `target` till rutten för respektive handling. *Move…* ritas
 * bakom `can.delete` på itemet: en flytt tar bort något från källan, och den
 * som inte får radera ska inte kunna tömma itemet genom att flytta allt.
 * *Copy…* står på varje rad, för en kopia rör inte originalet och raden syns
 * bara för den som får läsa itemet. Servern prövar båda grindarna på nytt;
 * flaggan här är presentation.
 *
 * **Felet ritas på den rad som flyttades.** Kvotfelet
 * (`quota.storage_exceeded`) kommer som ett fältfel på `attachment`, och ett
 * 403 från grinden fångas av `onHttpException` — annars hade Inertia visat
 * sin egen ruta mitt över sidan. Samma väg som köns radfel: raden bär sin
 * egen mening, och resten av listan står kvar orörd.
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
    /* Containerns ägarkonto — förvalet när användaren är medlem i det. */
    containerAccount: { type: String, default: '' },
    /*
     * Sant när itemets container är en inbox (M27 · issue 244, ADR-0054 § 1).
     * *Back to Inbox* ritas då inte på raden — bilagan är redan där, och
     * rutten hade svarat 422 `attachment.already_in_inbox`.
     */
    containerIsInbox: { type: Boolean, default: false },
    /*
     * Ulid för bilagan som är itemets omslag just nu (issue 342) — den
     * upplösta bilden, ur detaljvyns `cover`. Raden *Use as cover* ritas inte
     * på den. Null när itemet saknar omslag.
     */
    coverUlid: { type: String, default: null },
    can: { type: Object, required: true },
});

const { t } = useTranslations();

/*
 * Tomt lägets åtgärd ([[ADR-0068 Itemets sida och skapandet]] § 7): flikens
 * formulär står redan på sidan, så åtgärden flyttar fokus till dess första
 * fält. Inget formulär, ingen rättighet och ingen ändpunkt ändras.
 */
function focusForm() {
    document.getElementById('attachment-file')?.focus();
}
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
 * Bildvisaren (Beslut 3) bor i AttachmentViewer.vue (M57 · issue 372). Här
 * finns bara raden som visas och ett anrop till visarens `open()`.
 */
const viewer = ref(null);
const viewerElement = ref(null);

function openViewer(attachment, event) {
    viewerElement.value?.open(attachment, event.currentTarget);
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

/*
 * Flytten och kopian (issue 243). Väljaren (issue 242) äger listan och
 * valet; den här filen äger raden som öppnade den och vart valet ska.
 *
 * `picked` bär bilagan OCH verbet, alltså `move` eller `copy` — samma ord
 * som står i adressen. Anroparen läser det ur argumentet och aldrig ur en
 * ref som stängningen kan ha nollställt medan anropet är i luften.
 */
const pickerOpen = ref(false);
const pickerTrigger = ref(null);
const pickerHeading = ref('');
const picked = ref(null);

/* Radens fel: kvotfelet eller 403, ritat på den rad som flyttades. */
const actionError = ref(null);

function openPicker(attachment, action, event) {
    picked.value = { attachment, action };
    pickerTrigger.value = event.currentTarget;
    pickerHeading.value = action === 'copy'
        ? t('item.attachment.copy_heading')
        : t('item.attachment.move_heading');
    pickerOpen.value = true;
}

/* Väljaren stängdes — oavsett väg, ett val eller Esc. */
function closePicker() {
    pickerOpen.value = false;
    picked.value = null;
}

/*
 * Valet ur väljaren. Målet postas som `target`, och svaret är `back()` med
 * färska props — listan speglar alltid serverns svar, samma regel som
 * uppladdningen (Beslut 6). `preserveScroll` håller raden kvar under
 * fingret. Ett 403 fångas och ritas på raden; `false` stänger av Inertias
 * egen felruta, precis som köns `onHttpFailure` gör.
 */
function chooseTarget(target) {
    const { attachment, action } = picked.value;

    // Verbet står i adressen: `move` och `copy` är två bokstavliga suffix, och
    // `picked` bär det ena.
    const url = `${itemUrl()}/attachments/${attachment.ulid}${action === 'copy' ? '/copy' : '/move'}`;

    router.post(url, { target: target.ulid }, {
        preserveScroll: true,
        onStart: () => {
            pending.value = attachment.ulid;
            actionError.value = null;
        },
        onFinish: () => { pending.value = null; },
        onError: (errors) => {
            actionError.value = { ulid: attachment.ulid, message: errors.attachment };
        },
        onHttpException: (response) => {
            actionError.value = {
                ulid: attachment.ulid,
                message: response.status === 403 ? t('error.403') : t('error.generic'),
            };

            return false;
        },
    });
}

/*
 * *Use as cover* (issue 342 · [[ADR-0068 Itemets sida och skapandet]] § 12).
 * Raden står på varje bild som inte redan är omslaget, och bara med
 * `can.update` — samma grind som itemets egen PATCH, som servern prövar på
 * nytt. Anropet går mot itemets befintliga uppdatering och skickar BARA
 * `cover`: kategori, taggar och övriga fält är utelämnade, och servern rör
 * dem inte när nyckeln saknas. `preserveScroll` håller raden kvar under
 * fingret. Ett fel ritas på raden, som flyttens.
 */
function useAsCover(attachment) {
    router.patch(itemUrl(), { cover: attachment.ulid }, {
        preserveScroll: true,
        onStart: () => {
            pending.value = attachment.ulid;
            actionError.value = null;
        },
        onFinish: () => { pending.value = null; },
        onError: (errors) => {
            actionError.value = { ulid: attachment.ulid, message: errors.cover };
        },
        onHttpException: (response) => {
            actionError.value = {
                ulid: attachment.ulid,
                message: response.status === 403 ? t('error.403') : t('error.generic'),
            };

            return false;
        },
    });
}

/*
 * *Back to Inbox* (M27 · issue 244, [[ADR-0054 Inboxen]] § 6). En egen
 * handling och inte ett val i väljaren: den går alltid till användarens EGEN
 * inbox, och den skapas om den saknas. Knappen står bakom `can.delete`, som
 * *Move…* intill, och servern prövar samma grind på nytt.
 *
 * Ägaren blir personkontot och dess kvot prövas (ADR-0053 § 3 och 4): ett
 * kvotfel kommer som ett fältfel på `attachment` och ritas på raden, precis
 * som flyttens. Kroppen är tom — målet står inte i den.
 */
function toInbox(attachment) {
    router.post(`${itemUrl()}/attachments/${attachment.ulid}/inbox`, {}, {
        preserveScroll: true,
        onStart: () => {
            pending.value = attachment.ulid;
            actionError.value = null;
        },
        onFinish: () => { pending.value = null; },
        onError: (errors) => {
            actionError.value = { ulid: attachment.ulid, message: errors.attachment };
        },
        onHttpException: (response) => {
            actionError.value = {
                ulid: attachment.ulid,
                message: response.status === 403 ? t('error.403') : t('error.generic'),
            };

            return false;
        },
    });
}
</script>

<template>
    <section class="mt-10">
        <h2 class="text-lg font-semibold">{{ t('item.attachment.heading') }}</h2>

        <!--
            Uppladdningsytan i sin helhet bakom `can.create` (60 Beslut 3):
            dropzonen, filväljaren och kön. En `read`-mottagare ser varken
            dropzon eller filväljare, och servern nekar posten ändå.

            Ytan står FÖRE listan (M24, testarfynd 2026-10-02): med några
            bilagor, och en PDF-ram på 24 rem per PDF, måste man skrolla förbi
            allt för att ladda upp nästa fil. Innehållet är oförändrat — bara
            var det står.
        -->
        <template v-if="can.create">
            <h3 class="mt-8 text-base font-semibold">{{ t('item.attachment.upload_heading') }}</h3>

            <AttachmentUploadQueue
                :container-ulid="containerUlid"
                :item-ulid="itemUlid"
                :max-upload-bytes="maxUploadBytes"
                :container-account="containerAccount"
                :container-is-inbox="containerIsInbox"
            />
        </template>

        <!-- En tom container och ett item utan bilagor säger samma sak: det finns
             ingen rad att visa, och vyn hittar inte på en. -->
        <UiEmptyState v-if="rows.length === 0" class="mt-2">
            {{ t('item.attachment.empty') }}

            <template v-if="can.create" #action>
                <UiButton type="button" @click="focusForm">{{ t('item.show.add_document') }}</UiButton>
            </template>
        </UiEmptyState>

        <ul v-else class="mt-2 flex flex-col gap-2">
            <li
                v-for="attachment in rows"
                :key="attachment.ulid"
                class="flex flex-col gap-3 rounded border border-border bg-surface px-4 py-2"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <!--
                        Miniatyren (Beslut 1 och 3). Klicket öppnar bilden i
                        sidan; knappen är knapp och inte en div, så den går
                        att nå med tangentbord.
                    -->
                    <button
                        v-if="attachment.preview.display === 'thumb'"
                        type="button"
                        class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded border border-border"
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

                        PDF-raden ritar samma ikon (M24, testarfynd
                        2026-10-02): ramen flyttade in i visaren, och `frame`
                        har därför ingen egen yta i raden längre.
                    -->
                    <span
                        v-else-if="attachment.preview.display === 'file' || attachment.preview.display === 'frame'"
                        role="img"
                        :aria-label="t('item.attachment.file_icon')"
                        class="flex h-16 w-16 shrink-0 items-center justify-center rounded border border-border bg-surface-sunken text-ink-muted"
                    >
                        <UiIcon name="document" :size="24" class="h-8 w-8" />
                    </span>

                    <!--
                        Namnet öppnar visaren när filen går att visa — `image`
                        för en bild, `frame` för en PDF — och är ren text
                        annars (M57 · issue 372). Utan ett filorigin är båda
                        `null`.
                    -->
                    <button
                        v-if="attachment.preview.image !== null || attachment.preview.frame !== null"
                        type="button"
                        class="min-h-11 text-left font-medium text-ink hover:underline"
                        @click="openViewer(attachment, $event)"
                    >
                        {{ attachment.filename }}
                    </button>
                    <span v-else class="font-medium text-ink">{{ attachment.filename }}</span>
                    <span class="text-sm text-ink-muted">{{ attachment.kindLabel }}</span>
                    <span v-if="attachment.size" class="text-sm text-ink-muted">{{ attachment.size }}</span>

                    <!--
                        *Use as cover* (issue 342): på varje bild utom den som
                        redan är omslaget, och bara med `can.update`.
                    -->
                    <button
                        v-if="can.update && attachment.kind === 'image' && attachment.ulid !== coverUlid"
                        type="button"
                        :disabled="pending === attachment.ulid"
                        class="inline-flex min-h-11 items-center text-sm font-medium text-accent hover:underline"
                        @click="useAsCover(attachment)"
                    >
                        {{ t('item.attachment.use_as_cover') }}
                    </button>

                    <!--
                        Nedladdningen går alltid genom appen (issue 19a):
                        rutten kontrollerar `view` på itemet före leveransen.
                        Den står på VARJE rad, också de som ritas inline — att
                        se en faktura är inte att ha den (Beslut 5), och den är
                        samtidigt vägen vidare när webbläsaren inte kan visa
                        PDF:en.
                    -->
                    <AttachmentRowActions
                        :attachment="{ ...attachment, can }"
                        :container-is-inbox="containerIsInbox"
                        :pending="pending === attachment.ulid"
                        @move="openPicker(attachment, 'move', $event)"
                        @copy="openPicker(attachment, 'copy', $event)"
                        @inbox="toInbox(attachment)"
                        @destroy="destroy(attachment)"
                    />
                </div>

                <!--
                    Radens fel (issue 243): kvotfelet ur fältfelet på
                    `attachment`, eller 403:an ur `onHttpException`. Det står
                    under raden det gäller och lämnar resten av listan orörd —
                    samma form som köns felrad.
                -->
                <p
                    v-if="actionError && actionError.ulid === attachment.ulid"
                    role="alert"
                    class="text-sm text-danger"
                >
                    {{ actionError.message }}
                </p>
            </li>
        </ul>

        <AttachmentViewer
            ref="viewerElement"
            v-model:attachment="viewer"
            :preview="viewer ? viewer.preview : null"
        />

        <!--
            Målväljaren (issue 242 och 243). Ett mål per anrop: raden som
            öppnade den bär sin ULID och sitt verb, och valet postas till
            radens egen rutt. `exclude-item` är itemet, så flytten aldrig
            erbjuder ett mål som är källan.
        -->
        <ItemTargetPicker
            :open="pickerOpen"
            :trigger="pickerTrigger"
            :exclude-item="itemUlid"
            :heading="pickerHeading"
            @choose="chooseTarget"
            @close="closePicker"
        />

    </section>
</template>
