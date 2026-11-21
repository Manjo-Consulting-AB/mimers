<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Issue 400 · godkännandet av en MCP-klient, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 12 och 19 och App\Http\Controllers\Mcp\AuthorizeController.
 *
 * **Sidan ritar valen, den godkänner ingenting.** Formuläret postar till
 * Passports `POST /oauth/authorize` med webbläsarens egen omdirigering —
 * svaret är en 302 till klientens `redirect_uri`, och Inertias router hade
 * följt den som ett XHR. Därför en vanlig form med `_token` i ett dolt fält,
 * och därför en egen `submitting`-flagga i stället för `useForm`.
 *
 * **Tre lägen.** Gratiskontot ser Pro-kravet och ingen godkännandeknapp.
 * Samma klient igen ser att agenten redan finns — anslutningen återanvänder
 * raden. En ny klient väljer mellan en ny agent och att ersätta en befintlig;
 * har kontot redan taket är bara ersättningen kvar.
 *
 * **Sidan ritas utan appens skal.** Skalets sidolista och klocka hämtar sina
 * valfria proppar med `router.reload({ only: [...] })`, och en omladdning är
 * en GET mot sidans egen URL — här `/oauth/authorize?…`. Passport kör då
 * godkännandet en gång till och lägger en ny `authToken` i sessionen, medan
 * formuläret bär kvar den första: knappen gav 403 (`InvalidAuthTokenException`)
 * och felsidan därefter Inertias JSON-fel. Ingen layout betyder inga
 * omladdningar, så sessionens token och formulärets förblir samma.
 *
 * **Texten kommer ur `lang/en/ui.php` under `mcp.authorize`.** Ingenting här
 * är en användarvänd sträng ([[ADR-0021 Frontendteknik]] § Beslut).
 */
const props = defineProps({
    client: { type: Object, required: true },
    authToken: { type: String, required: true },
    csrfToken: { type: String, required: true },
    requiresPro: { type: Boolean, required: true },
    limitReached: { type: Boolean, required: true },
    reconnect: { type: Boolean, required: true },
    agentCount: { type: Number, required: true },
    maxAgents: { type: Number, required: true },
    agents: { type: Array, required: true },
});

const { t } = useTranslations();
const submitting = ref(false);

/*
 * Förvalet: en ny agent när det finns plats, annars den första befintliga
 * agenten som ersätts. Användaren kan byta val i listan.
 */
const choice = ref(
    props.limitReached && props.agents.length > 0
        ? `replace:${props.agents[0].ulid}`
        : 'new',
);

const canApprove = computed(
    () => !props.requiresPro && (props.reconnect || !props.limitReached || props.agents.length > 0),
);
</script>

