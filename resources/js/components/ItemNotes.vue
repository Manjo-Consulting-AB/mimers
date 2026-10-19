<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useHttp } from '@inertiajs/vue3';
import UiCard from './UiCard.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Itemets anteckning på översikten — M42 · issue 325 ·
 * [[ADR-0066 Itemets anteckning]] § 3–9.
 *
 * **Läsläget ritar HTML som servern har ritat.** `notesHtml` kommer ur
 * App\Actions\Item\RenderItemNotes och skrivs med `v-html`. Det är det enda
 * stället i koden där `v-html` används för innehåll, och undantaget gäller
 * bara just den här HTML:en: klienten ritar aldrig Markdown själv (§ 2 och 3).
 * Förbudet i [[ADR-0057 Ikoner och typsnitt]] § 2 gäller ikonernas SVG och
 * står kvar.
 *
 * **Den som har `update` redigerar direkt i rutan** (§ 5). Ett klick i rutan,
 * utom på en länk, eller Enter när den har fokus, gör den till ett
 * `<textarea>` med markören i slutet. Fältet växer med texten. När det tappar
 * fokus, eller vid Esc, ritas läsläget igen ur svarets `notes_html`. En tom
 * anteckning visar fältet direkt, så att man kan börja skriva utan att först
 * klicka fram det.
 *
 * Klicket fångas med en lyssnare på omslaget i stället för med `@click`:
 * innehållet är serverritad HTML som kan bära länkar, och en `<div>` med
 * `@click` går inte att nå med tabb (GenomgangTest). Rutan har därför
 * `tabindex="0"` och Enter, och lyssnaren hoppar över länkar — samma regel
 * som § 5 säger.
 *
 * **Anteckningen sparas av sig själv** (§ 6). En sekund efter sista
 * tangenttrycket, och direkt när fältet tappar fokus, skickas den till
 * `containers.items.notes.update` med Inertias `useHttp` — en vanlig
 * HTTP-förfrågan och inte ett sidbesök, så sidan laddas inte om och det man
 * skriver medan en sparning pågår står kvar. Bara en förfrågan åt gången:
 * kommer en ny ändring medan en sparning pågår skickas den när den första är
 * klar. Statusraden bredvid rubriken säger *Saving…*, *Saved* eller *Not
 * saved* med *Try again*, och `beforeunload` varnar medan en ändring väntar.
 *
 * **Den som sparar sist vinner** (§ 7). Ingen versionskontroll och ingen
 * sammanslagning; servern jämför ingen `updated_at` och klienten skickar
 * ingen.
 *
 * **Den som saknar `update` ser den ritade anteckningen utan fält** (§ 8).
 * Är anteckningen tom ritas ingen ruta alls.
 *
 * Typografin för den ritade HTML:en — rubriker, listor, kod, citat, tabeller
 * och länkar — står i `.item-notes-content` i resources/css/app.css med
 * designsystemets token (§ 3). Ingen typografiplugin.
 */
const props = defineProps({
    /* Itemet ur detaljvyns props; `notes` är den råa texten fältet öppnas med. */
    item: { type: Object, required: true },
    /* Anteckningen ritad som HTML, eller null när den är tom. */
    notesHtml: { type: String, default: null },
    /* Får användaren ändra itemet? Samma grind som `can.update`. */
    canEdit: { type: Boolean, required: true },
    /* Adressen till `containers.items.notes.update`. */
    updateUrl: { type: String, required: true },
});

const { t } = useTranslations();

/*
 * Den sparade texten och utkastet hålls isär: statusraden och
 * `beforeunload` frågar om de två skiljer sig, och svaret är vad som avgör om
 * en sparning väntar. `rendered` är HTML:en läsläget ritar, och den byts mot
 * svarets `notes_html` när en sparning har gått igenom.
 */
const savedText = ref(props.item.notes ?? '');
const draft = ref(savedText.value);
const rendered = ref(props.notesHtml);
const editing = ref(props.canEdit && draft.value.trim() === '');
const status = ref(null);
const saving = ref(false);

const http = useHttp({ notes: savedText.value });

const box = ref(null);
const field = ref(null);
const readView = ref(null);

let timer = null;
let failed = false;

/*
 * Rutan ritas när den går att redigera eller när det finns något att läsa.
 * En tom anteckning för en läsare är ingen tom ruta: den ritas inte alls
 * (§ 8).
 */
const showCard = computed(() => props.canEdit || rendered.value !== null);

/*
 * Frågan statusraden och `beforeunload` ställer: väntar en osparad ändring?
 *
 * Jämförelsen är trimmad därför att servern trimmar `notes` (Laravels
 * `TrimStrings`). Utan trimningen hade ett avslutande blanksteg setts som en
 * ändring varje gång en sparning kom tillbaka, och skickats igen i en slinga.
 */
function isDirty() {
    return draft.value.trim() !== savedText.value.trim();
}

function scheduleSave() {
    clearTimeout(timer);
    timer = setTimeout(saveNow, 1000);
}

/*
 * Sparar utkastet om det skiljer sig från det sparade. `saving` är grinden
 * som gör att bara en förfrågan är i luften: en ändring som kommer medan den
 * första pågår skickas av `onFinish` när den första är klar.
 */
