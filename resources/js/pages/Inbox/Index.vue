<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import FormField from '../../components/FormField.vue';
import ItemTargetPicker from '../../components/ItemTargetPicker.vue';
import UiButton from '../../components/UiButton.vue';
import UiCard from '../../components/UiCard.vue';
import UiCheckbox from '../../components/UiCheckbox.vue';
import UiInput from '../../components/UiInput.vue';
import UiSelect from '../../components/UiSelect.vue';
import UiStat from '../../components/UiStat.vue';
import { attachmentPreview, formatByteSize } from '../../components/attachmentPresentation.js';
import { useRelativeDate } from '../../composables/useRelativeDate.js';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Sidan `/inbox` — M27 · issue 245 och M28 · issue 776 (formen), se
 * [[ADR-0054 Inboxen]] § 4, 6, 7 och 8.
 *
 * **Där något fångas och bearbetas.** Sidan ligger i AppLayout, och raden
 * *Inbox* i sidopanelen visar antalet (issue 245 · Beslut 5). Uppgifterna och
 * bilagorna står i var sitt kort: det är samma kö, och användaren ska kunna se
 * hela sitt obearbetade innehåll på ett ställe.
 *
 * **Sidan räknar ingenting och filtrerar ingenting.** Uppgifterna kommer
 * färdiga ur App\Actions\Schedule\ListTodo (`list: inbox`, samma urval som
 * fliken *Inbox* på `/tasks`), bilagorna ur App\Http\Resources\
 * AttachmentResource med `variants` bredvid — och talet i sidopanelen ur den
 * delade proppen `inboxCount` (App\Http\Middleware\HandleInertiaRequests).
 * Vyn ritar, formaterar och postar. Det gäller brickorna också (issue 776 ·
 * Beslut 3): de tre talen är propparnas längder, och det tredje är summan av
 * de två — sidan ställer ingen fråga för att få dem.
 *
 * **Sidan skapar ingen inbox.** Är listorna tomma finns ingen inbox än, och
 * den skapas först av den första fångsten (ADR-0054 § 1). De två tomma lägena
 * säger det utan att nämna ett tal.
 *
 * **Att fånga skickar formuläret och ingenting annat.** Uppgiften är bara en
 * titel (ADR-0054 § 8): schemat blir `none` utan datum, och `CreateSchedule`
 * ser till att en uppgift på ett inbox-item inte får någon lista. Filerna går
 * i en `POST` med `forceFormData`, och servern bestämmer kontot — det är
 * personkontot (ADR-0054 § 3). Släppytan (issue 776 · Beslut 2) fyller SAMMA
 * fält som väljaren och postar genom samma knapp: ingen ny uppladdningsväg.
 *
 * **Att bearbeta är två steg för en uppgift och ett för bilagorna.** En
 * uppgiftsrad bär *Process…*: den öppnar målväljaren (issue 242) och därefter
 * ett litet steg med listan (förvalt *Next*) och ett frivilligt datum, som
 * postas till `POST /inbox/tasks/{schedule}/process` (issue 244). Bilagorna
 * har en kryssruta var, och *Move selected…* öppnar samma väljare och postar
 * direkt till `POST /inbox/attachments/process` — summan prövas på servern,
 * och antingen flyttas alla eller ingen.
 *
 * **Felet ritas över listan** (ADR-0054 § 6): `attachment.not_in_inbox` och
 * kvotfelet kommer som ett fältfel på `attachments` och står ovanför raderna,
 * aldrig som en rå JSON-kropp mitt i sidan (samma väg som
 * AttachmentController::move()).
 *
 * **Ingen sträng i filen** ([[ADR-0013 Språk och i18n]]): varje ord kommer ur
 * `lang/en/ui.php` genom `t()`.
 */
const props = defineProps({
    /* De obearbetade uppgifterna, som ListTodo-rader (samma form som `/tasks`). */
    tasks: { type: Array, required: true },
    /* Bilagorna, nyast först, ur AttachmentResource. */
    attachments: { type: Array, required: true },
    /* Bilagans ULID → de derivatvarianter som finns. */
    variants: { type: Object, required: true },
    /* Sant när användarfiler levereras från en egen origin (issue 61b). */
    inlineEnabled: { type: Boolean, required: true },
    /* `{ capture }` — om användaren får fånga något. */
    can: { type: Object, required: true },
});

