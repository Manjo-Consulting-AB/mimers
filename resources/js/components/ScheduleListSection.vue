<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import TaskGroup from './TaskGroup.vue';
import TodoRow from './TodoRow.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Itemets uppgiftsflik — listan av FÖREKOMSTER (M24 · issue 227, Tonys beslut
 * 2026-10-04, docs/Design/tasks-item.png).
 *
 * **Fliken visar samma grupper och samma rad som containerns flik** (Beslut 1
 * och 4): *Overdue → Today → This week → Upcoming → Done*, ur
 * App\Actions\Schedule\ListItemTasks, som frågar `ListTodo`s rader och grupper.
 * Gruppen räknas aldrig i vyn — en `computed` som jämför `due_at` mot
 * `Date.now()` hade flyttat en uppgift till fel hög så fort klientens klocka
 * gick fel (issue 64 § Beslut 3).
 *
 * **REGELLISTAN är borta.** Fram till issue 227 listade sektionen itemets
 * scheman med pausa, redigera och radera och en avbockning per rad. Reglerna
 * bor nu på schemats egen sida (issue 226), dit radens titel länkar, och
 * avbockningen är `TodoRow`s snabbavbockning (Beslut 5). Ingen `OpenOccurrence`
 * och ingen egen skrivväg här.
 *
 * **Reglaget *Include child items* står i querysträngen** (Beslut 3).
 * Kryssrutan postar ingen kropp: `router.get` mot SAMMA rutt som sidan ligger
 * på, med `children=0` när den är av och utan parametern när den är på — så ett
 * avgränsat läge är en adress man kan spara, dela och backa ur (issue 59a
 * § Beslut 1). Servern äger svaret, och `props.includeChildren` speglas med
 * `watch` så att kryssrutan följer servern efter en bakåtknapp eller en
 * omladdning. Vänteläget stänger kontrollen medan svaret är på väg (issue 68a
 * § Beslut 4). Samma konstruktion som underhållsfiltret i
 * resources/js/pages/Containers/Tasks.vue.
 *
 * **En tom grupp ritas inte** (Beslut 4) — varken rubrik eller lista, och det
 * gäller också *Done*. Är allt tomt ritas `item.schedule.empty` i stället: en
 * rubrik utan rader hade sagt att gruppen finns men är tom, och en lista utan
 * innehåll är samma svar utan brus.
 *
 * **`can` är presentation** (Beslut 4). Bara `can.create` läses här — knappen
 * *New task* — och rutten prövar `create` på nytt. Pausen, redigeringen och
 * raderingen ritas på schemats sida, bakom sina egna flaggor.
 *
 * Ordningen i *Done* kommer färdig från servern, och raden är `TodoRow` som de
 * öppna (M24 · issue 230, Beslut 5): komponenten ser `completed_at` och skriver
 * tidsstämpeln med `eventDate()` som en HÄNDELSE och inte som ett
 * förfallodatum (issue 104) — en avbockning är något som gjordes, inte något
 * som förfaller. Ingen sträng står i JavaScript (issue 52 · [[ADR-0013 Språk
 * och i18n]]): rubriken, knappen, reglaget och grupprubrikerna kommer ur `t()`.
 *
 * **Rubriken med antalet och ihopfällningen ritas av `TaskGroup`** (M24 ·
 * issue 231): sektionen skickar in gruppens namn, antalet och tonen, och
 * komponenten ritar knappen, chevronen och raderna — samma komponent som
 * containerns flik och `/tasks`.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /*
     * De fyra öppna grupperna, i ritningsordning: overdue, today, this_week,
     * upcoming. Varje rad är `ListTodo`s rad — `TodoEntryResource` plus
     * `account`, `can`, `paused` och `blocked` — och vyn ritar den som den kom.
     */
    groups: { type: Object, required: true },
    /* Avbockade förekomster på itemet och ättlingarna, nyast först, högst tjugo. */
    completed: { type: Array, required: true },
    /* Reglagets läge, ur `?children`. Förvalet är PÅ. */
    includeChildren: { type: Boolean, required: true },
    /* `{ create }` — *New task*-länken. Rutten prövar samma grind. */
    can: { type: Object, required: true },
});

const { t } = useTranslations();

