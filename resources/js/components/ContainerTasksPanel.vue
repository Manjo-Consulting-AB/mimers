<script setup>
import { Link } from '@inertiajs/vue3';
import UiCard from './UiCard.vue';
import TodoRow from './TodoRow.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Uppgiftspanelen på containerns översikt, se issue 172 ·
 * [[ADR-0050 Desktopdesignen]] § 7 och docs/Design/container.jpeg.
 *
 * **Panelen ställer ingen fråga.** Raderna kommer färdiga i `tasks`, ur
 * App\Actions\Schedule\ListTodo::forContainer() — samma kod, samma ordning och
 * samma rad som `/tasks` visar, avgränsad till containern och klippt till fem.
 * Den här filen formulerar inget eget `where`, sorterar inte om och räknar
 * ingen grupp: en panel med en egen fråga hade varit den andra sanningen om
 * vad containerns uppgifter är, och den hade glidit isär från listan
 * ([[ADR-0024 Tunna controllers och actions]]).
 *
 * **Raden är `TodoRow`** och inte en kopia, precis som på dashboarden: samma
 * item, samma schema, samma container, samma avbockning. `complete`-rutten
 * svarar `back()`, så översikten ritas om när en uppgift bockas av härifrån.
 *
 * **Ramen är `UiCard`** (issue 99): rubriken är kortets rubrikrad och länken
 * sitter i kortets åtgärdsplats. Är `tasks` tom är det växeln för framtida
 * uppgifter som är av — servern har redan avgränsat listan — och panelen säger
 * samma sak som todo-vyn gör i samma läge, ordagrant.
 *
 * **Länken pekar på `/tasks` till dess att containerns uppgiftsflik finns.**
 * Fliken är issue 174, och den som bygger den byter målet här: raden ska leda
 * till containerns uppgifter, och `/tasks` är hela listan. Att rita en länk
 * till en flik som inte finns vore en återvändsgränd, och att utelämna länken
 * vore detsamma.
 *
 * **Ingen sträng står i filen** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * rubriken, länkens ord och den tomma raden kommer ur `t()`. Den tomma raden
 * är todo-vyns egen nyckel — samma ord om samma sak, och en kopia här hade
 * varit en andra sanning om vad tomt betyder.
 */
const props = defineProps({
    /* Högst fem rader ur containerns todo-urval, i serverns ordning. */
    tasks: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <UiCard>
        <template #heading>{{ t('container.overview.tasks') }}</template>

        <template #action>
            <Link href="/tasks" class="inline-flex min-h-11 items-center text-accent hover:underline">
                {{ t('container.overview.view_all') }}
            </Link>
        </template>

        <p v-if="props.tasks.length === 0" class="text-slate-700">
            {{ t('todo.empty.nothing') }}
        </p>

        <ul v-else class="flex flex-col divide-y divide-slate-200">
            <TodoRow v-for="task in props.tasks" :key="task.ulid" :entry="task" />
        </ul>
    </UiCard>
</template>
