<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import UiButton from '../../components/UiButton.vue';
import UiCheckbox from '../../components/UiCheckbox.vue';
import UiConfirmDialog from '../../components/UiConfirmDialog.vue';
import { useRelativeDate } from '../../composables/useRelativeDate.js';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: [AppLayout, SettingsLayout] });

/*
 * En agents sida, se issue 407 och [[ADR-0087 MCP-server för agenter]]
 * Riktning 6 och 12.
 *
 * **Val sparas direkt, text sparas med *Save*.** Nivåväljaren, switcharna och
 * pausen skickar en begäran vid varje ändring; namnet har en egen knapp. En
 * nivå skickas som `null` när gruppen stängs av.
 *
 * Rättighetslogiken bor på servern (App\Actions\Agent\*): sidan ritar bara
 * det servern skickat, och en sänkning som slår igenom i containrarna syns
 * när sidan laddas om efter svaret.
 */
const props = defineProps({
    /* `{ulid, name, connectedAt, lastUsedAt, paused, createContainers}` */
    agent: { type: Object, required: true },

    /* `{key, level}` per grupp, `level` null när gruppen är avstängd. */
    groups: { type: Array, required: true },

    /* Nivåerna, lägst först. */
    levels: { type: Array, required: true },

    /* `{levels: {schedules, files}, limits: {schedules, files}}` — inboxens nivåer och kontots gränser. */
    inbox: { type: Object, required: true },

    /* `{ulid, name, url}` */
    containers: { type: Array, required: true },

    /* `{id, action, at}`, de senaste först. */
    events: { type: Array, required: true },
});

const { t } = useTranslations();
const { eventDate } = useRelativeDate();

const base = `/settings/agents/${props.agent.ulid}`;
const nameForm = useForm({ name: props.agent.name });
const confirming = ref(false);

/* Etiketten byter medan knappen väntar, så en stillastående knapp inte ser död ut. */
const saveLabel = computed(() => (nameForm.processing ? t('common.pending.default') : t('agents.save')));
const disconnecting = ref(false);

function saveName() {
    nameForm.patch(base, { preserveScroll: true });
}

function setLevel(group, level) {
    router.put(`${base}/grants`, { levels: { [group]: level === '' ? null : level } }, { preserveScroll: true });
}

/* Switchen för allt: högsta nivån på, eller gruppen av. */
/* Inboxen sparas direkt; en begäran bär båda grupperna så att den andra behålls. */
function setInboxLevel(group, level) {
    const levels = { ...props.inbox.levels, [group]: level === '' ? null : level };
    router.put(`${base}/inbox`, { levels }, { preserveScroll: true });
}

/* Nivåerna upp till gränsen, lägst först. */
function inboxOptions(group) {
    const limit = props.inbox.limits[group];
    return limit === null ? [] : props.levels.slice(0, props.levels.indexOf(limit) + 1);
}

function setAll(group, on) {
    setLevel(group, on ? props.levels[props.levels.length - 1] : '');
}

function setCreateContainers(on) {
    router.put(`${base}/grants`, { create_containers: on }, { preserveScroll: true });
}

function setPaused(on) {
    router.put(`${base}/pause`, { paused: on }, { preserveScroll: true });
}

function disconnect() {
    router.delete(base, {
        onStart: () => { disconnecting.value = true; },
        onFinish: () => { disconnecting.value = false; confirming.value = false; },
    });
}
</script>

