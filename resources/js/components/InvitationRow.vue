<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AccessLevelField from './AccessLevelField.vue';
import UiButton from './UiButton.vue';
import UiSelect from './UiSelect.vue';
import { formatDate } from './accessPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En inbjudningsrad på delningssidan, se issue 430.
 *
 * **Bara obesvarade inbjudningar når hit.** Servern filtrerar listan på
 * `status = 'pending'` (utgångna rader är fortfarande `pending` i kolumnen),
 * så varje rad som ritas går att dra tillbaka — och nu också att ändra.
 *
 * **Redigeringen är en egen yta med *Save* och *Cancel*.** Nivån är ett val,
 * men ett inbjudningsval skickar ingen ny inbjudan och öppnar ingen direkt
 * skrivning: raden fäller ut nivåväljaren (AccessLevelField, samma fält som
 * ContainerAccessRow och inbjudningsformuläret) och sparar först när ägaren
 * säger till. Det skiljer raden från åtkomstraden, där valet sparas direkt
 * (issue 1250) — en inbjudan är ett utskick, och en ändring av den ska vara
 * ett medvetet steg.
 *
 * **Egen komponent och inte en v-for i Sharing.vue**, av samma skäl som
 * ContainerAccessRow: varje rad har sitt eget vänteläge och sina egna
 * fältfel, så ett fel på en rad inte färgar varje annan.
 *
 * **Agentnivån ritas bara när fältet finns** (issue 408): väljarens tak är
 * inbjudans egen nivå, och den följer med i PATCH-kroppen endast då. Utan
 * fältet är värdet alltid `null` på servern.
 *
 * **Knapparna ritas bara med `can.manage`.** Det är presentation; grinden är
 * `manageAccess()` i ContainerInvitationController, och ett fryst ägarkonto
 * som ser listan får 403 på PATCH även om en knapp skulle synas.
 *
 * Felet på nyckeln `invitation` — en rad som hunnit besvaras — står i
 * Sharing.vue ovanför listan: raden felet gäller är borta ur nästa svar.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    invitation: { type: Object, required: true },
    itemNames: { type: Object, required: true },
    invitedByNames: { type: Object, required: true },
    levels: { type: Array, required: true },
    /* Fältet *Agent access* (issue 408), ägarens tak för inbjudans agenter. */
    agentAccess: { type: Boolean, default: false },
    agentLevel: { type: String, default: null },
    canManage: { type: Boolean, required: true },
});

const { t } = useTranslations();
const page = usePage();

/* Fältets id måste vara unikt på sidan — varje rad har samma fältnamn. */
const field = (name) => `invitation-${props.invitation.ulid}-${name}`;

const url = `/containers/${props.containerUlid}/invitations/${props.invitation.ulid}`;

const editing = ref(false);
const level = ref(props.invitation.level);
const agentLevel = ref(props.agentLevel ?? '');
const pending = ref(false);
const levelError = ref(null);
const agentError = ref(null);

/* Nivåerna upp till inbjudans valda nivå — taket är aldrig högre. */
const agentLevels = computed(() => props.levels.slice(0, props.levels.indexOf(level.value) + 1));

const scope = computed(() =>
    props.invitation.item === null
        ? t('sharing.invitations.item_container')
        : props.itemNames[props.invitation.item],
);

const inviter = computed(
    () => props.invitedByNames[props.invitation.invited_by] ?? t('sharing.accesses.granted_by_unknown'),
);

/* Öppna redigeringen med serverns värden, inte med ett halvt sparat försök. */
function openEdit() {
    level.value = props.invitation.level;
    agentLevel.value = props.agentLevel ?? '';
    levelError.value = null;
    agentError.value = null;
    editing.value = true;
}

function cancel() {
    editing.value = false;
    level.value = props.invitation.level;
    agentLevel.value = props.agentLevel ?? '';
}

function save() {
    const body = { level: level.value };

    if (props.agentAccess) {
        body.agent_level = agentLevel.value === '' ? null : agentLevel.value;
    }

    levelError.value = null;
    agentError.value = null;

    router.patch(url, body, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            pending.value = true;
        },
        onFinish: () => {
            pending.value = false;
        },
        onSuccess: () => {
            editing.value = false;
        },
        onError: (errors) => {
            levelError.value = errors.level ?? null;
            agentError.value = errors.agent_level ?? null;
        },
    });
}
</script>

<template>
    <li class="flex flex-col gap-1 rounded-card border border-border bg-surface px-4 py-2 text-body">
        <span class="font-medium text-ink">{{ invitation.email }}</span>

        <template v-if="! editing">
            <span class="text-ink">{{ t(`sharing.level.${invitation.level}.label`) }}</span>
            <span class="text-ink">{{ scope }}</span>
            <span class="text-ink">{{ t(`sharing.invitations.status.${invitation.status}`) }}</span>
            <span class="text-meta text-ink-muted">
                {{ t('sharing.invitations.expires', { date: formatDate(invitation.expires_at, page.props.locale) }) }}
            </span>
            <span class="text-meta text-ink-muted">
                {{ t('sharing.invitations.invited_by') }}: {{ inviter }}
            </span>

            <div v-if="canManage" class="mt-2 flex flex-wrap items-center gap-3">
                <UiButton variant="quiet" size="compact" @click="openEdit">
                    {{ t('sharing.invitations.edit') }}
                </UiButton>

                <Link
                    :href="url"
                    method="delete"
                    as="button"
                    preserve-scroll
                    class="inline-flex min-h-11 items-center self-start text-body text-danger underline"
                >
                    {{ t('sharing.invitations.revoke') }}
                </Link>
            </div>
        </template>

        <form v-else class="mt-2 flex flex-col gap-3" @submit.prevent="save">
            <AccessLevelField
                :id="field('level')"
                v-model="level"
                :levels="levels"
                :error="levelError"
                :disabled="pending"
            />

            <div v-if="agentAccess" class="flex flex-col gap-1">
                <label :for="field('agent_level')" class="text-meta font-medium text-ink">
                    {{ t('agents.access_field.label') }}
                </label>

                <UiSelect
                    :id="field('agent_level')"
                    v-model="agentLevel"
                    :disabled="pending"
                    class="self-start"
                >
                    <option value="">{{ t('agents.access_field.none') }}</option>
                    <option v-for="niva in agentLevels" :key="niva" :value="niva">
                        {{ t(`sharing.level.${niva}.label`) }}
                    </option>
                </UiSelect>

                <p class="text-meta text-ink-muted">{{ t('agents.access_field.hint') }}</p>

                <p v-if="agentError" role="alert" class="text-body text-danger">{{ agentError }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <UiButton type="submit" :pending="pending">
                    {{ pending ? t('common.pending.default') : t('sharing.invitations.save') }}
                </UiButton>

                <UiButton type="button" variant="secondary" :disabled="pending" @click="cancel">
                    {{ t('sharing.invitations.cancel') }}
                </UiButton>
            </div>
        </form>
    </li>
</template>
