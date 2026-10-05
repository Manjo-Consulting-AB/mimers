<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import FormField from '../../components/FormField.vue';
import ItemTargetPicker from '../../components/ItemTargetPicker.vue';
import UiButton from '../../components/UiButton.vue';
import UiCheckbox from '../../components/UiCheckbox.vue';
import UiInput from '../../components/UiInput.vue';
import UiSelect from '../../components/UiSelect.vue';
import { attachmentPreview, formatByteSize } from '../../components/attachmentPresentation.js';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Sidan `/inbox` — M27 · issue 245, se [[ADR-0054 Inboxen]] § 4, 6, 7 och 8.
 *
 * **Där något fångas och bearbetas.** Sidan ligger i AppLayout, och raden
 * *Inbox* i sidopanelen visar antalet (issue 245 · Beslut 5). Uppgifterna och
 * bilagorna står sida vid sida: det är samma kö, och användaren ska kunna se
 * hela sitt obearbetade innehåll på ett ställe.
 *
 * **Sidan räknar ingenting och filtrerar ingenting.** Uppgifterna kommer
 * färdiga ur App\Actions\Schedule\ListTodo (`list: inbox`, samma urval som
 * fliken *Inbox* på `/tasks`), bilagorna ur App\Http\Resources\
 * AttachmentResource med `variants` bredvid — och talet i sidopanelen ur den
 * delade proppen `inboxCount` (App\Http\Middleware\HandleInertiaRequests).
 * Vyn ritar, formaterar och postar.
 *
 * **Sidan skapar ingen inbox.** Är listorna tomma finns ingen inbox än, och
 * den skapas först av den första fångsten (ADR-0054 § 1). De två tomma lägena
 * säger det utan att nämna ett tal.
 *
 * **Att fånga skickar formuläret och ingenting annat.** Uppgiften är bara en
 * titel (ADR-0054 § 8): schemat blir `none` utan datum, och `CreateSchedule`
 * ser till att en uppgift på ett inbox-item inte får någon lista. Filerna går
 * i en `POST` med `forceFormData`, och servern bestämmer kontot — det är
 * personkontot (ADR-0054 § 3).
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
/* `task` eller `attachments`: vad valet gäller när det kommer tillbaka. */
const pickerMode = ref('task');

const pickerHeading = computed(() => (pickerMode.value === 'task'
    ? t('inbox.page.process_heading')
    : t('inbox.page.move_heading')));

