<script setup>
import { Link } from '@inertiajs/vue3';
import UiCard from './UiCard.vue';
import TodoRow from './TodoRow.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Uppgiftspanelen på dashboarden, se issue 122 och
 * docs/Design/main.jpeg (panelen *Kommande uppgifter*, uppe till höger).
 *
 * **Panelen ställer ingen fråga.** Raderna kommer färdiga i `tasks`, ur
 * App\Actions\Schedule\ListTodo — samma anrop som `/tasks` gör — och den här
 * filen formulerar inget eget `where`, sorterar inte om och räknar ingen
 * grupp. Ordningen är serverns, och den är densamma som todo-vyns: de fem
 * första raderna i `due_at`-ordning, alltså försenat först.
 *
 * **Raden är `TodoRow`** (issue 64 § Beslut 4 och 5), inte en kopia av den.
 * En panel som ritade sin egen rad hade varit den andra sanningen om vad en
 * uppgift består av — samma item, samma schema, samma container, samma
 * avbockning — och den hade glidit isär från listan den lovar är densamma.
 * Avbockningen fungerar därför också här: `complete`-rutten svarar `back()`,
 * och dashboarden ritas om.
 *
 * **Ramen är `UiCard`** (issue 99), som varje annan panel: rubriken är
 * kortets rubrikrad, och länken till `/tasks` sitter i kortets åtgärdsplats —
 * mockupens *Visa alla*. Panelen ritar ingen egen rubrik och ingen egen ram.
 *
 * **De två tomma lägena är todo-vyns, ordagrant** (issue 64 § Beslut 6, issue
 * 122): samma två meningar ur `todo.empty.*`. `hasContainers` kommer ur samma
 * anrop som raderna, så den som inte har någon container alls får länken till
 * att skapa en, och den som har containers utan öppna uppgifter får "inget att
 * göra just nu". Ingen av meningarna nämner ett tal eller antyder att rader
 * dolts — panelen visar fem, och den som vill se resten följer länken.
 *
 * **Länken ritas också när listan är tom.** Den är vägen till todo-vyn, inte
 * en knapp för listan: en tom panel utan väg vidare vore en återvändsgränd.
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
    <UiCard>
        <template #heading>{{ t('dashboard.tasks.heading') }}</template>

        <template #action>
            <Link href="/tasks" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
                {{ t('dashboard.tasks.view_all') }}
            </Link>
        </template>

        <p v-if="props.tasks.length === 0" class="text-slate-700">
            <template v-if="props.hasContainers">{{ t('todo.empty.nothing') }}</template>

            <template v-else>
                {{ t('todo.empty.no_containers') }}
                <Link href="/containers/create" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
                    {{ t('todo.empty.create') }}
                </Link>
            </template>
        </p>

        <ul v-else class="flex flex-col divide-y divide-slate-200">
            <TodoRow v-for="task in props.tasks" :key="task.ulid" :entry="task" />
        </ul>
    </UiCard>
</template>
