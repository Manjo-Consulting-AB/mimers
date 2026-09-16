<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import ContainerAccessRow from '../../components/ContainerAccessRow.vue';
import InvitationForm from '../../components/InvitationForm.vue';
import { accessKindLabel, accessScopeLabel, formatDate, granteeLabel } from '../../components/accessPresentation.js';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Delningssidan, se issue 55a § Beslut 3 och 7.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver:
 * `container` ur App\Http\Resources\ContainerResource.
 *
 * **Två sektioner med olika publik, och den ena datan kommer inte alls.**
 * Deltagarna har grinden `view()` — varje deltagare ser dem, också en
 * `read`-guest — och visar namn och roll, aldrig en e-postadress, en nivå,
 * ett utgångsdatum eller vem som beviljade. Åtkomsterna har grinden
 * `viewAccesses()`, alltså medlemskap i ägarkontot.
 *
 * `accesses` är `null` när svaret är nej, och då renderas sektionen inte. Det
 * är inte samma sak som en tom lista: en prop som ligger i sidans HTML är
 * utlämnad oavsett vad den här filen gör med den, och kontrollern skickar
 * därför ingenting alls. Ingen `v-if` här är något skydd — den är formatering.
 *
 * **Giltiga och historiska rader delas i vyn** (Beslut 7).
 * `ContainerAccessResource` bär `revoked_at` och `expires_at` och ingen
 * `status` — med flit, issue 9b § Beslut 10 — så uppdelningen görs på de två
 * tidsstämplarna. Ingen härledd flagga kommer från servern, för den finns
 * redan som två tidsstämplar, och en andra sanning om tillståndet är en
 * sanning som kan glida isär.
 *
 * **Tredje sektionen: Inbjudningar** (issue 55b § Beslut 5). Samma grind som
 * åtkomsterna — `viewAccesses()`, alltså medlemskap i ägarkontot — och
 * `invitations` är `null` för den som inte får se dem. En obesvarad inbjudan
 * röjer en e-postadress, och listan är därför lika känslig som förvaltningsvyn;
 * här visas adressen med flit, för det är avsändarens egen lista över vad hon
 * skickat. Formuläret kräver `manageAccess()` (skrivningen) och ritas därför
 * bara för `can.manage`, medan listan ritas ur `invitations`.
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
    can: { type: Object, required: true },
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

const isValid = (access) =>
    access.revoked_at === null && (access.expires_at === null || new Date(access.expires_at) > new Date());

const validAccesses = computed(() => (props.accesses ?? []).filter(isValid));
const historicalAccesses = computed(() => (props.accesses ?? []).filter((access) => !isValid(access)));

const historyDate = (access) =>
    access.revoked_at !== null
        ? t('sharing.history.revoked', { date: formatDate(access.revoked_at, page.props.locale) })
        : t('sharing.history.expired', { date: formatDate(access.expires_at, page.props.locale) });

/*
 * Vilka rader som bär en Dra tillbaka-knapp. `pending` är den väntande raden,
 * och `expired` är samma rad efter att tiden gått ut — `status` i kolumnen står
 * kvar på `pending` (issue 10a § Beslut 7), och det är resursen som redovisar
 * den som `expired`. Alltså går båda att dra tillbaka, medan en accepterad,
 * avvisad eller redan tillbakadragen rad inte gör det (422).
 *
 * Villkoret är presentation. Grinden är policyn: DELETE auktoriserar med
 * `manageAccess()` oavsett vad knappen visade.
 */
const isWithdrawable = (invitation) => invitation.status === 'pending' || invitation.status === 'expired';

/*
 * Omfånget på en inbjudningsrad. Itemets namn kommer ur `itemNames`, samma
 * uppslag som åtkomstlistan använder — kontrollern fyller det ur båda
 * listorna, med `withTrashed()`, så en inbjudan till ett sedan länge
 * mjukraderat item redovisas med sitt namn och inte som en tom rad.
 */
const invitationScope = (invitation) =>
    invitation.item === null ? t('sharing.invitations.item_container') : props.itemNames[invitation.item];

