<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import ContainerSettingsLayout from '../../layouts/ContainerSettingsLayout.vue';
import ContainerAccessRow from '../../components/ContainerAccessRow.vue';
import InvitationForm from '../../components/InvitationForm.vue';
import InvitationRow from '../../components/InvitationRow.vue';
import UiConfirmDialog from '../../components/UiConfirmDialog.vue';
import TwoFactorRequirementToggle from '../../components/TwoFactorRequirementToggle.vue';
import { accessKindLabel, accessScopeLabel, formatDate, granteeLabel } from '../../components/accessPresentation.js';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({
    layout: (props) => [
        [AppLayout, {}],
        [ContainerLayout, { hero: 'compact', container: props.container }],
        [ContainerSettingsLayout, { container: props.container }],
    ],
});

/*
 * Delningssidan, se issue 55a § Beslut 3 och 7 och issue 1250.
 *
 * Sidan ligger i ContainerSettingsLayout och bär den prop layouten kräver:
 * `container` ur App\Http\Resources\ContainerResource.
 *
 * **En lista, inte två** (issue 1250). Deltagarlistan och åtkomstlistan sade
 * samma sak två gånger, och nivåväljaren — det ägaren kom hit för att göra —
 * stod längst ner i små textrader. Nu heter listan *People with access*:
 * ägarkontot överst utan kontroller, sedan en rad per giltig åtkomst med
 * namnet i body-storlek och omfånget under. Har en person flera åtkomster
 * står de som var sin rad under samma namn — två omfång, inte två personer.
 * Ett `managed`-konto är fortfarande en post, aldrig en per anställd.
 *
 * **Kontrollerna finns bara för den som får se åtkomsterna.** `accesses` är
 * `null` när svaret är nej, och då ritas raderna ur `participants` i stället
 * — namn och roll, samma innehåll som deltagarlistan hade före issue 1250.
 * Det är inte samma sak som en tom lista: en prop som ligger i sidans HTML är
 * utlämnad oavsett vad den här filen gör med den, och kontrollern skickar
 * därför ingenting alls. Ingen `v-if` här är något skydd — den är
 * formatering. Historiken är av samma skäl bara ägarens.
 *
 * **Text sparas med *Save*, val sparas direkt** (Tonys regel från issue
 * 1245). Nivån och gästens utgångsdatum skrivs därför i sina egna PATCH:er
 * från raden, utan *Save* (se ContainerAccessRow.vue); inbjudningsformuläret
 * är text och behåller sin knapp.
 *
 * **Historiken står sist, hopfälld** (issue 1250 § 3). Återkallade och
 * utgångna åtkomster är något man läser efteråt, inte en yta man arbetar i,
 * och de hade legat mellan åtkomsterna och inbjudningarna och trängt undan
 * det man kom hit för. Innehållet är oförändrat: namn, vad raden betydde,
 * omfång och datum.
 *
 * **Giltiga och historiska rader delas i vyn** (Beslut 7).
 * `ContainerAccessResource` bär `revoked_at` och `expires_at` och ingen
 * `status` — med flit, issue 9b § Beslut 10 — så uppdelningen görs på de två
 * tidsstämplarna. Ingen härledd flagga kommer från servern, för den finns
 * redan som två tidsstämplar, och en andra sanning om tillståndet är en
 * sanning som kan glida isär.
 *
 * **Inbjudningarna står direkt efter listan** (issue 1250 § 2, issue 55b
 * § Beslut 5). Samma grind som åtkomsterna — `viewAccesses()`, alltså
 * medlemskap i ägarkontot — och `invitations` är `null` för den som inte får
 * se dem. En obesvarad inbjudan röjer en e-postadress, och listan är därför
 * lika känslig som förvaltningsvyn; här visas adressen med flit, för det är
 * avsändarens egen lista över vad hon skickat. Formuläret kräver
 * `manageAccess()` (skrivningen) och ritas därför bara för `can.manage`,
 * medan listan ritas ur `invitations`. Sedan issue 430 bär listan bara
 * obesvarade inbjudningar, och varje rad har *Edit* och *Revoke* för den som
 * får hantera åtkomster — se InvitationRow.vue.
 */