/* Kryssrutans läge, speglat ur proppen — se docblocken ovan. */
const onlyChildren = ref(props.includeChildren);

/* Vänteläget för reglaget: en enda kontroll, en enda flagga (issue 68a). */
const pending = ref(false);

watch(
    () => props.includeChildren,
    (value) => {
        onlyChildren.value = value;
    },
);

/*
 * Är allt tomt? Frågan ställs på serverns svar och inte på en egen räkning:
 * grupperna är redan avgränsade av reglaget, och vyn lägger inget villkor till
 * dem (Beslut 4).
 */
const hasAnyTask = computed(
    () => props.completed.length > 0 || Object.values(props.groups).some((entries) => entries.length > 0),
);

/*
 * Flikens egen adress. `router.get` lägger parametern ovanpå querysträngen, så
 * `tab=schedules` står kvar och `children=0` läggs till eller utelämnas.
 */
const tabUrl = () => `/containers/${props.containerUlid}/items/${props.itemUlid}?tab=schedules`;

function apply() {
    const params = onlyChildren.value ? {} : { children: 0 };

    router.get(tabUrl(), params, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}
</script>

<template>
    <section class="mt-10">
        <div class="flex flex-wrap items-baseline gap-4">
            <h2 class="text-lg font-semibold">{{ t('item.schedule.heading') }}</h2>

            <Link
                v-if="can.create"
                :href="`/containers/${containerUlid}/items/${itemUlid}/schedules/create`"
                class="inline-flex min-h-11 items-center text-sm font-medium text-blue-700 hover:underline"
            >
                {{ t('item.schedule.add') }}
            </Link>
        </div>

        <!--
            Reglaget (Beslut 3). En kryssruta och ingen skicka-knapp: valet är
            ett värde i adressen, och svaret ritar servern. `:disabled` medan
            svaret är på väg, så kontrollen inte ser död ut (issue 68a
            § Beslut 4).
        -->
        <label
            for="item-tasks-children"
            class="mt-4 flex min-h-11 w-fit items-center gap-2 text-sm font-medium text-slate-800"
        >
            <input
                id="item-tasks-children"
                v-model="onlyChildren"
                type="checkbox"
                name="children"
                :disabled="pending"
                @change="apply"
            >
            {{ t('item.schedule.include_children') }}
        </label>

        <!--
            Det tomma läget (Beslut 4): först när allt är tomt — de fyra öppna
            grupperna OCH *Done* — annars hade en lista med bara avbockade
            rader sagt att itemet saknar uppgifter.
        -->
        <p v-if="!hasAnyTask" class="mt-2 text-sm text-slate-600">
            {{ t('item.schedule.empty') }}
        </p>

        <!--
            Listan (Beslut 4): grupperna i den ordning servern gav dem, sedan
            *Done*. En sektion ritas bara när den har rader, och rubriken byggs
            ur gruppens EGET namn — samma nyckel som containerns flik. Rubriken
            med antalet och ihopfällningen ritas av TaskGroup (M24 · issue
            231), samma komponent som de två andra ytorna.
        -->
        <div v-else class="mt-6 flex flex-col gap-8">
            <template v-for="(entries, group) in groups" :key="group">
                <TaskGroup
                    v-if="entries.length > 0"
                    :heading="t(`todo.group.${group}`)"
                    :count="entries.length"
                    :tone="group === 'overdue' ? 'danger' : null"
                >
                    <TodoRow
                        v-for="entry in entries"
                        :key="entry.ulid"
                        :entry="entry"
                        :show-container="false"
                    />
                </TaskGroup>
            </template>

            <!--
                *Done* (Beslut 4). Raden bär samma upplysningar som de öppna —
                schemats titel, itemet, ett datum — men datumet är
                `completed_at`, och avbockningsknappen ritas inte: det finns
                ingenting kvar att bocka av. Rubriken är containerns fliks ord,
                samma grupp och samma nyckel.
            -->
            <TaskGroup
                v-if="completed.length > 0"
                :heading="t('container.tasks.done')"
                :count="completed.length"
            >
                <TodoRow
                    v-for="entry in completed"
                    :key="entry.ulid"
                    :entry="entry"
                    :show-container="false"
                />
            </TaskGroup>
        </div>
    </section>
</template>
