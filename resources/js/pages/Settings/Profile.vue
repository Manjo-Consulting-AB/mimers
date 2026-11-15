<script setup>
import { computed, ref, useId, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import EmailChangeForm from '../../components/EmailChangeForm.vue';
import FormField from '../../components/FormField.vue';
import UiSheet from '../../components/UiSheet.vue';
import UserAvatar from '../../components/UserAvatar.vue';
import { avatarDragFocus, avatarFocusRange, avatarNudgeFocus } from '../../composables/useAvatarCrop.js';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from '../Auth/useErrorFocus.js';

defineOptions({ layout: [AppLayout, SettingsLayout] });

/*
 * Profilsidan, se issue 53c § Beslut 2 och 6.
 *
 * ETT formulär och EN PATCH mot /settings/profile. Serverns fel går genom
 * FormField, precis som på inloggningen och säkerhetssidan.
 *
 * De tre inställningarna har var sitt förstaval i formen "Följ kontots språk
 * (svenska)". Det valet postar `null`, och `null` är hela innebörden av
 * kolumnen: användarens värde ÅSIDOSÄTTER kontots, det ärver det när det är
 * tomt ([[Konton och åtkomst]] § user och User::preferredLocale()). Vyn
 * hittar aldrig på ett kontovärde: parentesen kommer ur `accountDefaults`,
 * som servern skickar, och när den är null — användaren är medlem i flera
 * konton och värdet går inte att avgöra — står valet utan parentes.
 *
 * E-postadressen har ett eget formulär sedan issue 130. Adressen visas med
 * sin verifieringsstatus i namnformuläret, och bytet begärs i
 * resources/js/components/EmailChangeForm.vue, som postar till en EGEN rutt
 * (`POST /settings/profile/email`) — adressen byts aldrig i samma steg som
 * den begärs, och den här sidans PATCH tar fortfarande inte emot `email`.
 * Vyn skickar bara vidare vad servern redan bestämt: `hasPassword` och
 * `totpEnabled` är kontots tillstånd, och formuläret ritar fälten efter dem.
 *
 * Tidszonslistan kommer som prop från kontrollern, samma lista som validerar
 * (`DateTimeZone::listIdentifiers()`). Ingen datafil i resources/js/: två
 * listor blir två sanningar.
 */
const props = defineProps({
    name: { type: String, required: true },
    email: { type: String, required: true },
    emailVerifiedAt: { type: String, default: null },

    // `userLocale`, inte `locale`: den delade propen `locale` bär
    // språkKATALOGEN (`sv`/`en`) och skickas till varje sida — en sidprop med
    // samma namn skuggar den. Se ProfileController::edit().
    userLocale: { type: String, default: null },
    userTimezone: { type: String, default: null },
    userUnitSystem: { type: String, default: null },

    // E-postformulärets två lägen, se issue 130: ett konto utan lösenord kan
    // inte begära ett byte, och ett konto med bekräftad tvåfaktor behöver
    // koden. Samma proppar och samma namn som säkerhetssidan ger
    // lösenordsformuläret.
    hasPassword: { type: Boolean, required: true },
    totpEnabled: { type: Boolean, required: true },

    timezones: { type: Array, required: true },
    accountDefaults: { type: Object, default: null },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const page = usePage();

/*
 * Profilbilden, se [[ADR-0076 Profilbilden]] § 6 och 7 och [[M59
 * Profilbilden]] § 381 och § 382.
 *
 * **Bilden läses ur den delade proppen, inte ur en sidpropp.** `avatar` är
 * `{ ulid, has_thumb, focus, zoom }` eller null och kommer ur
 * AuthUserResource (issue 380 och 382); kontrollern för profilsidan skickar
 * ingenting eget, och efter en uppladdning svarar servern `back()` med färska
 * proppar, så cirkeln byter bild utan att vyn laddar om något själv.
 *
 * **Ett val postas direkt.** Filväljaren är den enda kontrollen för
 * uppladdningen — ingen extra knapp — och `forceFormData` är det som gör
 * filen till en multipart-kropp. Formen är ContainerCoverSheets
 * (resources/js/components/ContainerCoverSheet.vue), som är förlagan både
 * för anropet och för felraden.
 *
 * **Fältfelet `file` ritas under avsnittet.** Kvoten, en fil som inte är en
 * bild och `avatar.no_personal_account` kommer från servern som ett
 * valideringsfel på `file`, redan översatt (ApiErrorTranslator). Vyn skriver
 * ingen egen mening för dem.
 */
const avatar = computed(() => page.props.auth.user.avatar ?? null);

/* Hela personen, till `UserAvatar` — den läser `name` och `avatar`. */
const currentUser = computed(() => page.props.auth.user);

const uploading = ref(false);
const removing = ref(false);
const avatarError = ref(null);

const busy = computed(() => uploading.value || removing.value);

function onFileSelected(event) {
    const file = event.target.files?.[0] ?? null;

    // Fältet töms så att SAMMA fil går att välja igen efter ett fel, samma
    // skäl som i ContainerCoverSheet.
    event.target.value = '';

    if (file !== null) {
        uploadAvatar(file);
    }
}

function uploadAvatar(file) {
    avatarError.value = null;

    router.post('/settings/profile/avatar', { file }, {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => {
            uploading.value = true;
        },
        onFinish: () => {
            uploading.value = false;
        },
        onError: (errors) => {
            avatarError.value = errors.file ?? null;
        },
    });
}

/*
 * Ingen bekräftelse: bilden laddas lätt upp igen ([[ADR-0047 Containerns
 * bild]] § Beslut, samma linje som för containerns bild).
 */
function removeAvatar() {
    avatarError.value = null;

    router.delete('/settings/profile/avatar', {
        preserveScroll: true,
        onStart: () => {
            removing.value = true;
        },
        onFinish: () => {
            removing.value = false;
        },
    });
}

/*
 * Redigeraren för utsnittet, se [[ADR-0076 Profilbilden]] § 7.
 *
 * **Samma ark som containerns bild** (resources/js/components/UiSheet.vue):
 * fokusfällan, Esc, trycket utanför och rubriken bor där. Här bor ytan,
 * reglaget och de tre vägarna ut.
 *
 * **Ytan är kvadratisk med en rund mask.** `UserAvatar` ritar utsnittet i en
 * fyrkant (`shape="square"`) med `?variant=medium` — samma räkning som
 * avataren, så det man ser i masken är exakt vad avataren visar — och masken
 * ovanpå mörklägger hörnen utanför cirkeln.
 *
 * **Punkten flyttas med pekaren och med piltangenterna.** Båda utgår från den
 * EFFEKTIVA punkten — den som faktiskt syns — och klamras till det intervall
 * som går att nå vid aktuell zoom (useAvatarCrop.js § avatarFocusRange). Utan
 * det hade ett sparat värde nära kanten först ätit upp ett dött område där
 * bilden står stilla medan talet rör sig, och nästa zoom hade hoppat till en
 * punkt personen aldrig såg. Dragningen mäts i bildpunkter mot bildens RITADE
 * bredd, inte mot rutan: vid zoom är bilden bredare än rutan, och en fast
 * procent per pixel hade gjort draget olika känsligt vid olika zoom.
 * Piltangenterna flyttar 2 procent, 10 med Shift; zoomen klamrar om punkten
 * när den ändras, så utkastet alltid är nåbart.
 *
 * **Allt är lokalt till dess att Save trycks.** Reset sätter mitten och
 * 100 %, Cancel stänger utan att skriva — arket öppnas alltid med de sparade
 * värdena, så en avbruten redigering lämnar ingenting efter sig.
 */
const EDITOR_SIZE = 256;

const uid = useId();
const zoomId = `avatar-zoom-${uid}`;

const editorOpen = ref(false);
const editorTrigger = ref(null);
const editorFocus = ref({ x: 50, y: 50 });
const editorZoom = ref(100);
const editorNatural = ref({ width: 0, height: 0 });
const editorPending = ref(false);
const editorError = ref(null);

/* Personen som `UserAvatar` ritar i redigeraren: sparade värden + utkastet. */
const editorUser = computed(() => ({
    name: currentUser.value?.name ?? '',
    avatar: avatar.value === null
        ? null
        : {
            ...avatar.value,
            focus: editorFocus.value,
            zoom: editorZoom.value,
        },
}));

/* Bilden har laddats: först då går det att dra i den. */
const editorReady = computed(() => editorNatural.value.width > 0 && editorNatural.value.height > 0);

/*
 * Maskens stil: en cirkel i mitten lämnas genomskinlig, resten av ytan bär
 * den mörka tonen — hörnen utanför avataren mörkläggs, och det som syns i
 * cirkeln är exakt vad avataren visar. En mask och ingen `box-shadow`: skuggan
 * är en roll (ADR-0057 § 6), och den här konturen är ingen skugga. Ingen SVG
 * heller — ikoner går genom `UiIcon` (ADR-0057 § 2), och masken är ingen ikon.
 */
const maskStyle = {
    maskImage: 'radial-gradient(circle at center, transparent 0 50%, black 50%)',
    WebkitMaskImage: 'radial-gradient(circle at center, transparent 0 50%, black 50%)',
};

function openEditor(event) {
    editorTrigger.value = event.currentTarget ?? null;
    editorFocus.value = { ...(avatar.value?.focus ?? { x: 50, y: 50 }) };
    editorZoom.value = avatar.value?.zoom ?? 100;
    editorError.value = null;
    editorOpen.value = true;
}

function closeEditor() {
    editorOpen.value = false;
}

function onEditorImageLoad(matt) {
    editorNatural.value = matt;
}

/*
 * Zoomen ändras: punkten klamras om till det intervall som går att nå vid
 * den nya zoomen. Utan det kunde utkastet peka på en punkt som bilden inte
 * längre kan visa, och nästa drag hade börjat i ett dött område. Steget är
 * noll — samma räkning som pilarna, bara utan rörelse.
 */
watch(editorZoom, () => {
    if (!editorReady.value) {
        return;
    }

    editorFocus.value = avatarNudgeFocus({
        imageWidth: editorNatural.value.width,
        imageHeight: editorNatural.value.height,
        box: EDITOR_SIZE,
        focusX: editorFocus.value.x,
        focusY: editorFocus.value.y,
        zoom: editorZoom.value,
    });
});

const dragging = ref(false);
const dragStart = ref(null);

function startDrag(event) {
    if (!editorReady.value) {
        return;
    }

    event.preventDefault();

    // Utgångsläget är den punkt som SYNS, inte det sparade talet.
    const bounds = avatarFocusRange({
        imageWidth: editorNatural.value.width,
        imageHeight: editorNatural.value.height,
        box: EDITOR_SIZE,
        focusX: editorFocus.value.x,
        focusY: editorFocus.value.y,
        zoom: editorZoom.value,
    });

    dragging.value = true;
    dragStart.value = {
        x: event.clientX,
        y: event.clientY,
        focusX: bounds.focusX,
        focusY: bounds.focusY,
    };

    event.currentTarget.setPointerCapture?.(event.pointerId);
}

function dragMove(event) {
    if (!dragging.value || dragStart.value === null) {
        return;
    }

    const start = dragStart.value;

    editorFocus.value = avatarDragFocus({
        imageWidth: editorNatural.value.width,
        imageHeight: editorNatural.value.height,
        box: EDITOR_SIZE,
        focusX: start.focusX,
        focusY: start.focusY,
        zoom: editorZoom.value,
        deltaX: event.clientX - start.x,
        deltaY: event.clientY - start.y,
    });
}

function endDrag(event) {
    if (!dragging.value) {
        return;
    }

    dragging.value = false;
    dragStart.value = null;

    if (event.currentTarget.hasPointerCapture?.(event.pointerId)) {
        event.currentTarget.releasePointerCapture(event.pointerId);
    }
}

function onEditorKeydown(event) {
    const step = event.shiftKey ? 10 : 2;
    let stepX = 0;
    let stepY = 0;

    if (event.key === 'ArrowLeft') {
        stepX = -step;
    } else if (event.key === 'ArrowRight') {
        stepX = step;
    } else if (event.key === 'ArrowUp') {
        stepY = -step;
    } else if (event.key === 'ArrowDown') {
        stepY = step;
    } else {
        return;
    }

    event.preventDefault();

    editorFocus.value = avatarNudgeFocus({
        imageWidth: editorNatural.value.width,
        imageHeight: editorNatural.value.height,
        box: EDITOR_SIZE,
        focusX: editorFocus.value.x,
        focusY: editorFocus.value.y,
        zoom: editorZoom.value,
        stepX,
        stepY,
    });
}

function resetCrop() {
    editorFocus.value = { x: 50, y: 50 };
    editorZoom.value = 100;
}

function saveCrop() {
    editorError.value = null;

    router.patch('/settings/profile/avatar/crop', {
        x: editorFocus.value.x,
        y: editorFocus.value.y,
        zoom: editorZoom.value,
    }, {
        preserveScroll: true,
        onStart: () => {
            editorPending.value = true;
        },
        onFinish: () => {
            editorPending.value = false;
        },
        onSuccess: () => {
            closeEditor();
        },
        onError: (errors) => {
            editorError.value = errors.x ?? errors.y ?? errors.zoom ?? null;
        },
    });
}

const form = useForm({
    name: props.name,
    locale: props.userLocale,
    timezone: props.userTimezone,
    unit_system: props.userUnitSystem,
});

const locales = computed(() => ['sv_SE', 'en_GB']);
const units = computed(() => ['metric', 'imperial']);

/*
 * Förstavalens etiketter. Den namngivna formen bär kontots gällande värde i
 * parentesen och används bara när servern kunde avgöra det; annars den korta
 * formen. Översättningen av det inbäddade värdet går genom samma `t()` som
 * resten — `settings.locales.sv_SE` är "svenska", `settings.units.metric` är
 * "metriskt". Tidszonen är ett IANA-namn och översätts inte.
 */
const localeFollowLabel = computed(() => (props.accountDefaults?.locale
    ? t('settings.profile.locale_follow', { account: t(`settings.locales.${props.accountDefaults.locale}`) })
    : t('settings.profile.locale_follow_plain')));

const timezoneFollowLabel = computed(() => (props.accountDefaults?.timezone
    ? t('settings.profile.timezone_follow', { timezone: props.accountDefaults.timezone })
    : t('settings.profile.timezone_follow_plain')));

const unitFollowLabel = computed(() => (props.accountDefaults?.unitSystem
    ? t('settings.profile.unit_follow', { unit: t(`settings.units.${props.accountDefaults.unitSystem}`) })
    : t('settings.profile.unit_follow_plain')));

function submit() {
    form.patch('/settings/profile', { onError: focusFirstError });
}
</script>

<template>
    <Head :title="t('settings.profile.title')" />

    <h1 class="text-2xl font-semibold">{{ t('settings.profile.heading') }}</h1>

    <form class="mt-8 flex max-w-lg flex-col gap-4" @submit.prevent="submit">
        <FormField
            v-slot="{ describedBy }"
            :label="t('settings.profile.name')"
            id="name"
            :error="form.errors.name"
        >
            <input
                id="name"
                v-model="form.name"
                :aria-describedby="describedBy"
                type="text"
                name="name"
                autocomplete="name"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <!-- E-postadressen: visad här, bytt i sitt eget formulär (issue
             130). Ingen FormField — den här PATCH:en tar inte emot
             fältet, och ett fält här hade sett ut som att det bytte
             adressen i samma steg. -->
        <div class="flex flex-col gap-1">
            <p class="text-sm font-medium text-slate-800">{{ t('settings.profile.email') }}</p>
            <p>{{ props.email }}</p>
            <p class="text-sm text-slate-600">
                {{ props.emailVerifiedAt ? t('settings.profile.email_verified') : t('settings.profile.email_unverified') }}
            </p>
        </div>

        <FormField
            v-slot="{ describedBy }"
            :label="t('settings.profile.locale')"
            id="locale"
            :error="form.errors.locale"
        >
            <select
                id="locale"
                v-model="form.locale"
                :aria-describedby="describedBy"
                name="locale"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
                <option :value="null">{{ localeFollowLabel }}</option>
                <option v-for="locale in locales" :key="locale" :value="locale">
                    {{ t(`settings.locales.${locale}`) }}
                </option>
            </select>
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('settings.profile.timezone')"
            id="timezone"
            :error="form.errors.timezone"
        >
            <select
                id="timezone"
                v-model="form.timezone"
                :aria-describedby="describedBy"
                name="timezone"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
                <option :value="null">{{ timezoneFollowLabel }}</option>
                <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
            </select>
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('settings.profile.unit_system')"
            id="unit_system"
            :error="form.errors.unit_system"
        >
            <select
                id="unit_system"
                v-model="form.unit_system"
                :aria-describedby="describedBy"
                name="unit_system"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
                <option :value="null">{{ unitFollowLabel }}</option>
                <option v-for="unit in units" :key="unit" :value="unit">
                    {{ t(`settings.units.${unit}`) }}
                </option>
            </select>
        </FormField>

        <button
            type="submit"
            :disabled="form.processing"
            class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
        >
            {{ form.processing ? t('common.pending.default') : t('settings.profile.submit') }}
        </button>
    </form>

    <!--
        Profilbilden, se [[ADR-0076 Profilbilden]] § 6 och 7. Cirkeln ritas av
        `UserAvatar` — bilden med sitt utsnitt när den finns, initialerna
        annars — och *Adjust* står bredvid *Remove* när det finns en bild.
        Statusraderna `avatar-updated`, `avatar-removed` och `avatar-cropped`
        ritas av FlashMessage, som alla andra statusrader på sidan.
    -->
    <section class="mt-8 flex max-w-lg flex-col gap-4">
        <h2 class="text-lg font-semibold">{{ t('settings.profile.avatar.heading') }}</h2>

        <div class="flex items-center gap-4">
            <UserAvatar :user="currentUser" :size="64" />

            <div class="flex flex-col gap-2">
                <label for="avatar-file" class="text-sm font-medium text-slate-800">
                    {{ t('settings.profile.avatar.upload') }}
                </label>

                <input
                    id="avatar-file"
                    type="file"
                    accept="image/*"
                    :disabled="busy"
                    class="text-sm text-slate-700"
                    @change="onFileSelected"
                >

                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="avatar"
                        type="button"
                        :disabled="busy"
                        class="self-start inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
                        @click="openEditor"
                    >
                        {{ t('settings.profile.avatar.adjust') }}
                    </button>

                    <button
                        v-if="avatar"
                        type="button"
                        :disabled="busy"
                        class="self-start inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
                        @click="removeAvatar"
                    >
                        {{ t('settings.profile.avatar.remove') }}
                    </button>
                </div>
            </div>
        </div>

        <p v-if="uploading" role="status" class="text-sm text-slate-600">
            {{ t('settings.profile.avatar.uploading') }}
        </p>

        <!-- Serverns färdigöversatta mening: kvoten, en fil som inte är en
             bild eller en person utan personkonto. -->
        <p v-if="avatarError" role="alert" class="text-sm text-danger">{{ avatarError }}</p>
    </section>

    <!--
        Redigeraren, se [[ADR-0076 Profilbilden]] § 7. Arket är UiSheet, som
        bär fokusfällan, Esc och trycket utanför. Ytan är kvadratisk med en
        rund mask: `UserAvatar` ritar utsnittet i fyrkant (och ber om
        `?variant=medium`), och masken mörklägger hörnen så att det som syns i
        cirkeln är exakt vad avataren visar. Dra för att flytta punkten, pilarna
        när ytan har fokus, reglaget för zoomen.
    -->
    <UiSheet
        :open="editorOpen"
        :heading="t('settings.profile.avatar.crop_heading')"
        :trigger="editorTrigger"
        @close="closeEditor"
    >
        <div class="mx-auto" :style="{ width: `${EDITOR_SIZE}px`, height: `${EDITOR_SIZE}px` }">
            <div
                tabindex="0"
                role="application"
                :aria-label="t('settings.profile.avatar.crop_label')"
                class="relative touch-none overflow-hidden rounded-control outline-none focus-visible:ring-2 focus-visible:ring-focus"
                :style="{ width: `${EDITOR_SIZE}px`, height: `${EDITOR_SIZE}px` }"
                @pointerdown="startDrag"
                @pointermove="dragMove"
                @pointerup="endDrag"
                @pointercancel="endDrag"
                @keydown="onEditorKeydown"
            >
                <UserAvatar
                    :user="editorUser"
                    :size="EDITOR_SIZE"
                    shape="square"
                    @load="onEditorImageLoad"
                />

                <!-- Den runda masken: hörnen utanför cirkeln mörkläggs. -->
                <span
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-0 bg-ink opacity-50"
                    :style="maskStyle"
                ></span>

                <span
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-0 rounded-pill border-2 border-white"
                ></span>
            </div>
        </div>

        <div class="mt-3 flex flex-col gap-1">
            <label :for="zoomId" class="text-sm font-medium text-ink">
                {{ t('settings.profile.avatar.zoom') }}
            </label>

            <input
                :id="zoomId"
                v-model.number="editorZoom"
                type="range"
                min="100"
                max="300"
                step="10"
                :disabled="editorPending"
                class="min-h-11"
            >
        </div>

        <div class="mt-1 flex flex-wrap gap-2">
            <button
                type="button"
                :disabled="editorPending"
                class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                @click="saveCrop"
            >
                {{ t('settings.profile.avatar.save') }}
            </button>

            <button
                type="button"
                :disabled="editorPending"
                class="inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
                @click="resetCrop"
            >
                {{ t('settings.profile.avatar.reset') }}
            </button>

            <button
                type="button"
                :disabled="editorPending"
                class="inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
                @click="closeEditor"
            >
                {{ t('settings.profile.avatar.cancel') }}
            </button>
        </div>

        <p v-if="editorError" role="alert" class="mt-1 text-sm text-danger">{{ editorError }}</p>
    </UiSheet>

    <EmailChangeForm :has-password="props.hasPassword" :totp-enabled="props.totpEnabled" />
</template>
