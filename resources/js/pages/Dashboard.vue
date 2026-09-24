<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import DashboardTasksPanel from '../components/DashboardTasksPanel.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Dashboarden — startsidan efter inloggning, se issue 122 och
 * docs/Design/main.jpeg. Panelen *Kommande uppgifter* är den första av M19:s
 * paneler; brickorna (124), kostnaderna (125), händelserna (126), klockan
 * (127) och informationsytan (128) kommer efter.
 *
 * **Sidan ritar paneler och äger ingenting själv.** Den här filen är
 * monteringspunkten: varje panel är sin egen komponent med sin egen propp, och
 * den här sidan skickar vidare vad servern gav den — den räknar ingenting,
 * filtrerar ingenting och formulerar ingen fråga. Det är hela skälet att M19:s
 * issues kan byggas parallellt: en ny panel krockar om en rad här och en rad i
 * App\Http\Controllers\DashboardController, inte om varandras innehåll.
 *
 * **Komponenten hette `Dashboard` redan före issue 122** — då var den todo-vyn,
 * och `/dashboard` var dess adress. Rutten och ruttnamnet står kvar därför att
 * ramverket skickar en nyinloggad användare hit (issue 64 § Beslut 1), och
 * sidnamnet är kontraktet mot `import.meta.glob` över `pages/` (issue 51).
 *
 * **Uppgifterna kommer ur samma urval som `/tasks`.** Servern klipper de fem
 * första i App\Http\Controllers\DashboardController, och panelen formulerar
 * inget eget `where` — se resources/js/components/DashboardTasksPanel.vue.
 */
const props = defineProps({
    /* Högst fem rader ur todo-urvalet, i serverns ordning. */
    tasks: { type: Array, required: true },
    /* Har användaren någon container alls? Skiljer de två tomma lägena åt. */
    hasContainers: { type: Boolean, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <AppLayout>
        <Head :title="t('dashboard.title')" />

        <h1 class="text-2xl font-semibold">{{ t('dashboard.heading') }}</h1>

        <div class="mt-8">
            <DashboardTasksPanel :tasks="props.tasks" :has-containers="props.hasContainers" />
        </div>
    </AppLayout>
</template>
