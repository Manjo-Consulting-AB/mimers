<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Issue 400 · kvittot på mejlets signerade bortkoppling, se
 * App\Http\Controllers\Mcp\DisconnectAgentController och [[ADR-0087
 * MCP-server för agenter]] Riktning 12.
 *
 * Sidan ritas utan inloggning: länken finns just för att den som fått ett
 * mejl om en agent hon inte kände igen ska kunna stänga av den. Den säger
 * bara vad som hände — agentens namn finns inte kvar att visa, raden är
 * raderad.
 */
const { t } = useTranslations();
</script>

<template>
    <Head :title="t('mcp.disconnected.title')" />

    <div class="mx-auto max-w-lg py-12 text-center">
        <h1 class="text-2xl font-semibold">{{ t('mcp.disconnected.heading') }}</h1>

        <p class="mt-3 text-slate-700">{{ t('mcp.disconnected.body') }}</p>

        <Link href="/" class="mt-6 inline-flex min-h-11 items-center text-accent hover:underline">{{ t('common.home') }}</Link>
    </div>
</template>