<template>
    <Head :title="t('mcp.authorize.title')" />

    <main class="mx-auto max-w-xl px-4 py-10">
        <h1 class="text-2xl font-semibold">{{ t('mcp.authorize.heading', { client: props.client.name }) }}</h1>

        <p v-if="props.requiresPro" class="mt-4 text-slate-700">{{ t('mcp.authorize.pro_body', { client: props.client.name }) }}</p>
        <p v-else class="mt-4 text-slate-700">{{ t('mcp.authorize.intro', { client: props.client.name }) }}</p>

        <template v-if="props.requiresPro">
            <h2 class="mt-6 text-lg font-medium">{{ t('mcp.authorize.pro_heading') }}</h2>
            <p class="mt-2 text-sm text-slate-600">{{ t('mcp.authorize.no_access') }}</p>

            <Link href="/settings/plan" class="mt-6 inline-flex min-h-11 items-center text-accent hover:underline">
                {{ t('mcp.authorize.pro_link') }}
            </Link>
        </template>

        <template v-else>
            <p class="mt-2 text-sm text-slate-600">{{ t('mcp.authorize.no_access') }}</p>

            <Link href="/settings/agents" class="mt-2 inline-flex min-h-11 items-center text-accent hover:underline">
                {{ t('mcp.authorize.agents_link') }}
            </Link>

            <template v-if="props.reconnect">
                <h2 class="mt-6 text-lg font-medium">{{ t('mcp.authorize.reconnect_heading') }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ t('mcp.authorize.reconnect_body') }}</p>
            </template>

            <form v-if="canApprove" method="POST" action="/oauth/authorize" class="mt-6 flex flex-col gap-4" @submit="submitting = true">
                <input id="authorize-client-id" type="hidden" name="client_id" :value="props.client.id" />
                <input id="authorize-auth-token" type="hidden" name="auth_token" :value="props.authToken" />
                <input id="authorize-csrf" type="hidden" name="_token" :value="props.csrfToken" />

                <fieldset v-if="!props.reconnect && props.agents.length > 0" class="flex flex-col gap-3">
                    <legend class="text-sm font-medium">{{ t('mcp.authorize.choose_heading', { client: props.client.name }) }}</legend>

                    <div class="flex items-start gap-2">
                        <input
                            id="agent-choice-new"
                            v-model="choice"
                            type="radio"
                            name="agent_choice"
                            value="new"
                            :disabled="props.limitReached"
                            class="mt-1"
                        />
                        <label for="agent-choice-new" class="text-sm text-slate-700">{{ t('mcp.authorize.choose_new') }}</label>
                    </div>

                    <p v-if="props.limitReached" class="text-sm text-slate-600">
                        {{ t('mcp.authorize.choose_new_disabled', { max: props.maxAgents }) }}
                    </p>

                    <p class="text-sm font-medium">{{ t('mcp.authorize.choose_replace') }}</p>

                    <div v-for="agent in props.agents" :key="agent.ulid" class="flex items-start gap-2">
                        <input
                            :id="`agent-choice-${agent.ulid}`"
                            v-model="choice"
                            type="radio"
                            name="agent_choice"
                            :value="`replace:${agent.ulid}`"
                            class="mt-1"
                        />
                        <label :for="`agent-choice-${agent.ulid}`" class="text-sm text-slate-700">
                            {{ agent.paused ? t('mcp.authorize.replace_paused', { name: agent.name }) : agent.name }}
                        </label>
                    </div>
                </fieldset>

                <button
                    type="submit"
                    :disabled="submitting"
                    class="inline-flex min-h-11 items-center justify-center rounded bg-accent px-4 font-medium text-ink-on-accent disabled:opacity-50"
                >
                    {{ submitting ? t('common.pending.default') : t('mcp.authorize.approve', { client: props.client.name }) }}
                </button>
            </form>

            <template v-else>
                <h2 class="mt-6 text-lg font-medium">{{ t('mcp.authorize.limit_heading', { max: props.maxAgents }) }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ t('mcp.authorize.limit_body', { max: props.maxAgents, client: props.client.name }) }}</p>

                <Link href="/settings/agents" class="mt-6 inline-flex min-h-11 items-center text-accent hover:underline">
                    {{ t('mcp.authorize.agents_link') }}
                </Link>
            </template>
        </template>

        <form method="POST" action="/oauth/authorize" class="mt-4" @submit="submitting = true">
            <input id="deny-method" type="hidden" name="_method" value="DELETE" />
            <input id="deny-csrf" type="hidden" name="_token" :value="props.csrfToken" />
            <input id="deny-client-id" type="hidden" name="client_id" :value="props.client.id" />
            <input id="deny-auth-token" type="hidden" name="auth_token" :value="props.authToken" />

            <button
                type="submit"
                :disabled="submitting"
                class="inline-flex min-h-11 items-center justify-center rounded border border-border bg-surface px-4 font-medium text-ink disabled:opacity-50"
            >
                {{ submitting ? t('common.pending.default') : t('mcp.authorize.deny') }}
            </button>
        </form>
    </main>
</template>