const invitationInviter = (invitation) =>
    props.invitedByNames[invitation.invited_by] ?? t('sharing.accesses.granted_by_unknown');
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('sharing.title')" />

        <h1 class="text-2xl font-semibold">{{ t('sharing.heading') }}</h1>

        <section class="mt-8">
            <h2 class="text-lg font-semibold">{{ t('sharing.participants.heading') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ t('sharing.participants.description') }}</p>

            <ul class="mt-4 flex flex-col gap-2">
                <li
                    v-for="participant in participants"
                    :key="`${participant.type}-${participant.ulid}`"
                    class="flex items-center gap-2 rounded border border-slate-300 bg-white px-4 py-2"
                >
                    <span>{{ participant.name }}</span>
                    <span class="text-xs text-slate-600">{{ t(`sharing.role.${participant.role}`) }}</span>
                </li>
            </ul>
        </section>

        <section v-if="accesses" class="mt-10">
            <h2 class="text-lg font-semibold">{{ t('sharing.accesses.heading') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ t('sharing.accesses.description') }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ t('sharing.accesses.limits') }}</p>

            <p v-if="isFrozenOwner" class="mt-4 rounded border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                {{ t('sharing.frozen') }}
            </p>

            <ul class="mt-4 flex flex-col gap-3">
                <ContainerAccessRow
                    v-for="access in validAccesses"
                    :key="access.ulid"
                    :container-ulid="container.ulid"
                    :access="access"
                    :item-names="itemNames"
                    :grantee-names="granteeNames"
                    :granted-by-names="grantedByNames"
                    :levels="levels"
                />
            </ul>

            <template v-if="historicalAccesses.length > 0">
                <h3 class="mt-8 text-base font-semibold">{{ t('sharing.history.heading') }}</h3>

                <ul class="mt-3 flex flex-col gap-2">
                    <li
                        v-for="access in historicalAccesses"
                        :key="access.ulid"
                        class="flex flex-col gap-1 rounded border border-slate-200 bg-slate-100 px-4 py-2 text-sm"
                    >
                        <!-- Vems åtkomst som klipptes är hela skälet till att
                             historiken finns — ett datum utan namn svarar
                             inte på frågan. -->
                        <span class="text-slate-700">{{ granteeLabel(t, granteeNames, access) }}</span>
                        <span class="text-slate-700">{{ accessKindLabel(t, access) }}</span>
                        <span class="text-slate-700">{{ accessScopeLabel(t, itemNames, access) }}</span>
                        <span class="text-xs text-slate-600">{{ historyDate(access) }}</span>
                    </li>
                </ul>
            </template>
        </section>

        <!--
            Tredje sektionen, se issue 55b § Beslut 5. `invitations` är `null`
            för en deltagare som inte är medlem i ägarkontot, och då ritas
            ingenting — ingen v-if här är ett skydd, den är formatering.
            Kontrollern skickar ingenting alls.
        -->
        <section v-if="invitations" class="mt-10">
            <h2 class="text-lg font-semibold">{{ t('sharing.invitations.heading') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ t('sharing.invitations.description') }}</p>

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
                class="mt-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            >
                {{ page.props.errors.invitation }}
            </p>

            <InvitationForm
                v-if="can.manage"
                :container-ulid="container.ulid"
                :levels="levels"
                :items="items"
            />

            <ul v-if="invitations.length > 0" class="mt-4 flex flex-col gap-2">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.ulid"
                    class="flex flex-col gap-1 rounded border border-slate-300 bg-white px-4 py-2 text-sm"
                >
                    <span class="font-medium text-slate-800">{{ invitation.email }}</span>
                    <span class="text-slate-700">{{ t(`sharing.level.${invitation.level}.label`) }}</span>
                    <span class="text-slate-700">{{ invitationScope(invitation) }}</span>
                    <span class="text-slate-700">{{ t(`sharing.invitations.status.${invitation.status}`) }}</span>
                    <span class="text-xs text-slate-600">
                        {{ t('sharing.invitations.expires', { date: formatDate(invitation.expires_at, page.props.locale) }) }}
                    </span>
                    <span class="text-xs text-slate-600">
                        {{ t('sharing.invitations.invited_by') }}: {{ invitationInviter(invitation) }}
                    </span>

                    <Link
                        v-if="can.manage && isWithdrawable(invitation)"
                        :href="`/containers/${container.ulid}/invitations/${invitation.ulid}`"
                        method="delete"
                        as="button"
                        preserve-scroll
                        class="inline-flex min-h-11 items-center self-start text-sm text-red-700 underline"
                    >
                        {{ t('sharing.invitations.revoke') }}
                    </Link>
                </li>
            </ul>

            <p v-else class="mt-4 text-sm text-slate-600">{{ t('sharing.invitations.empty') }}</p>
        </section>
    </ContainerLayout>
</template>