const props = defineProps({
    container: { type: Object, required: true },
    participants: { type: Array, required: true },
    accesses: { type: Array, default: null },
    invitations: { type: Array, default: null },
    itemNames: { type: Object, required: true },
    granteeNames: { type: Object, required: true },
    grantedByNames: { type: Object, required: true },
    invitedByNames: { type: Object, required: true },
    items: { type: Array, required: true },
    levels: { type: Array, required: true },
    /* Fältet *Agent access* (issue 408): ritas bara när servern säger det. */
    agentAccess: { type: Boolean, default: false },
    /* Access-ULID → ägarens tak för medlemmens agenter. */
    agentLevels: { type: Object, default: () => ({}) },
    /* Inbjudnings-ULID → inbjudans agenttak (issue 430). */
    invitationAgentLevels: { type: Object, default: () => ({}) },
    can: { type: Object, required: true },
    /* Tvåfaktorkravet (issue 450, ADR-0092). */
    twoFactor: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

/*
 * Ett fryst ägarkonto: `revokeAccess()` saknar den `read_only`-kontroll som
 * `manageAccess()` har, för regel 4 undantar uttryckligen återkallandet — det
 * minskar exponeringen i stället för att öka den. Kombinationen "får återkalla
 * men inte ändra" ÄR fryst, och den beskrivs med en rad i stället för att
 * användaren ska upptäcka den som ett 403 (Beslut 9).
 */
const isFrozenOwner = computed(() => props.can.revoke && !props.can.manage);

/*
 * Lämna containern (issue 1529 · M75). Knappen ritas bara när `can.leave` är
 * sant och öppnar en bekräftelse; grinden är ContainerPolicy::leave().
 */
const leaveOpen = ref(false);
const leaveTrigger = ref(null);
const leavePending = ref(false);
const leaveError = ref(null);

function openLeave(button) {
    leaveTrigger.value = button;
    leaveError.value = null;
    leaveOpen.value = true;
}

function leave() {
    router.post(`/containers/${props.container.ulid}/leave`, {}, {
        onStart: () => {
            leavePending.value = true;
        },
        onFinish: () => {
            leavePending.value = false;
            leaveOpen.value = false;
        },
        onError: () => {
            leaveError.value = t('sharing.leave.error');
        },
    });
}

/* Ägarkontot är alltid deltagarlistans första post (issue 9c § Beslut 3). */
const owner = computed(() => props.participants[0] ?? null);

const isValid = (access) =>
    access.revoked_at === null && (access.expires_at === null || new Date(access.expires_at) > new Date());

const validAccesses = computed(() => (props.accesses ?? []).filter(isValid));
const historicalAccesses = computed(() => (props.accesses ?? []).filter((access) => !isValid(access)));

/*
 * Tvåfaktorkravet gäller och mottagaren saknar tvåfaktor: raden märks.
 * `twoFactor.lacking` bär användarnas ULID:er; en kontomottagare märks när
 * någon av dess medlemmar saknar den, vilket servern redan räknat ut i
 * `lacking_grantees`.
 */
const lacksTwoFactor = (access) =>
    props.twoFactor.effective && props.twoFactor.lackingGrantees.includes(access.ulid);

const historyDate = (access) =>
    access.revoked_at !== null
        ? t('sharing.history.revoked', { date: formatDate(access.revoked_at, page.props.locale) })
        : t('sharing.history.expired', { date: formatDate(access.expires_at, page.props.locale) });
</script>

<template>
    <Head :title="t('sharing.title')" />

    <h1 class="text-2xl font-semibold text-ink">{{ t('sharing.heading') }}</h1>

    <!--
        Den enda listan (issue 1250 § 1). Ägarkontot först, sedan en rad per
        giltig åtkomst. Behörighetsmeningen står EN gång över listan och inte
        per rad.
    -->
    <section class="mt-8">
        <h2 class="text-title font-semibold text-ink">{{ t('sharing.accesses.people_heading') }}</h2>
        <p class="mt-1 text-body text-ink-muted">{{ t('sharing.accesses.limits') }}</p>

        <p v-if="isFrozenOwner" class="mt-4 rounded-card border border-warning bg-warning/10 px-4 py-2 text-body text-ink">
            {{ t('sharing.frozen') }}
        </p>

        <!-- Tvåfaktorkravet (issue 450): bara för den som får hantera åtkomst. -->
        <TwoFactorRequirementToggle
            v-if="can.manage"
            id="container-require-two-factor"
            :label="t('sharing.two_factor.container_label')"
            :url="`/containers/${container.ulid}/sharing/require-two-factor`"
            :checked="twoFactor.required"
            :locked="twoFactor.accountRequired"
            :locked-note="t('sharing.two_factor.required_for_all')"
            :needs-own="!twoFactor.ownerEnrolled && !twoFactor.required && !twoFactor.accountRequired"
            :needs-own-note="t('sharing.two_factor.container_needs_own')"
            :lacking="twoFactor.lacking"
            :error="page.props.errors.require_two_factor ?? null"
        />

        <p v-if="can.manage && twoFactor.effective" class="mt-1 text-meta text-ink-muted">
            {{ t('sharing.two_factor.invite_note') }}
        </p>

        <ul v-if="accesses" class="mt-4 flex flex-col gap-2">
            <!-- Ägarkontot: namn och roll, utan kontroller. Det finns ingen
                 nivå att välja och ingen åtkomst att ta bort. -->
            <li
                v-if="owner"
                class="flex min-h-11 flex-col gap-1 rounded-card border border-border bg-surface px-4 py-2"
            >
                <span class="text-body font-medium text-ink">{{ owner.name }}</span>
                <span class="text-meta text-ink-muted">{{ t('sharing.role.owner') }}</span>
            </li>

            <ContainerAccessRow
                v-for="access in validAccesses"
                :key="access.ulid"
                :container-ulid="container.ulid"
                :access="access"
                :item-names="itemNames"
                :grantee-names="granteeNames"
                :levels="levels"
                :agent-access="agentAccess"
                :agent-level="agentLevels[access.ulid] ?? null"
                :lacks-two-factor="lacksTwoFactor(access)"
            />
        </ul>

        <!-- Utan `accesses` ritas samma lista ur deltagarlistan: namn och
             roll, ingen nivå, inget utgångsdatum och ingen borttagning. -->
        <ul v-else class="mt-4 flex flex-col gap-2">
            <li
                v-for="participant in participants"
                :key="`${participant.type}-${participant.ulid}`"
                class="flex min-h-11 flex-col gap-1 rounded-card border border-border bg-surface px-4 py-2"
            >
                <span class="text-body font-medium text-ink">{{ participant.name }}</span>
                <span class="text-meta text-ink-muted">{{ t(`sharing.role.${participant.role}`) }}</span>
            </li>
        </ul>

        <div v-if="can.leave" class="mt-4">
            <p v-if="leaveError" role="alert" class="mb-2 text-body text-danger">{{ leaveError }}</p>

            <button
                type="button"
                :disabled="leavePending"
                class="inline-flex min-h-11 items-center rounded-control border border-danger px-3 text-body font-medium text-danger hover:bg-danger-soft disabled:opacity-50"
                @click="openLeave($event.currentTarget)"
            >
                {{ t('sharing.leave.button') }}
            </button>

            <UiConfirmDialog
                :open="leaveOpen"
                :heading="t('sharing.leave.heading')"
                :body="t('sharing.leave.body', { container: container.name })"
                :confirm-label="t('sharing.leave.button')"
                tone="danger"
                :pending="leavePending"
                :trigger="leaveTrigger"
                @confirm="leave"
                @cancel="leaveOpen = false"
            />
        </div>
    </section>

    <!--
        Inbjudningarna, direkt efter listan (issue 1250 § 2), se issue 55b
        § Beslut 5. `invitations` är `null` för en deltagare som inte är
        medlem i ägarkontot, och då ritas ingenting — ingen v-if här är ett
        skydd, den är formatering. Kontrollern skickar ingenting alls.
    -->
    <section v-if="invitations" class="mt-10">
        <h2 class="text-title font-semibold text-ink">{{ t('sharing.invitations.heading') }}</h2>
        <p class="mt-1 text-body text-ink-muted">{{ t('sharing.invitations.description') }}</p>

        <!--
            Ett svar som inte går att dra tillbaka — en redan besvarad rad
            — blir ett formulärfel på nyckeln `invitation`, inte en rå
            felkod på skärmen. Det står här och inte per rad: raden felet
            gäller är redan besvarad och bär ingen egen yta att sätta det
            på.
        -->
        <p
            v-if="page.props.errors.invitation"
            role="alert"
            class="mt-4 rounded-card border border-warning bg-warning/10 px-3 py-2 text-body text-ink"
        >
            {{ page.props.errors.invitation }}
        </p>

        <InvitationForm
            v-if="can.manage"
            :container-ulid="container.ulid"
            :levels="levels"
            :items="items"
            :agent-access="agentAccess"
        />

        <ul v-if="invitations.length > 0" class="mt-4 flex flex-col gap-2">
            <InvitationRow
                v-for="invitation in invitations"
                :key="invitation.ulid"
                :container-ulid="container.ulid"
                :invitation="invitation"
                :item-names="itemNames"
                :invited-by-names="invitedByNames"
                :levels="levels"
                :agent-access="agentAccess"
                :agent-level="invitationAgentLevels[invitation.ulid] ?? null"
                :can-manage="can.manage"
            />
        </ul>

        <p v-else class="mt-4 text-body text-ink-muted">{{ t('sharing.invitations.empty') }}</p>
    </section>

    <!--
        Historiken sist och hopfälld från början (issue 1250 § 3), se
        Beslut 7. `<details>` är tangentbordets egen yta: summary är
        fokuserbart och fälls ut med Enter eller mellanslag, utan egen
        tabbhanterare. Bara ägaren har `accesses`, och därför är det också
        bara hon som har en historik att fälla ut.
    -->
    <details
        v-if="accesses && historicalAccesses.length > 0"
        class="mt-10 rounded-card border border-border bg-surface"
    >
        <summary class="flex min-h-11 cursor-pointer items-center px-4 text-title font-semibold text-ink">
            {{ t('sharing.history.heading') }}
        </summary>

        <ul class="flex flex-col gap-2 border-t border-border p-4">
            <li
                v-for="access in historicalAccesses"
                :key="access.ulid"
                class="flex flex-col gap-1 rounded-control bg-surface-sunken px-4 py-2 text-body"
            >
                <!-- Vems åtkomst som klipptes är hela skälet till att
                     historiken finns — ett datum utan namn svarar
                     inte på frågan. -->
                <span class="text-ink">{{ granteeLabel(t, granteeNames, access) }}</span>
                <span class="text-ink">{{ accessKindLabel(t, access) }}</span>
                <span class="text-ink">{{ accessScopeLabel(t, itemNames, access) }}</span>
                <span class="text-meta text-ink-muted">{{ historyDate(access) }}</span>
            </li>
        </ul>
    </details>
</template>
