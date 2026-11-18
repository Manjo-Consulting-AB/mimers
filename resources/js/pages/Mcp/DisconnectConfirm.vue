<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Issue 400 · bekräftelsen innan mejlets signerade bortkoppling, se
 * App\Http\Controllers\Mcp\DisconnectAgentController och [[ADR-0087
 * MCP-server för agenter]] Riktning 12.
 *
 * **Länken i mejlet raderar ingenting när den hämtas.** Mejlskannrar och
 * länkförhandsvisningar hämtar länkar automatiskt; utan den här sidan hade de
 * kunnat koppla bort en legitim agent. Knappen postar till samma signerade
 * URL som sidan kom in på, så signaturen är kvar hela vägen — och en kapad
 * session kan inte koppla bort något utan den.
 *
 * Sidan ritas utan inloggning. Texten kommer ur `lang/en/ui.php` under
 * `mcp.disconnect_confirm`; agentens namn är en propp och aldrig en sträng
 * här ([[ADR-0021 Frontendteknik]] § Beslut).
 */
const props = defineProps({
    agentName: { type: String, required: true },
    actionUrl: { type: String, required: true },
});

const { t } = useTranslations();
const form = useForm({});

function disconnect() {
    form.post(props.actionUrl);
}
</script>

<template>
    <Head :title="t('mcp.disconnect_confirm.title')" />

    <div class="mx-auto max-w-lg">
        <h1 class="text-2xl font-semibold">{{ t('mcp.disconnect_confirm.heading', { agent: props.agentName }) }}</h1>

        <p class="mt-4 text-slate-700">{{ t('mcp.disconnect_confirm.body', { agent: props.agentName }) }}</p>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <button
                type="button"
                :disabled="form.processing"
                class="inline-flex min-h-11 items-center justify-center rounded bg-accent px-4 font-medium text-ink-on-accent disabled:opacity-50"
                @click="disconnect"
            >
                {{ form.processing ? t('common.pending.default') : t('mcp.disconnect_confirm.confirm') }}
            </button>

            <Link href="/" class="inline-flex min-h-11 items-center text-accent hover:underline">
                {{ t('mcp.disconnect_confirm.cancel') }}
            </Link>
        </div>
    </div>
</template>