<template>
    <Head :title="agent.name" />

    <Link href="/settings/agents" class="inline-flex min-h-11 items-center text-sm underline">{{ t('agents.back') }}</Link>

    <h2 class="mt-2 text-2xl font-semibold">{{ agent.name }}</h2>

    <p class="mt-1 text-sm text-slate-700">
        {{ t('agents.connected_at') }}: {{ eventDate(agent.connectedAt).text }} ·
        {{ t('agents.last_used') }}: {{ agent.lastUsedAt ? eventDate(agent.lastUsedAt).text : t('agents.never_used') }}
    </p>

    <form class="mt-6 flex flex-col gap-1" @submit.prevent="saveName">
        <label for="agent-name" class="text-sm font-medium text-slate-800">{{ t('agents.name_label') }}</label>
        <div class="flex flex-wrap items-center gap-2">
            <input
                id="agent-name"
                v-model="nameForm.name"
                type="text"
                maxlength="100"
                class="min-h-11 rounded border border-slate-300 px-3"
            >
            <UiButton type="submit" :pending="nameForm.processing">{{ saveLabel }}</UiButton>
        </div>
        <p v-if="nameForm.errors.name" role="alert" class="text-sm text-red-700">{{ nameForm.errors.name }}</p>
    </form>

    <section class="mt-8">
        <h3 class="text-lg font-semibold">{{ t('agents.pause_label') }}</h3>
        <div class="mt-1 flex items-center gap-2 text-sm">
            <UiCheckbox id="agent-paused" :model-value="agent.paused" @update:model-value="setPaused">
            </UiCheckbox>
            <label for="agent-paused" class="inline-flex min-h-11 items-center">{{ t('agents.pause_label') }}</label>
        </div>
        <p class="text-sm text-slate-700">{{ t('agents.pause_hint') }}</p>
    </section>

    <section class="mt-8">
        <h3 class="text-lg font-semibold">{{ t('agents.rights_heading') }}</h3>
        <p class="mt-1 text-sm text-slate-700">{{ t('agents.rights_intro') }}</p>

        <ul class="mt-4 flex flex-col gap-3">
            <li v-for="group in groups" :key="group.key" class="rounded border border-slate-300 p-3">
                <label :for="`level-${group.key}`" class="text-sm font-medium">{{ t(`agents.group.${group.key}`) }}</label>
                <div class="mt-1 flex flex-wrap items-center gap-4">
                    <select
                        :id="`level-${group.key}`"
                        :value="group.level ?? ''"
                        class="min-h-11 rounded border border-slate-300 bg-white px-3"
                        @change="setLevel(group.key, $event.target.value)"
                    >
                        <option value="">{{ t('agents.level_none') }}</option>
                        <option v-for="level in levels" :key="level" :value="level">{{ t(`agents.level.${level}`) }}</option>
                    </select>

                    <span class="flex items-center gap-2 text-sm">
                        <UiCheckbox
                            :id="`all-${group.key}`"
                            :model-value="group.level === levels[levels.length - 1]"
                            @update:model-value="(on) => setAll(group.key, on)"
                        />
                        <label :for="`all-${group.key}`" class="inline-flex min-h-11 items-center">{{ t('agents.all_label') }}</label>
                    </span>
                </div>
            </li>
        </ul>

        <div class="mt-3 flex items-center gap-2 text-sm">
            <UiCheckbox id="create-containers" :model-value="agent.createContainers" @update:model-value="setCreateContainers" />
            <label for="create-containers" class="inline-flex min-h-11 items-center">{{ t('agents.create_containers') }}</label>
        </div>
    </section>

    <section class="mt-8">
        <h3 class="text-lg font-semibold">{{ t('agents.inbox_heading') }}</h3>
        <p class="mt-1 text-sm text-slate-700">{{ t('agents.inbox_intro') }}</p>

        <ul class="mt-4 flex flex-col gap-3">
            <li v-for="group in ['schedules', 'files']" :key="group" class="rounded border border-slate-300 p-3">
                <label :for="`inbox-${group}`" class="text-sm font-medium">{{ t(`agents.group.${group}`) }}</label>
                <div class="mt-1">
                    <select
                        :id="`inbox-${group}`"
                        :value="inbox.levels[group] ?? ''"
                        :disabled="inbox.limits[group] === null"
                        class="min-h-11 rounded border border-slate-300 bg-white px-3"
                        @change="setInboxLevel(group, $event.target.value)"
                    >
                        <option value="">{{ t('agents.level_none') }}</option>
                        <option v-for="level in inboxOptions(group)" :key="level" :value="level">{{ t(`agents.level.${level}`) }}</option>
                    </select>
                </div>
                <p v-if="inbox.limits[group] === null" class="mt-1 text-sm text-slate-700">{{ t('agents.inbox_locked') }}</p>
            </li>
        </ul>
    </section>

    <section class="mt-8">
        <h3 class="text-lg font-semibold">{{ t('agents.containers_heading') }}</h3>
        <p v-if="containers.length === 0" class="mt-1 text-sm text-slate-700">{{ t('agents.containers_empty') }}</p>
        <ul v-else class="mt-2 flex flex-col">
            <li v-for="container in containers" :key="container.ulid">
                <Link :href="container.url" class="inline-flex min-h-11 items-center underline">{{ container.name }}</Link>
            </li>
        </ul>
    </section>

    <section class="mt-8">
        <h3 class="text-lg font-semibold">{{ t('agents.events_heading') }}</h3>
        <p v-if="events.length === 0" class="mt-1 text-sm text-slate-700">{{ t('agents.events_empty') }}</p>
        <ul v-else class="mt-2 flex flex-col gap-1 text-sm">
            <li v-for="event in events" :key="event.id" class="flex flex-wrap gap-x-3">
                <code>{{ event.action }}</code>
                <span class="text-slate-700">{{ eventDate(event.at).text }}</span>
            </li>
        </ul>
    </section>

    <section class="mt-10">
        <UiButton variant="danger" @click="confirming = true">{{ t('agents.disconnect') }}</UiButton>
    </section>

    <UiConfirmDialog
        :open="confirming"
        :heading="t('agents.disconnect_heading')"
        :body="t('agents.disconnect_body')"
        :confirm-label="t('agents.disconnect')"
        tone="danger"
        :pending="disconnecting"
        @confirm="disconnect"
        @cancel="confirming = false"
    />
</template>
