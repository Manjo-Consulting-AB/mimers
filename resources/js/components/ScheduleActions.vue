<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import ItemTargetPicker from './ItemTargetPicker.vue';
import UiConfirmDialog from './UiConfirmDialog.vue';
import { scheduleUrl } from './occurrencePresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Uppgiftens meny, se issue 1159 (M53). Samma rad av länkar och knappar står
 * på schemats sida (`context="show"`) och i redigeringen (`context="edit"`);
 * den bröts ut ur Schedules/Show.vue så att de två sidorna inte får två
 * kopior att hålla lika.
 *
 * - `show`: *Back*, *Edit*, *Pause*, *Move…*, *Back to Inbox*, *Delete*.
 * - `edit`: *Back*, *Back to the task*, *Pause*, *Move…*, *Back to Inbox*,
 *   *Delete*. Ingen *Edit* — man står redan där.
 *
 * Handlingarnas beteende och adresser är de som stod i Show.vue, inklusive
 * `return`-hanteringen från issue 1158. Råa `<button>` och `<Link>` är kvar:
 * GenomgangTest tillåter bara dem som klickyta.
 *
 * **Osparade ändringar.** När `dirty` är sann öppnas först
 * `UiConfirmDialog` med `inline_create.discard.*` (issue 356), samma fråga
 * som InlineCreate ställer. *Confirm* fortsätter med handlingen, *Cancel*
 * gör ingenting. För en länk förhindras navigeringen (fångstfasen, så att
 * Inertias egen klickhanterare ser `defaultPrevented`) och `router.visit`
 * körs efter bekräftelsen. *Delete* behåller sin `window.confirm` efter
 * frågan. Utan osparade ändringar körs allt direkt.
 */
const props = defineProps({
    container: { type: Object, required: true },
    item: { type: Object, required: true },
    schedule: { type: Object, required: true },
    can: { type: Object, required: true },
    /*
     * Sant när schemat ligger på ett inbox-item (M27 · issue 244, ADR-0054
     * § 1). *Back to Inbox* ritas då inte: uppgiften är redan där, och rutten
     * hade svarat 422 `schedule.already_in_inbox`.
     */
    containerIsInbox: { type: Boolean, default: false },
    /* Den prövade returadressen och tillbakaadressen (issue 1158). */
    returnTo: { type: String, default: null },
    back: { type: String, required: true },
    /* `show` eller `edit`. */
    context: {
        type: String,
        default: 'show',
        validator: (value) => ['show', 'edit'].includes(value),
    },
    /* Sant när formuläret har osparade ändringar. */
    dirty: { type: Boolean, default: false },
});

const { t } = useTranslations();

const pending = ref(false);

const scheduleActionUrl = scheduleUrl(props.container.ulid, props.item.ulid, props.schedule.ulid);

const returnQuery = props.returnTo !== null ? `?return=${encodeURIComponent(props.returnTo)}` : '';

/* *Edit* bär returadressen vidare (issue 1158). */
const editHref = `${scheduleActionUrl}/edit${returnQuery}`;

/* *Back to the task* leder till uppgiftens sida och bär samma adress. */
const taskHref = `${scheduleActionUrl}${returnQuery}`;

/*
 * Frågan vid osparade ändringar. `ask(run, trigger)` kör `run` direkt när
 * inget är osparat; annars sparas den och dialogen öppnas.
 */
const confirming = ref(false);
const dialogTrigger = ref(null);
let afterConfirm = null;

function ask(run, trigger = null) {
    if (! props.dirty) {
        run();

        return;
    }

    afterConfirm = run;
    dialogTrigger.value = trigger;
    confirming.value = true;
}

function confirmed() {
    const run = afterConfirm;

    afterConfirm = null;
    confirming.value = false;
    run?.();
}

function cancelled() {
    afterConfirm = null;
    confirming.value = false;
}

/*
 * En länk i menyn. Med osparade ändringar stoppas klicket i fångstfasen och
 * navigeringen görs efter bekräftelsen; ett klick med modifierartangent
 * (ny flik) lämnas orört, det kastar ingenting.
 */
function guardLink(event, href) {
    if (! props.dirty || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0) {
        return;
    }

    event.preventDefault();
    ask(() => router.visit(href), event.currentTarget);
}

/*
 * Regelns egna två handlingar (issue 720 § Beslut 2): pausen och raderingen.
 * Båda anropar samma rutt som redigeringen — PATCH med bara `is_active` för
 * pausen, DELETE för raderingen — och servern svarar med en omdirigering.
 * `pending` är vänteläget; `onFinish` nollställer den även vid fel.
 * Bekräftelsen vid radering är webbläsarens egen dialog med serverns mening
 * ur `lang/`.
 */
