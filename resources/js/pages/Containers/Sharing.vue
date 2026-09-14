<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import ContainerAccessRow from '../../components/ContainerAccessRow.vue';
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
 * **Ingen inbjudningsyta.** Listan över obesvarade inbjudningar,
 * inbjudningsformuläret och acceptflödet är 55b, som lägger sin tredje
 * sektion här. Webben beviljar aldrig en åtkomst direkt (Beslut 2) — all ny
 * delning går genom en inbjudan.
 */
const props = defineProps({
    container: { type: Object, required: true },
    participants: { type: Array, required: true },
    accesses: { type: Array, default: null },
    itemNames: { type: Object, required: true },
    granteeNames: { type: Object, required: true },
    grantedByNames: { type: Object, required: true },
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
    </ContainerLayout>
</template>