const { t } = useTranslations();
/* Datumregeln (issue 104): raderna nedan skriver ut sina datum genom den och
 * formaterar dem aldrig själva — `/inbox` och `/tasks` ska säga samma sak om
 * samma datum. */
const { dueDate, eventDate } = useRelativeDate();

/* --- att fånga en uppgift -------------------------------------------------- */

const taskForm = useForm({ title: '' });

function captureTask() {
    taskForm.post('/inbox/tasks', {
        preserveScroll: true,
        onSuccess: () => taskForm.reset(),
    });
}

/* --- att fånga filer ------------------------------------------------------- */

const fileInput = ref(null);
const selectedFiles = ref([]);
const fileForm = useForm({ files: [] });

function onFilesSelected(event) {
    selectedFiles.value = Array.from(event.target.files ?? []);
}

/*
 * Släppytan (issue 776 · Beslut 2). Den lägger filerna i SAMMA `ref` som
 * väljaren fyller, och postningen är oförändrad — `Browse files` öppnar
 * webbläsarens egen väljare, och en släppt fil går samma väg.
 */
function onFilesDropped(event) {
    selectedFiles.value = Array.from(event.dataTransfer?.files ?? []);
}

function browseFiles() {
    fileInput.value?.click();
}

function captureFiles() {
    fileForm.files = selectedFiles.value;

    fileForm.post('/inbox/attachments', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            selectedFiles.value = [];
            fileForm.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

/* --- målväljaren, delad av de två bearbetningarna -------------------------- */

const pickerOpen = ref(false);
const pickerTrigger = ref(null);
/* `task`, `file` eller `attachments`: vad valet gäller när det kommer
 * tillbaka. `file` är EN rads *Process…* — samma väljare och samma rutt som
 * satsen, men bara den filen. */
const pickerMode = ref('task');

/* Filen vars *Process…* öppnades (Beslut 4). Satsen läser i stället
 * `selected`, så de två vägarna delar väljare men inte urval. */
const processingAttachment = ref(null);

const pickerHeading = computed(() => (pickerMode.value === 'task'
    ? t('inbox.page.process_heading')
    : t('inbox.page.move_heading')));

function openPicker(mode, event) {
    pickerMode.value = mode;
    pickerTrigger.value = event.currentTarget;
    pickerOpen.value = true;
}

function startProcessFile(attachment, event) {
    processingAttachment.value = attachment;

    openPicker('file', event);
}

/* --- att bearbeta en uppgift ----------------------------------------------- */

/* Raden vars *Process…* öppnades, och målet valet landade i. */
const processingTask = ref(null);
const processTarget = ref(null);

const processForm = useForm({ target: '', gtd_list: 'next', due_at: '' });

function startProcess(task, event) {
    processingTask.value = task;
    processTarget.value = null;
    processForm.reset();
    processForm.clearErrors();

    openPicker('task', event);
}

function submitProcess() {
    processForm.post(`/inbox/tasks/${processingTask.value.schedule.ulid}/process`, {
        preserveScroll: true,
        onSuccess: () => {
            processingTask.value = null;
            processTarget.value = null;
            processForm.reset();
        },
    });
}

/* --- att bearbeta bilagor -------------------------------------------------- */

const selected = ref([]);
const moveForm = useForm({ target: '', attachments: [] });

/* Elementfelen (`attachments.0`, `attachments.1`, …) ur `distinct` och
 * `string` i App\Http\Requests\Inbox\ProcessInboxAttachmentsRequest. Det
 * samlade felet ligger på `attachments` och ritas först; de här kommer bara
 * ur ett handgjort anrop, men listan ska visa dem också i stället för att
 * tiga om vilken rad som var fel. */
const attachmentElementErrors = computed(() => Object.entries(moveForm.errors)
    .filter(([key]) => key.startsWith('attachments.'))
    .map(([, message]) => message));

/* Raderna: storleken formaterad och förhandsvisningen räknad, som i
 * dokumentfliken — `attachmentPreview()` läser `variants` och `inlineEnabled`
 * och vyn gissar ingenting (issue 61b § Beslut 1). Underraden bär typen,
 * storleken och `created_at` genom datumregeln (issue 776 · Beslut 4): *Added*
 * är samma form som uppgiftsradens datum, och ingen av dem är påhittad. */
const attachmentRows = computed(() => props.attachments.map((attachment) => ({
    ...attachment,
    size: formatByteSize(attachment.byte_size),
    preview: attachmentPreview(attachment, props.variants, props.inlineEnabled),
    added: eventDate(attachment.created_at).text,
})));

/*
 * Uppgiftsradens datum (issue 776 · Beslut 4). Raden bär sitt eget datum —
 * `due_at` med serverns `overdue`-flagga — och regeln för hur det skrivs bor
 * i useRelativeDate. Sammansättningen är TodoRows: ett relativt datum bär sin
 * egen preposition och står för sig självt, ett absolut får ordet *Due*
 * framför sig. Utan datum ritas ingen rad alls, och raden visar bara platsen.
 */
function taskDate(task) {
    const due = dueDate(task.due_at, task.overdue);

    if (due.text === null) {
        return null;
    }

    return due.relative ? due.text : t('todo.due', { date: due.text });
}

const taskRows = computed(() => props.tasks.map((task) => ({
    ...task,
    date: taskDate(task),
})));

/* --- de tre brickorna ------------------------------------------------------ */

/*
 * Talen (issue 776 · Beslut 3): uppgifterna, filerna och summan av de två.
 * Den tredje säger *to process* och aldrig *items* — ett item är ett begrepp i
 * Mimers, och en obearbetad post i inboxen är inte ett. Ental har sin egen
 * nyckel av samma skäl som `todo.group_count_one`: `t()` pluraliserar inte.
 */
const taskCount = computed(() => t(
    props.tasks.length === 1 ? 'inbox.page.stats.tasks_one' : 'inbox.page.stats.tasks',
    { count: props.tasks.length },
));

const fileCount = computed(() => t(
    props.attachments.length === 1 ? 'inbox.page.stats.files_one' : 'inbox.page.stats.files',
    { count: props.attachments.length },
));

const totalCount = computed(() => t('inbox.page.stats.total', {
    count: props.tasks.length + props.attachments.length,
}));

/* Valet ur väljaren. Uppgiften får sitt steg; bilagorna postas direkt. */
function onTargetChosen(item) {
    pickerOpen.value = false;

    if (pickerMode.value === 'task') {
        processTarget.value = item;
        processForm.target = item.ulid;

        return;
    }

    const batch = pickerMode.value !== 'file';

    moveForm.target = item.ulid;
    moveForm.attachments = batch
        ? [...selected.value]
        : [processingAttachment.value.ulid];

    moveForm.post('/inbox/attachments/process', {
        preserveScroll: true,
        onSuccess: () => {
            processingAttachment.value = null;

            // Bara satsen tömmer urvalet: en enstaka rads bearbetning får inte
            // kasta ett kryss användaren satt på en annan rad.
            if (batch) {
                selected.value = [];
            }

            moveForm.reset();
        },
    });
}

/* --- att radera ------------------------------------------------------------ */

/*
 * Raderingen (M28 · issue 775 · Beslut 1 och 3) följer de vanliga reglerna för
 * varje objekttyp och har ingen egen modell: uppgiften går till
 * `DELETE /inbox/tasks/{schedule}` och filen till
 * `DELETE /inbox/attachments/{attachment}`, som båda anropar samma actioner som
 * uppgiftens sida respektive bilagans väg (DeleteSchedule och TrashAttachment).
 * *Delete selected* postar samma kropp som *Move selected…* (`attachments[]`)
 * till `POST /inbox/attachments/delete`: hela satsen raderas eller inte alls.
 *
 * **Bekräftelsen är webbläsarens egen dialog** med serverns mening ur `lang/`,
 * precis som *Delete* på uppgiftens sida (Show.vue): ingen modal och ingen
 * sträng i JavaScript. En fråga per rad och en för satsen — satsen kan ta flera
 * filer på en gång, och antalet är det enda som skiljer de två åt.
 *
 * **Felet ritas under listan.** Servern nekar med ett fältfel — `schedule` för
 * en uppgift utanför inboxen, `attachments` för en fil — och `router.delete`
 * har ingen form att hänga det på, så meningen hamnar i en `role="alert"`-rad
 * under den lista den gällde. Utan den raden nekas raderingen i tysthet.
 */
const deletePending = ref(null);
/* Två refs och inte en: felet hör till LISTAN det gällde, och en delad rad hade
 * ritats på fel ställe — ett uppgiftsfel ovanför filerna. */
const taskDeleteError = ref(null);
const attachmentDeleteError = ref(null);

function destroyTask(task) {
    if (! window.confirm(t('inbox.page.delete_confirm'))) {
        return;
    }

    taskDeleteError.value = null;

    router.delete(`/inbox/tasks/${task.schedule.ulid}`, {
        preserveScroll: true,
        onStart: () => { deletePending.value = task.ulid; },
        onFinish: () => { deletePending.value = null; },
        onError: (errors) => {
            taskDeleteError.value = errors.schedule ?? t('inbox.page.delete_error');
        },
    });
}

function destroyAttachment(attachment) {
    if (! window.confirm(t('inbox.page.delete_confirm'))) {
        return;
    }

    attachmentDeleteError.value = null;

    router.delete(`/inbox/attachments/${attachment.ulid}`, {
        preserveScroll: true,
        onStart: () => { deletePending.value = attachment.ulid; },
        onFinish: () => { deletePending.value = null; },
        onError: (errors) => {
            attachmentDeleteError.value = errors.attachment ?? t('inbox.page.delete_error');
        },
    });
}

/* Satsen: samma kropp som flytten, men ingen väljare — filerna försvinner utan
 * mål. Felet hamnar i `deleteForm.errors.attachments`, som ritas i samma
 * summering som flyttens. */
const deleteForm = useForm({ attachments: [] });

function destroySelected() {
    if (! window.confirm(t('inbox.page.delete_selected_confirm'))) {
        return;
    }

    attachmentDeleteError.value = null;
    deleteForm.attachments = [...selected.value];

    deleteForm.post('/inbox/attachments/delete', {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
            deleteForm.reset();
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('inbox.page.title')" />

        <h1 class="text-2xl font-semibold text-ink">{{ t('inbox.page.heading') }}</h1>
        <p class="mt-1 text-body text-ink-muted">{{ t('inbox.page.tagline') }}</p>

        <!--
            Fångstkortet (Beslut 2): två halvor, åtskilda av en lodrät linje
            över `lg:` och staplade under. Kortet har ingen egen rubrik — de
            två etiketterna är halvornas, och de kommer ur FormField.
        -->
        <UiCard class="mt-6">
            <div class="flex flex-col lg:flex-row lg:gap-6 lg:divide-x lg:divide-border">
                <form class="flex flex-1 flex-col gap-3" @submit.prevent="captureTask">
                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('inbox.page.capture.task_label')"
                        id="inbox-task-title"
                        :error="taskForm.errors.title"
                    >
                        <UiInput
                            id="inbox-task-title"
                            v-model="taskForm.title"
                            :described-by="describedBy"
                            :placeholder="t('inbox.page.capture.task_placeholder')"
                        />
                    </FormField>

                    <UiButton type="submit" :pending="taskForm.processing" class="self-start">
                        {{ taskForm.processing ? t('common.pending.default') : t('inbox.page.capture.task_submit') }}
                    </UiButton>
                </form>

                <form class="mt-6 flex flex-1 flex-col gap-3 lg:mt-0" @submit.prevent="captureFiles">
                    <!-- Fältet är dolt för ögat men inte för skärmläsaren:
                         etiketten ovan hör till det, och `Browse files` och
                         släppytan skriver i samma fält. `tabindex="-1"` tar
                         bort det ur tabbordningen — knappen är den väg som
                         syns, och en fokusring på ett osynligt fält är ingen
                         ring. -->
                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('inbox.page.capture.files_label')"
                        id="inbox-files"
                        :error="fileForm.errors.files"
                    >
                        <input
                            id="inbox-files"
                            ref="fileInput"
                            type="file"
                            multiple
                            tabindex="-1"
                            :aria-describedby="describedBy"
                            class="sr-only"
                            @change="onFilesSelected"
                        >
                    </FormField>

                    <div
                        class="flex flex-wrap items-center justify-between gap-3 rounded-card border border-dashed border-border bg-surface-sunken px-4 py-3"
                        @dragover.prevent
                        @drop.prevent="onFilesDropped"
                    >
                        <p class="text-body text-ink-muted">{{ t('inbox.page.capture.files_drop') }}</p>

                        <!-- Rå `<button>`: GenomgangTest tillåter bara
                             `<Link>` bland komponenttaggarna, och en `@click`
                             på en `<UiButton>` är en klickyta provet fäller. -->
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center justify-center rounded-control border border-border bg-surface px-4 text-body text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                            @click="browseFiles"
                        >
                            {{ t('inbox.page.capture.files_browse') }}
                        </button>
                    </div>

                    <p class="text-meta text-ink-muted">{{ t('inbox.page.capture.files_hint') }}</p>

                    <UiButton
                        type="submit"
                        :pending="fileForm.processing"
                        :disabled="selectedFiles.length === 0"
                        class="self-start"
                    >
                        {{ fileForm.processing ? t('common.pending.upload') : t('inbox.page.capture.files_submit') }}
                    </UiButton>
                </form>
            </div>
        </UiCard>

        <!-- De tre brickorna (Beslut 3). -->
        <div class="mt-6 flex flex-wrap gap-4">
            <UiStat :value="taskCount" :label="t('inbox.page.stats.to_process')" />
            <UiStat :value="fileCount" :label="t('inbox.page.stats.to_process')" />
            <UiStat :value="totalCount" :label="t('inbox.page.stats.to_process')" />
        </div>

        <!-- Uppgifterna: en rad med *Process…*, och steget som följer på valet. -->
        <UiCard class="mt-6">
            <template #heading>
                {{ t('inbox.page.tasks_heading', { count: taskRows.length }) }}
            </template>

            <p v-if="taskRows.length === 0" class="text-body text-ink-muted">
                {{ t('inbox.page.empty_tasks') }}
            </p>

            <ul v-else class="flex flex-col divide-y divide-border">
                <li
                    v-for="task in taskRows"
                    :key="task.ulid"
                    class="flex min-h-11 items-center gap-3 py-2"
                >
                    <!-- Cirkeln är dekor: `inbox` har ingen avbockning, och en
                         knapp utan rutt bakom sig är en död yta. Den ritas för
                         att raden ska se ut som uppgiftsraden den blir. -->
                    <span
                        aria-hidden="true"
                        class="h-5 w-5 shrink-0 rounded-full border-2 border-border"
                    />

                    <div class="min-w-0 flex-1">
                        <span class="block truncate text-body text-ink">{{ task.schedule.title }}</span>

                        <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-meta text-ink-muted">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="h-4 w-4 shrink-0"
                                aria-hidden="true"
                            >
                                <rect x="3" y="5" width="18" height="14" rx="2" />
                                <path d="m3 7 9 6 9-6" />
                            </svg>

                            <span>{{ t('todo.location.inbox') }}</span>

                            <template v-if="task.date">
                                <span aria-hidden="true">&middot;</span>
                                <span>{{ task.date }}</span>
                            </template>
                        </span>
                    </div>

                    <button
                        type="button"
                        class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-control bg-accent px-3 text-meta font-medium text-ink-on-accent outline-none hover:bg-accent/90 focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                        @click="startProcess(task, $event)"
                    >
                        {{ t('inbox.page.process') }}
                    </button>

                    <!-- Radens ⋯-meny (Beslut 3): en `<details>` som *Lägg till
                         dokument*-menyn i Documents.vue, med *Delete* i
                         panelen. Uppgiften raderas med samma action som på
                         uppgiftens sida; servern prövar samma grind. -->
                    <details class="relative shrink-0">
                        <summary
                            class="inline-flex min-h-11 cursor-pointer list-none items-center rounded-control px-3 text-meta text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                            :aria-label="t('inbox.page.row_menu')"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="currentColor"
                                class="size-5 shrink-0"
                                aria-hidden="true"
                            >
                                <circle cx="12" cy="5" r="1.6" />
                                <circle cx="12" cy="12" r="1.6" />
                                <circle cx="12" cy="19" r="1.6" />
                            </svg>
                        </summary>

                        <div class="absolute right-0 z-10 mt-2 w-40 rounded-card border border-border bg-surface p-1 shadow-sm">
                            <button
                                type="button"
                                :disabled="deletePending !== null"
                                class="flex min-h-11 w-full items-center rounded-control px-3 text-left text-body text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                                @click="destroyTask(task)"
                            >
                                {{ t('inbox.page.delete') }}
                            </button>
                        </div>
                    </details>
                </li>
            </ul>

            <!-- Ett domänfel ur bearbetningen är ett formulärfel och ritas
                 ÖVER steget (ADR-0054 § 6): `schedule.*` för ett mål som inte
                 går att flytta till, `target` för ett ogiltigt mål. Utan den
                 här raden nekas bearbetningen tyst — felet hamnade på ett fält
                 vyn inte läste. Raderingen av en uppgift ritar sin nekande
                 mening här, av samma skäl. -->
            <p
                v-if="processForm.errors.schedule || processForm.errors.target || taskDeleteError"
                class="mt-4 flex flex-col"
                role="alert"
            >
                <span v-if="processForm.errors.schedule" class="text-body text-danger">
                    {{ processForm.errors.schedule }}
                </span>
                <span v-if="processForm.errors.target" class="text-body text-danger">
                    {{ processForm.errors.target }}
                </span>
                <span v-if="taskDeleteError" class="text-body text-danger">
                    {{ taskDeleteError }}
                </span>
            </p>

            <!-- Steget efter målväljaren: listan (förvalt *Next*) och ett
                 frivilligt datum. -->
            <form
                v-if="processingTask && processTarget"
                class="mt-4 flex flex-col gap-3 rounded-card border border-border bg-surface-sunken p-4 md:max-w-md"
                @submit.prevent="submitProcess"
            >
                <p class="text-body text-ink">
                    {{ t('inbox.page.process_heading') }}
                    <strong class="font-semibold">{{ processTarget.name }}</strong>
                </p>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('inbox.page.list_label')"
                    id="inbox-process-list"
                    :error="processForm.errors.gtd_list"
                >
                    <UiSelect
                        id="inbox-process-list"
                        v-model="processForm.gtd_list"
                        :described-by="describedBy"
                    >
                        <option value="next">{{ t('todo.list.next') }}</option>
                        <option value="waiting">{{ t('todo.list.waiting') }}</option>
                        <option value="someday">{{ t('todo.list.someday') }}</option>
                    </UiSelect>
                </FormField>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('inbox.page.due_label')"
                    id="inbox-process-due"
                    :error="processForm.errors.due_at"
                >
                    <UiInput
                        id="inbox-process-due"
                        v-model="processForm.due_at"
                        type="date"
                        :described-by="describedBy"
                    />
                </FormField>

                <!-- Bara när datumet är tomt: en permanent *No date* under ett
                     valt datum är en motsägelse. -->
                <p v-if="!processForm.due_at" class="text-meta text-ink-muted">
                    {{ t('inbox.page.due_none') }}
                </p>

                <UiButton type="submit" :pending="processForm.processing" class="self-start">
                    {{ processForm.processing ? t('common.pending.default') : t('inbox.page.process_confirm') }}
                </UiButton>
            </form>
        </UiCard>

        <!-- Bilagorna: en kryssruta var, och *Move selected…* mot samma väljare. -->
        <UiCard class="mt-6">
            <template #heading>
                {{ t('inbox.page.attachments_heading', { count: attachmentRows.length }) }}
            </template>

            <template #action>
                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        :disabled="selected.length === 0"
                        class="inline-flex min-h-11 items-center rounded-control border border-border px-4 text-body text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                        @click="openPicker('attachments', $event)"
                    >
                        {{ t('inbox.page.move_selected') }}
                    </button>

                    <!-- *Delete selected* (Beslut 3): bredvid *Move selected…*
                         och inaktiv när ingen fil är markerad. Satsen raderas
                         eller inte alls — servern prövar hela listan först. -->
                    <button
                        type="button"
                        :disabled="selected.length === 0 || deleteForm.processing"
                        class="inline-flex min-h-11 items-center rounded-control border border-border px-4 text-body text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                        @click="destroySelected"
                    >
                        {{ deleteForm.processing ? t('common.pending.default') : t('inbox.page.delete_selected') }}
                    </button>
                </div>
            </template>

            <!-- Felet ritas ÖVER listan (ADR-0054 § 6): hela satsen nekas, och
                 meningen säger varför. Elementfelen (`attachments.N`) hör hit
                 de med. -->
            <p
                v-if="moveForm.errors.attachments || attachmentElementErrors.length > 0 || deleteForm.errors.attachments || attachmentDeleteError"
                class="mb-3 flex flex-col"
                role="alert"
            >
                <span v-if="moveForm.errors.attachments" class="text-body text-danger">
                    {{ moveForm.errors.attachments }}
                </span>
                <span
                    v-for="(message, index) in attachmentElementErrors"
                    :key="index"
                    class="text-body text-danger"
                >
                    {{ message }}
                </span>
                <span v-if="deleteForm.errors.attachments" class="text-body text-danger">
                    {{ deleteForm.errors.attachments }}
                </span>
                <span v-if="attachmentDeleteError" class="text-body text-danger">
                    {{ attachmentDeleteError }}
                </span>
            </p>

            <p v-if="attachmentRows.length === 0" class="text-body text-ink-muted">
                {{ t('inbox.page.empty_attachments') }}
            </p>

            <ul v-else class="flex flex-col divide-y divide-border">
                <li
                    v-for="attachment in attachmentRows"
                    :key="attachment.ulid"
                    class="flex min-h-11 items-center gap-3 py-2"
                >
                    <UiCheckbox
                        :id="`inbox-attachment-${attachment.ulid}`"
                        v-model="selected"
                        :value="attachment.ulid"
                        class="min-w-0 flex-1"
                    >
                        <img
                            v-if="attachment.preview.display === 'thumb'"
                            :src="attachment.preview.thumbnail"
                            alt=""
                            class="h-10 w-10 shrink-0 rounded object-cover"
                        >

                        <!-- Utan derivat ritas samma neutrala filikon som
                             dokumentfliken: en `<img>` mot `?variant=thumb` på
                             en fil utan derivat är en trasig bild (61b §
                             Beslut 1). -->
                        <span
                            v-else
                            aria-hidden="true"
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-surface-sunken text-ink-muted"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="h-5 w-5"
                            >
                                <path d="M14 3v5h5" />
                                <path d="M6 3h8l5 5v13H6z" />
                            </svg>
                        </span>

                        <span class="flex min-w-0 flex-col">
                            <span class="truncate text-body text-ink">{{ attachment.filename }}</span>

                            <span class="flex flex-wrap items-center gap-1.5 text-meta text-ink-muted">
                                <span>{{ t(`item.attachment.kind.${attachment.kind}`) }}</span>

                                <template v-if="attachment.size">
                                    <span aria-hidden="true">&middot;</span>
                                    <span>{{ attachment.size }}</span>
                                </template>

                                <template v-if="attachment.added">
                                    <span aria-hidden="true">&middot;</span>
                                    <span>{{ t('inbox.page.added', { date: attachment.added }) }}</span>
                                </template>
                            </span>
                        </span>
                    </UiCheckbox>

                    <button
                        type="button"
                        class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-control bg-accent px-3 text-meta font-medium text-ink-on-accent outline-none hover:bg-accent/90 focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                        @click="startProcessFile(attachment, $event)"
                    >
                        {{ t('inbox.page.process') }}
                    </button>

                    <!-- Filradens ⋯-meny (Beslut 3), samma `<details>`
                         som uppgiftsradens ovan. Filen raderas med
                         TrashAttachment: papperskorg och fördröjd
                         radering som överallt annars. -->
                    <details class="relative shrink-0">
                        <summary
                            class="inline-flex min-h-11 cursor-pointer list-none items-center rounded-control px-3 text-meta text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                            :aria-label="t('inbox.page.row_menu')"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="currentColor"
                                class="size-5 shrink-0"
                                aria-hidden="true"
                            >
                                <circle cx="12" cy="5" r="1.6" />
                                <circle cx="12" cy="12" r="1.6" />
                                <circle cx="12" cy="19" r="1.6" />
                            </svg>
                        </summary>

                        <div class="absolute right-0 z-10 mt-2 w-40 rounded-card border border-border bg-surface p-1 shadow-sm">
                            <button
                                type="button"
                                :disabled="deletePending !== null"
                                class="flex min-h-11 w-full items-center rounded-control px-3 text-left text-body text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                                @click="destroyAttachment(attachment)"
                            >
                                {{ t('inbox.page.delete') }}
                            </button>
                        </div>
                    </details>
                </li>
            </ul>
        </UiCard>

        <!-- Målväljaren, en och samma för båda bearbetningarna (issue 242). -->
        <ItemTargetPicker
            :open="pickerOpen"
            :trigger="pickerTrigger"
            :heading="pickerHeading"
            @choose="onTargetChosen"
            @close="pickerOpen = false"
        />
    </AppLayout>
</template>