function toggle() {
    router.patch(scheduleActionUrl, {
        is_active: ! props.schedule.is_active,
        ...(props.returnTo !== null ? { return: props.returnTo } : {}),
    }, {
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

function destroy() {
    if (! window.confirm(t('item.schedule.destroy_confirm'))) {
        return;
    }

    router.delete(scheduleActionUrl, {
        data: props.returnTo !== null ? { return: props.returnTo } : {},
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/*
 * Flytten (issue 243 · [[ADR-0053 Flytt och kopiering]] § 6). Uppgiften
 * flyttas men kopieras aldrig. Knappen står bakom `can.delete`, som
 * raderingen: en flytt tar bort något från källan. Väljaren (issue 242)
 * lämnar målet; svaret går till schemats sida på det NYA itemet. Domänfelet
 * — en uppgift med beroenden kan flyttas inom containern men inte till en
 * annan — kommer som ett fältfel på `schedule` och ritas under knappen.
 */
const pickerOpen = ref(false);
const pickerTrigger = ref(null);
const moveError = ref(null);

function openPicker(trigger) {
    pickerTrigger.value = trigger;
    moveError.value = null;
    pickerOpen.value = true;
}

function closePicker() {
    pickerOpen.value = false;
}

function chooseTarget(target) {
    router.post(`${scheduleActionUrl}/move`, { target: target.ulid }, {
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
        onError: (errors) => { moveError.value = errors.schedule ?? null; },
        onHttpException: (response) => {
            moveError.value = response.status === 403 ? t('error.403') : t('error.generic');

            return false;
        },
    });
}

/*
 * *Back to Inbox* (M27 · issue 244, [[ADR-0054 Inboxen]] § 6). En egen
 * handling och inte ett val i väljaren: den går alltid till användarens EGEN
 * inbox, och den skapas om den saknas. Kroppen är tom. Domänfelet
 * (`schedule.has_dependencies`, `schedule.already_in_inbox`) kommer som ett
 * fältfel på `schedule` och ritas i samma ruta som flyttens.
 */
function toInbox() {
    router.post(`${scheduleActionUrl}/inbox`, {}, {
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
        onError: (errors) => { moveError.value = errors.schedule ?? null; },
        onHttpException: (response) => {
            moveError.value = response.status === 403 ? t('error.403') : t('error.generic');

            return false;
        },
    });
}
</script>

<template>
    <div class="mt-4 flex flex-wrap gap-4 text-sm">
        <Link
            :href="back"
            class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
            @click.capture="guardLink($event, back)"
        >
            {{ t('item.schedule.back_to_list') }}
        </Link>

        <Link
            v-if="context === 'show' && can.update"
            :href="editHref"
            class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
            @click.capture="guardLink($event, editHref)"
        >
            {{ t('item.schedule.edit') }}
        </Link>

        <Link
            v-if="context === 'edit'"
            :href="taskHref"
            class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
            @click.capture="guardLink($event, taskHref)"
        >
            {{ t('item.schedule.back_to_task') }}
        </Link>

        <button
            v-if="can.update"
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
            @click="ask(toggle, $event.currentTarget)"
        >
            {{ pending ? t('common.pending.default') : (schedule.is_active ? t('item.schedule.pause') : t('item.schedule.resume')) }}
        </button>

        <!-- Flytten (issue 243 · [[ADR-0053 Flytt och kopiering]] § 6):
             bakom `can.delete`, som raderingen intill — en flytt tar bort
             något från källan. Servern prövar samma grind på nytt. -->
        <button
            v-if="can.delete"
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
            @click="ask(() => openPicker($event.currentTarget), $event.currentTarget)"
        >
            {{ t('item.schedule.move') }}
        </button>

        <!-- *Back to Inbox* (M27 · issue 244, ADR-0054 § 6): bredvid
             *Move…* och bakom samma `can.delete`. Ritas inte när uppgiften
             REDAN ligger i inboxen (`containerIsInbox`): rutten hade svarat
             422 `schedule.already_in_inbox`. -->
        <button
            v-if="can.delete && !containerIsInbox"
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
            @click="ask(toInbox, $event.currentTarget)"
        >
            {{ t('todo.back_to_inbox') }}
        </button>

        <button
            v-if="can.delete"
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center font-medium text-red-700 hover:underline"
            @click="ask(destroy, $event.currentTarget)"
        >
            {{ pending ? t('common.pending.default') : t('item.schedule.destroy') }}
        </button>
    </div>

    <!-- Flyttens och *Back to Inbox*:s fel (issue 243 och 244):
         `schedule.has_dependencies` när målet ligger i en annan container
         och uppgiften har beroenden, eller en 403:a ur grinden. Det står
         under knapparna, och sidan står kvar. -->
    <p v-if="moveError" role="alert" class="mt-2 text-sm text-red-700">
        {{ moveError }}
    </p>

    <!-- Målväljaren (issue 242 och 243): `excludeItem` är itemet uppgiften
         står på — att flytta den till sig själv är inget mål. -->
    <ItemTargetPicker
        :open="pickerOpen"
        :trigger="pickerTrigger"
        :exclude-item="item.ulid"
        :heading="t('item.schedule.move_heading')"
        @choose="chooseTarget"
        @close="closePicker"
    />

    <UiConfirmDialog
        :open="confirming"
        :heading="t('inline_create.discard.heading')"
        :cancel-label="t('inline_create.discard.cancel')"
        :confirm-label="t('inline_create.discard.confirm')"
        :trigger="dialogTrigger"
        @confirm="confirmed"
        @cancel="cancelled"
    />
</template>
