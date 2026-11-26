<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import AgentRulesEditor from '../../components/AgentRulesEditor.vue';
import HelpLink from '../../components/HelpLink.vue';
import { useRelativeDate } from '../../composables/useRelativeDate.js';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: [AppLayout, SettingsLayout] });

/*
 * Användarens anslutna agenter, se issue 407 och [[ADR-0087 MCP-server för
 * agenter]] Riktning 12. En rad per agent; raden länkar till agentens sida
 * där rättigheterna sätts. Utan agenter förklarar sidan vad MCP är och ger
 * adressen att lägga in i klienten.
 */
defineProps({
    /* `{ulid, name, connectedAt, lastUsedAt, containerCount, paused}` */
    agents: { type: Array, required: true },

    /* Adressen klienten ska få. */
    mcpUrl: { type: String, required: true },

    /* Användarens regler, [[ADR-0100 Agentregler]]: `{text, revisions}`. */
    rules: { type: Object, required: true },
});

const { t } = useTranslations();
const { eventDate } = useRelativeDate();
</script>

<template>
    <Head :title="t('agents.title')" />

    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <h2 class="text-2xl font-semibold">{{ t('agents.heading') }}</h2>

        <span class="flex items-center">
            <HelpLink context="account.agents" icon-only class="sm:hidden" />
            <HelpLink context="account.agents" class="max-sm:hidden" />
        </span>
    </div>
    <p class="mt-2 text-sm text-slate-700">{{ t('agents.intro') }}</p>

    <AgentRulesEditor
        id="user-agent-rules"
        action="/settings/agents/rules"
        :heading="t('agents.rules.user_heading')"
        :hint="t('agents.rules.user_hint')"
        :text="rules.text"
        :revisions="rules.revisions"
    />

    <section v-if="agents.length === 0" class="mt-6 rounded border border-slate-300 p-4 text-sm">
        <p>{{ t('agents.empty') }}</p>
        <p class="mt-2">
            <span class="font-medium">{{ t('agents.address_label') }}:</span>
            <code class="ml-1 break-all">{{ mcpUrl }}</code>
        </p>
    </section>

    <ul v-else class="mt-6 flex flex-col gap-3">
        <li v-for="agent in agents" :key="agent.ulid" class="rounded border border-slate-300 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <Link :href="`/settings/agents/${agent.ulid}`" class="inline-flex min-h-11 items-center font-medium underline">
                    {{ agent.name }}
                </Link>
                <span v-if="agent.paused" class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-900">
                    {{ t('agents.paused') }}
                </span>
            </div>

            <dl class="mt-1 grid grid-cols-1 gap-x-6 gap-y-1 text-sm text-slate-700 sm:grid-cols-3">
                <div>
                    <dt class="font-medium">{{ t('agents.connected_at') }}</dt>
                    <dd>{{ eventDate(agent.connectedAt).text }}</dd>
                </div>
                <div>
                    <dt class="font-medium">{{ t('agents.last_used') }}</dt>
                    <dd>{{ agent.lastUsedAt ? eventDate(agent.lastUsedAt).text : t('agents.never_used') }}</dd>
                </div>
                <div>
                    <dt class="font-medium">{{ t('agents.containers_count') }}</dt>
                    <dd>{{ agent.containerCount }}</dd>
                </div>
            </dl>
        </li>
    </ul>
</template>