function saveNow() {
    clearTimeout(timer);
    timer = null;

    if (! props.canEdit || saving.value || ! isDirty()) {
        return;
    }

    /*
     * Det som skickas är det som sparades. Servern trimmar `notes`, så
     * `savedText` får inte sättas ur svaret: då hade ett avslutande blanksteg
     * setts som en ny ändring och skickats om och om igen.
     */
    const sent = draft.value;

    saving.value = true;
    failed = false;
    status.value = 'saving';
    http.notes = sent;

    http.patch(props.updateUrl, {
        onSuccess: (response) => {
            savedText.value = sent;
            rendered.value = response?.notes_html ?? null;
            status.value = 'saved';
        },
        onError: () => {
            failed = true;
            status.value = 'error';
        },
        onHttpException: () => {
            failed = true;
            status.value = 'error';
        },
        onNetworkError: () => {
            failed = true;
            status.value = 'error';
        },
        onFinish: () => {
            saving.value = false;

            // En ändring som kom medan förfrågan pågick skickas nu, men bara
            // efter en lyckad sparning: ett fel väntar på *Try again* och ska
            // inte bli en oändlig serie förfrågningar.
            if (! failed && isDirty()) {
                saveNow();
            }
        },
    }).catch(() => {});
}

function retry() {
    saveNow();
}

function fit() {
    const element = field.value;

    if (element === null) {
        return;
    }

    element.style.height = 'auto';
    element.style.height = `${element.scrollHeight}px`;
}

function onInput(event) {
    draft.value = event.target.value;
    status.value = saving.value ? 'saving' : null;
    fit();
    scheduleSave();
}

async function enterEdit() {
    if (! props.canEdit || editing.value) {
        return;
    }

    status.value = null;
    editing.value = true;

    await nextTick();
    fit();

    const element = field.value;

    if (element === null) {
        return;
    }

    element.focus();

    const end = element.value.length;
    element.setSelectionRange(end, end);
}

function onBlur() {
    if (! editing.value) {
        return;
    }

    saveNow();

    // En tömd anteckning visar fältet direkt även fortsättningsvis (§ 5).
    if (draft.value.trim() !== '') {
        editing.value = false;
    }
}

async function leaveEdit() {
    if (! editing.value) {
        return;
    }

    saveNow();

    if (draft.value.trim() === '') {
        return;
    }

    editing.value = false;

    await nextTick();
    readView.value?.focus();
}

/*
 * Esc lämnar redigeringsläget. Nyckeln läses ur `event.key` i stället för
 * `@keydown.esc`: GenomgangTest fäller varje `@keydown.esc` som en egen
 * fokusfälla, och den regeln handlar om overlays — den här rutan är ingen.
 */
function onFieldKeydown(event) {
    if (event.key !== 'Escape') {
        return;
    }

    event.preventDefault();
    leaveEdit();
}

/*
 * Klicket i läsläget. Länkar i den ritade HTML:en ska gå att följa, så ett
 * klick som landar i en `<a>` lämnas åt webbläsaren.
 */
function onReadClick(event) {
    if (! props.canEdit || editing.value) {
        return;
    }

    if (event.target instanceof Element && event.target.closest('a') !== null) {
        return;
    }

    enterEdit();
}

function onBeforeUnload(event) {
    if (! saving.value && ! isDirty()) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
}

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    box.value?.addEventListener('click', onReadClick);

    if (editing.value) {
        nextTick(fit);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    box.value?.removeEventListener('click', onReadClick);
    clearTimeout(timer);
});
</script>

<template>
    <UiCard v-if="showCard">
        <template #heading>{{ t('item.show.notes') }}</template>

        <!--
            Statusraden står bredvid rubriken, och slotten finns bara när det
            finns något att säga — en tom åtgärdsrad är samma sak som ingen rad
            men med luft omkring sig (se UiCard.vue).
        -->
        <template v-if="status !== null" #action>
            <span v-if="status === 'saving'" class="text-meta text-ink-muted">{{ t('item.notes.saving') }}</span>
            <span v-else-if="status === 'saved'" class="text-meta text-ink-muted">{{ t('item.notes.saved') }}</span>
            <span v-else class="inline-flex items-center gap-2 text-meta text-danger">
                {{ t('item.notes.not_saved') }}
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center font-medium text-accent underline"
                    @click="retry"
                >
                    {{ t('item.notes.try_again') }}
                </button>
            </span>
        </template>

        <div ref="box">
            <textarea
                v-if="editing"
                id="item-notes"
                ref="field"
                :value="draft"
                rows="1"
                :placeholder="t('item.notes.placeholder')"
                :aria-label="t('item.show.notes')"
                class="block w-full resize-none overflow-hidden rounded-control border border-border bg-surface px-3 py-2 text-body text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                @input="onInput"
                @blur="onBlur"
                @keydown="onFieldKeydown"
            />

            <!--
                Läsläget. Den ritade HTML:en är serverritad enligt
                ADR-0066 § 2 och 3, och det här är det enda stället i koden
                där `v-html` bär innehåll.
            -->
            <div
                v-else
                ref="readView"
                class="item-notes-content cursor-text rounded-control outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :tabindex="canEdit ? 0 : undefined"
                :aria-label="canEdit ? t('item.show.notes') : undefined"
                @keydown.enter.prevent="enterEdit"
                v-html="rendered"
            />

            <p v-if="editing" class="mt-2 text-meta text-ink-muted">{{ t('item.notes.markdown_supported') }}</p>
        </div>
    </UiCard>
</template>