function openPicker(mode, event) {
    pickerMode.value = mode;
    pickerTrigger.value = event.currentTarget;
    pickerOpen.value = true;
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

/* Raderna: storleken formaterad och förhandsvisningen räknad, som i
 * dokumentfliken — `attachmentPreview()` läser `variants` och `inlineEnabled`
 * och vyn gissar ingenting (issue 61b § Beslut 1). */
const attachmentRows = computed(() => props.attachments.map((attachment) => ({
    ...attachment,
    size: formatByteSize(attachment.byte_size),
    preview: attachmentPreview(attachment, props.variants, props.inlineEnabled),
})));

/* Valet ur väljaren. Uppgiften får sitt steg; bilagorna postas direkt. */
function onTargetChosen(item) {
    pickerOpen.value = false;

    if (pickerMode.value === 'task') {
        processTarget.value = item;
        processForm.target = item.ulid;

        return;
    }

    moveForm.target = item.ulid;
    moveForm.attachments = [...selected.value];
    moveForm.post('/inbox/attachments/process', {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
            moveForm.reset();
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('inbox.page.title')" />

        <h1 class="text-2xl font-semibold">{{ t('inbox.page.heading') }}</h1>

        <!-- Fångsten: en uppgift med bara en titel och en filväljare. -->
        <div class="mt-6 flex flex-col gap-6 md:flex-row md:items-start">
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

                <UiButton type="submit" :pending="taskForm.processing">
                    {{ taskForm.processing ? t('common.pending.default') : t('inbox.page.capture.task_submit') }}
                </UiButton>
            </form>

            <form class="flex flex-1 flex-col gap-3" @submit.prevent="captureFiles">
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
                        :aria-describedby="describedBy"
                        class="min-h-11 rounded-control border border-border bg-surface px-3 py-2 text-body text-ink file:mr-3 file:rounded-control file:border-0 file:bg-surface-sunken file:px-3 file:py-1 file:text-ink"
                        @change="onFilesSelected"
                    />
                </FormField>

                <p class="text-meta text-ink-muted">{{ t('inbox.page.capture.files_hint') }}</p>

                <UiButton
                    type="submit"
                    :pending="fileForm.processing"
                    :disabled="selectedFiles.length === 0"
                >
                    {{ fileForm.processing ? t('common.pending.upload') : t('inbox.page.capture.files_submit') }}
                </UiButton>
            </form>
        </div>

        <!-- Uppgifterna: en rad med *Process…*, och steget som följer på valet. -->
        <section class="mt-10">
            <h2 class="text-title font-semibold text-ink">{{ t('inbox.page.tasks_heading') }}</h2>

            <p v-if="tasks.length === 0" class="mt-2 text-body text-ink-muted">
                {{ t('inbox.page.empty_tasks') }}
            </p>

            <ul v-else class="mt-2 flex flex-col">
                <li
                    v-for="task in tasks"
                    :key="task.ulid"
                    class="flex min-h-11 items-center gap-3 border-b border-border py-2"
                >
                    <span class="min-w-0 flex-1 truncate text-body text-ink">
                        {{ task.schedule.title }}
                    </span>

                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center rounded-control px-3 text-meta text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        @click="startProcess(task, $event)"
                    >
                        {{ t('inbox.page.process') }}
                    </button>
                </li>
            </ul>

            <!-- Steget efter målväljaren: listan (förvalt *Next*) och ett
                 frivilligt datum. -->
            <form
                v-if="processingTask && processTarget"
                class="mt-4 flex flex-col gap-3 rounded-card border border-border bg-surface p-4 md:max-w-md"
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

                <p class="text-meta text-ink-muted">{{ t('inbox.page.due_none') }}</p>

                <UiButton type="submit" :pending="processForm.processing">
                    {{ processForm.processing ? t('common.pending.default') : t('inbox.page.process_confirm') }}
                </UiButton>
            </form>
        </section>

        <!-- Bilagorna: en kryssruta var, och *Move selected…* mot samma väljare. -->
        <section class="mt-10">
            <h2 class="text-title font-semibold text-ink">{{ t('inbox.page.attachments_heading') }}</h2>

            <!-- Felet ritas ÖVER listan (ADR-0054 § 6): hela satsen nekas, och
                 meningen säger varför. -->
            <p v-if="moveForm.errors.attachments" class="mt-2 flex flex-col" role="alert">
                <span class="text-body text-danger">{{ moveForm.errors.attachments }}</span>
            </p>

            <p v-if="attachments.length === 0" class="mt-2 text-body text-ink-muted">
                {{ t('inbox.page.empty_attachments') }}
            </p>

            <template v-else>
                <ul class="mt-2 flex flex-col">
                    <li
                        v-for="attachment in attachmentRows"
                        :key="attachment.ulid"
                        class="flex min-h-11 items-center gap-3 border-b border-border py-2"
                    >
                        <UiCheckbox
                            :id="`inbox-attachment-${attachment.ulid}`"
                            v-model="selected"
                            :value="attachment.ulid"
                        >
                            <img
                                v-if="attachment.preview.display === 'thumb'"
                                :src="attachment.preview.thumbnail"
                                alt=""
                                class="size-10 shrink-0 rounded object-cover"
                            />

                            <span class="min-w-0 truncate text-body text-ink">{{ attachment.filename }}</span>
                        </UiCheckbox>

                        <span class="ml-auto text-meta text-ink-muted">{{ attachment.size }}</span>
                    </li>
                </ul>

                <button
                    type="button"
                    :disabled="selected.length === 0"
                    class="mt-4 inline-flex min-h-11 items-center rounded-control border border-border px-4 text-body text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                    @click="openPicker('attachments', $event)"
                >
                    {{ t('inbox.page.move_selected') }}
                </button>
            </template>
        </section>

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
