<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import UiButton from './UiButton.vue';
import UiCheckbox from './UiCheckbox.vue';
import UiSelect from './UiSelect.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Avsnittet *Agents* i containerns inställningar, se issue 408 och
 * [[ADR-0087 MCP-server för agenter]] Riktning 4 och 6.
 *
 * **Allt är avslaget från start.** En agent utan sparade nivåer visar bara
 * knappen *Switch on*; den öppnar grupperna men sparar ingenting förrän ett val
 * görs. **Varje val sparas direkt**, utan *Save*: switchen för allt skickar
 * `{all: true}` och servern sparar kontots nivåer som de är; en nivå per grupp
 * skickar hela kartan. Att stänga av switchen, eller *Switch off*, raderar
 * agentens rader i containern.
 *
 * Nivåerna i väljaren går bara upp till `limits[grupp]` — det lägsta av
 * kontots nivå och, i någon annans container, ägarens tak. Servern kapar
 * ändå: vyn är presentation och grinden är actionen.
 *
 * Propen är `null` när avsnittet inte ska ritas (Gratis hos användaren eller
 * ägaren, eller MCP av); då ritas ingenting här.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    section: { type: Object, required: true },
});

const { t } = useTranslations();

const base = `/containers/${props.containerUlid}/agents`;
const opened = ref({});
const pending = ref(false);
const errors = ref({});

const levelsUpTo = (limit) => (limit === null ? [] : props.section.levels.slice(0, props.section.levels.indexOf(limit) + 1));

function send(agent, method, data) {
    errors.value = { ...errors.value, [agent.ulid]: null };

    router[method](`${base}/${agent.ulid}`, data, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
        onError: (bag) => {
            errors.value = { ...errors.value, [agent.ulid]: Object.values(bag)[0] ?? t('error.generic') };
        },
    });
}

function setAll(agent, on) {
    if (on) {
        send(agent, 'put', { all: true });
    } else {
        send(agent, 'delete', {});
    }
}

function setLevel(agent, group, value) {
    send(agent, 'put', { levels: { ...agent.levels, [group]: value === '' ? null : value } });
}

function switchOff(agent) {
    opened.value = { ...opened.value, [agent.ulid]: false };
    send(agent, 'delete', {});
}
</script>

<template>
    <section class="mt-12 border-t border-border pt-6" data-section="agents">
        <h2 class="text-title">{{ t('agents.container.heading') }}</h2>
        <p class="mt-1 text-body text-ink-muted">{{ t('agents.container.intro') }}</p>

        <p v-if="!section.own && section.ownerAllows && section.ceiling" class="mt-2 text-meta text-ink-muted">
            {{ t('agents.container.owner_ceiling', { level: t(`agents.level.${section.ceiling}`) }) }}
        </p>

        <p v-if="section.agents.length === 0" class="mt-3 text-body text-ink-muted">{{ t('agents.container.empty') }}</p>

        <p v-else-if="!section.ownerAllows" role="status" class="mt-3 text-body text-ink">
            {{ t('agents.container.owner_disallows') }}
        </p>

        <ul v-else class="mt-4 flex flex-col gap-3">
            <li v-for="agent in section.agents" :key="agent.ulid" class="flex flex-col gap-3 rounded-card border border-border bg-surface p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-body font-medium text-ink">{{ agent.name }}</span>
                    <span class="text-meta text-ink-muted">{{ agent.enabled ? t('agents.container.on') : t('agents.container.off') }}</span>
                </div>

                <UiButton
                    v-if="!agent.enabled && !opened[agent.ulid]"
                    type="button"
                    class="self-start"
                    @click="opened = { ...opened, [agent.ulid]: true }"
                >
                    {{ t('agents.container.switch_on') }}
                </UiButton>

                <template v-else>
                    <div class="flex items-center gap-2 text-body">
                        <UiCheckbox
                            :id="`agent-all-${agent.ulid}`"
                            :model-value="agent.all"
                            :disabled="pending"
                            @update:model-value="(on) => setAll(agent, on)"
                        />
                        <label :for="`agent-all-${agent.ulid}`" class="inline-flex min-h-11 items-center">{{ t('agents.container.everything') }}</label>
                    </div>
                    <p class="text-meta text-ink-muted">{{ t('agents.container.everything_hint') }}</p>

                    <p class="text-body font-medium text-ink">{{ t('agents.container.per_group') }}</p>

                    <div v-for="group in section.groups" :key="group" class="flex flex-col gap-1">
                        <label :for="`agent-${agent.ulid}-${group}`" class="text-body text-ink">
                            {{ t(`agents.group.${group}`) }}
                        </label>
                        <UiSelect
                            :id="`agent-${agent.ulid}-${group}`"
                            :model-value="agent.levels[group] ?? ''"
                            :disabled="pending || agent.limits[group] === null"
                            class="self-start"
                            @update:model-value="(value) => setLevel(agent, group, value)"
                        >
                            <option value="">{{ t('agents.level_none') }}</option>
                            <option v-for="level in levelsUpTo(agent.limits[group])" :key="level" :value="level">
                                {{ t(`agents.level.${level}`) }}
                            </option>
                        </UiSelect>
                    </div>

                    <UiButton v-if="agent.enabled" type="button" variant="danger" class="self-start" @click="switchOff(agent)">
                        {{ t('agents.container.switch_off') }}
                    </UiButton>
                </template>

                <p v-if="errors[agent.ulid]" role="alert" class="text-body text-danger">{{ errors[agent.ulid] }}</p>
            </li>
        </ul>
    </section>
</template>
