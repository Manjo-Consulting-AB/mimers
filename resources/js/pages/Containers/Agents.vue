<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import ContainerSettingsLayout from '../../layouts/ContainerSettingsLayout.vue';
import ContainerAgentsSection from '../../components/ContainerAgentsSection.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({
    layout: (props) => [
        [AppLayout, {}],
        [ContainerLayout, { hero: 'compact', container: props.container }],
        [ContainerSettingsLayout, { container: props.container }],
    ],
});

/*
 * Containerns agenter, se M69 · issue 1483 och
 * [[ADR-0087 MCP-server för agenter]] Riktning 4 och 6.
 *
 * Avsnittet låg tidigare längst ned på containerns inställningssida (issue
 * 408). Här har det en egen sida, samma mönster som kontots `/settings/agents`:
 * raden i inställningsmenyn (`containerSections.js`, nyckeln `agents`) leder
 * hit, och sidan svarar bara när avsnittet finns.
 *
 * **Sidan ritar avsnittet och ingenting annat.** Formuläret, skrivningarna och
 * nivåerna bor i `ContainerAgentsSection`, som är orörd — sidan bär bara
 * layouten, rubriken och proppen `section`, precis som de andra
 * inställningssidorna bär sina sektioner.
 *
 * `section` kommer ur App\Http\Controllers\ContainerAgentController::section()
 * och är aldrig `null` här: rutten svarar 404 i stället för att rita en tom
 * sida när avsnittet inte är tillgängligt (Pro hos både användaren och
 * ägarkontot, och `mcp.enabled`).
 */
defineProps({
    container: { type: Object, required: true },
    section: { type: Object, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <Head :title="t('container.nav.agents')" />

    <h1 class="text-2xl font-semibold">{{ t('container.nav.agents') }}</h1>

    <ContainerAgentsSection :container-ulid="container.ulid" :section="section" />
</template>
