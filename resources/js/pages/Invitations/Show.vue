<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Mejlets landningssida, se issue 55b § Beslut 2, 3 och 4.
 *
 * **Fem tillstånd, och vart och ett har ett svar.** Servern avgör vilket —
 * kontrollern anropar den delade InvitationTokenRequest::invitation() och
 * fångar dess ApiException — och den här filen renderar det. Ingen av
 * kontrollerna görs om här: formuläret finns i `ready`, och i varje annat
 * tillstånd hade en postning nekats av servern oavsett vad sidan visade.
 *
 *   ready       — inloggad, verifierad mottagare. Accept- och avvisa-formulären.
 *   guest       — utloggad. Containerns namn, inbjudaren och nivån, plus vägarna
 *                 till inloggning och registrering. Adressen visas aldrig.
 *   unverified  — inloggad med rätt adress men overifierad. Ingenting eget
 *                 renderas: AppLayout visar redan verifieringspåminnelsen för
 *                 varje overifierad användare utom på /email/verify, och två
 *                 likadana knappar på samma sida är en bugg och inte en
 *                 påminnelse. Kvar blir inbjudningskontexten ovanför.
 *   mismatch    — inloggad med en annan adress. Beskedet säger INTE vilken
 *                 adress inbjudan gäller.
 *   unavailable — utgången, redan besvarad eller okänt token. ETT tillstånd
 *                 med EN mening, för de två får inte gå att skilja åt: ett
 *                 "finns inte" mot ett "är redan accepterad" är en orakelyta
 *                 mot giltiga token.
 *
 * **Tokenet ligger i formulärets data och inte i URL:en.** Sidan nås på
 * `/invitations`, dit `GET /invitations/{token}` omdirigerade efter att ha
 * lagt tokenet i sessionen (Beslut 2) — det är hela skälet till att
 * accept- och avvisa-vägarna kan ta det i kroppen precis som `/api` gör. Det
 * skickas bara till `ready`: de övriga tillstånden har inget formulär att
 * lägga det i.
 *
 * Ingen egen text och ingen egen regel: allt går genom t(), och `level` slås
 * upp med samma nycklar som delningssidan använder.
 */
const props = defineProps({
    state: { type: String, required: true },
    invitation: { type: Object, default: null },
    token: { type: String, default: null },
});

const { t } = useTranslations();
const page = usePage();

const acceptForm = useForm({ token: props.token });
const rejectForm = useForm({ token: props.token });

/* Avvisa går till startsidan, accept till containerlistan — se kontrollern. */
function accept() {
    acceptForm.post('/invitations/accept');
}

function reject() {
    rejectForm.post('/invitations/reject');
}
</script>

<template>
    <AppLayout>
        <Head :title="t('invitation.title')" />

        <h1 class="text-2xl font-semibold">{{ t('invitation.heading') }}</h1>

        <!--
            Förhandsvisningen — containerns namn, inbjudaren och nivån — visas för
            både en gäst och en inloggad mottagare. Containerns namn står redan i
            mejlet (InvitationNotification), så den som har länken har fått
            det. Adressen inbjudan gäller finns inte i propen alls.
        -->
        <template v-if="invitation">
            <p class="mt-4 text-slate-700">
                {{ t('invitation.intro', { inviter: invitation.inviter, container: invitation.container }) }}
            </p>
            <p class="mt-1 text-sm text-slate-600">
                {{ t('invitation.level', { level: t(`sharing.level.${invitation.level}.label`) }) }}
            </p>
        </template>

        <!--
            Ett nekande från servern blir ett formulärfel på nyckeln
            `invitation` (t.ex. en andra accept av samma token), aldrig en rå
            JSON-kropp — se App\Http\Controllers\InvitationResponseController.
        -->
        <p
            v-if="page.props.errors.invitation"
            role="alert"
            class="mt-4 max-w-sm rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            {{ page.props.errors.invitation }}
        </p>

        <div v-if="state === 'ready'" class="mt-6 flex max-w-sm flex-col gap-3">
            <form @submit.prevent="accept">
                <button
                    type="submit"
                    :disabled="acceptForm.processing"
                    class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                >
                    {{ acceptForm.processing ? t('common.pending.default') : t('invitation.accept') }}
                </button>
            </form>

            <form @submit.prevent="reject">
                <button
                    type="submit"
                    :disabled="rejectForm.processing"
                    class="inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
                >
                    {{ rejectForm.processing ? t('common.pending.default') : t('invitation.reject') }}
                </button>
            </form>
        </div>

        <div v-else-if="state === 'guest'" class="mt-6 flex max-w-sm flex-col gap-2 text-sm">
            <p class="text-slate-700">{{ t('invitation.guest') }}</p>
            <Link href="/login" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">{{ t('nav.login') }}</Link>
            <Link href="/register" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">{{ t('auth.register.heading') }}</Link>
        </div>

        <!--
            `unverified` har inget eget innehåll: AppLayouts banner bär redan
            verifieringsuppmaningen (se dess docblock). Grenen finns kvar bara
            för att tillståndet inte ska falla vidare till `mismatch` eller
            `unavailable` nedan.
        -->
        <template v-else-if="state === 'unverified'"></template>

        <div v-else-if="state === 'mismatch'" class="mt-6 max-w-sm">
            <p class="text-slate-700">{{ t('invitation.mismatch') }}</p>
        </div>

        <!--
            `unavailable` och ingenting annat: utgången, redan besvarad och
            okänt token ger samma besked, med en väg vidare till startsidan.
        -->
        <div v-else class="mt-6 flex max-w-sm flex-col gap-2 text-sm">
            <p class="text-slate-700">{{ t('invitation.unavailable') }}</p>
            <Link href="/" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">{{ t('invitation.home') }}</Link>
        </div>
    </AppLayout>
</template>
