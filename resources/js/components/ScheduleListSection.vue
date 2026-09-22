<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import OpenOccurrence from './OpenOccurrence.vue';
import { scheduleUrl } from './occurrencePresentation.js';
import { recurrenceLabel } from './schedulePresentation.js';
import { useRelativeDate } from '../composables/useRelativeDate.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Schemana på itemets detaljvy, se issue 63a § Beslut 1, 2, 6, 7 och 8, och
 * issue 63b § Beslut 1, 2 och 4.
 *
 * **Schemat är regeln och förekomsten den enskilda gången** ([[ADR-0005
 * Schema och förekomst]]). Den här sektionen listar REGLERNA — titeln,
 * återkommandet, nästa förfall och en markering för pausade — och bär sedan
 * 63b:s avbockning: den öppna förekomstens tre datum och de två handlingar
 * som stänger den. Historiken bor på schemats egen sida, dit raden länkar
 * (Beslut 1).
 *
 * **Avbockningen ligger här med flit** (Beslut 1). Det är produktens
 * vanligaste skrivning, och den ska kosta en knapptryckning från itemet —
 * inte en navigering. Formuläret är resources/js/components/OpenOccurrence.vue,
 * samma komponent som schemats sida ritar: två avskrifter av samma
 * skrivning hade glidit isär.
 *
 * **Nästa förfall är den öppna förekomstens datum** och räknas aldrig om i
 * vyn: servern skickar `openOccurrences` (schemanas ULID → förekomsten, eller
 * `null`) byggd ur den eager-laddade relationen, och ett schema utan öppen
 * förekomst får sin egen mening i stället för ett tomt fält (63a § Beslut 1).
 * Sedan 63b är det förekomsten och inte datumet — avbockningen behöver
 * ULID:n, `overdue` och `visible_from`, och `due_at` är ett av dess fält.
 *
 * **Egen komponent och inte rader i Show.vue**, av samma skäl som
 * ItemAttachmentSection och ItemLinkSection ligger här: sektionen bär sina
 * egna skrivningar och sina egna fel, så en nekad avbockning inte färgar
 * resten av sidan.
 *
 * **`can` är presentation** (63a § Beslut 7). Varje knapp ritas efter samma
 * grind som kontrollern prövar — `create` för att lägga till, `update` för
 * att ändra, pausa OCH bocka av, `delete` för att radera — men det som
 * avgör är `Gate::authorize()` i App\Http\Controllers\ScheduleController och
 * App\Http\Controllers\ScheduleOccurrenceController. En användare med bara
 * `read` ser ingen skrivyta alls; en `create`-mottagare ser *Nytt schema* men
 * ingen radåtgärd; en `write`-mottagare ser pausen och avbockningen men inte
 * raderingen.
 *
 * **Pausen är en PATCH som bär bara `is_active`** (63a § Beslut 6), och
 * raderingen en DELETE. Båda går mot samma rutt som redigeringen, och båda
 * behåller scrolläget: en paus är en liten ändring i en lista man står mitt
 * i.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /* Schemana ur App\Http\Resources\ScheduleResource, sorterade på titel. */
    schedules: { type: Array, required: true },
    /*
     * Schemats ULID → den öppna förekomsten ur ScheduleOccurrenceResource,
     * eller `null` för ett schema som inte har någon (issue 63b § Beslut 1).
     */
    openOccurrences: { type: Object, required: true },
    /* Containerns ägarkonto — avbockningens förval när användaren är medlem. */
    containerAccount: { type: String, default: '' },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const { dueDate } = useRelativeDate();

/*
 * Raderna: återkommandet formulerat i ord, den öppna förekomsten som den kom
 * från servern, och dess datum ur datumregeln (issue 104) — relativt inom
 * gränsen, absolut bortom den. Nästa förfall är ett förfallodatum som alla
 * andra och får samma form: en egen formatering här är precis den blandning
 * regeln finns för att ta bort. `occurrence.overdue` är serverns fält och går
 * in i regeln — raden räknar aldrig försenat själv.
 *
 * `done` är `none`-uppgiftens sista tillstånd (Beslut 8 och "Klart när"): en
 * engångsuppgift vars förekomst är stängd öppnar ingen ny, och raden ska säga
 * att uppgiften är klar i stället för att visa ett tomt förfallodatum. En
 * PAUSAD rad har redan sin egen mening och förväxlas inte med den.
 *
 * `dueLabel` är den färdiga meningen: en relativ rad bär sin egen preposition
 * ("Overdue by 3 days"), medan det absoluta datumet får radens ord runt sig
 * ("Next due: 14 Oct 2026").
 */
const rows = computed(() => props.schedules.map((schedule) => {
    const occurrence = props.openOccurrences[schedule.ulid] ?? null;
    const due = occurrence ? dueDate(occurrence.due_at, occurrence.overdue) : null;

    return {
        ...schedule,
        recurrence: recurrenceLabel(t, schedule),
        occurrence,
        dueLabel: due === null
            ? null
            : (due.relative ? due.text : t('item.schedule.next_due', { date: due.text })),
        done: occurrence === null && schedule.recurrence_type === 'none' && schedule.is_active,
    };
}));

function url(schedule) {
    return scheduleUrl(props.containerUlid, props.itemUlid, schedule.ulid);
}

function editUrl(schedule) {
    return `${url(schedule)}/edit`;
}

/*
 * `pending` är radens vänteläge (issue 68a § Beslut 4 och 5): pausen och
 * raderingen är båda små mutationer i samma lista, och flaggan bär den
 * anropade radens ULID — listan ritar flera scheman ur samma komponent, och
 * bara knapparna på raden man tryckte på ska stängas och byta ord medan
 * servern svarar (Beslut 4).
 */
const pending = ref(null);

function toggle(schedule) {
    router.patch(url(schedule), { is_active: !schedule.is_active }, {
        preserveScroll: true,
        onStart: () => { pending.value = schedule.ulid; },
        onFinish: () => { pending.value = null; },
    });
}

/*
 * Raderingen. Bekräftelsen är webbläsarens egen dialog med serverns mening ur
 * `lang/` — ingen modal komponent och ingen sträng i JavaScript, samma mönster
 * som detaljvyns radering och bilagesektionen.
 *
 * Texten säger att schemat och dess kommande förekomster tas bort och nämner
 * varken papperskorgen eller de 30 dagarna (Beslut 8): raden mjukraderas, men
 * papperskorgen listar fyra typer och `schedule` är inte en av dem (issue 20a
 * § Beslut 3). Att lova en väg tillbaka som inte finns är värre än att inte
 * lova någon.
 */
function destroy(schedule) {
    if (! window.confirm(t('item.schedule.destroy_confirm'))) {
        return;
    }

    router.delete(url(schedule), {
        preserveScroll: true,
        onStart: () => { pending.value = schedule.ulid; },
        onFinish: () => { pending.value = null; },
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

        <p v-if="rows.length === 0" class="mt-2 text-sm text-slate-600">
            {{ t('item.schedule.empty') }}
        </p>

        <ul v-else class="mt-2 flex flex-col gap-2">
            <!--
                En pausad rad ligger KVAR i listan, gråtonad: pausen är
                avsiktligt reversibel och synlig (Beslut 6). Att dölja den
                hade gjort ett pausat schema omöjligt att hitta och därmed
                omöjligt att återuppta.
            -->
            <li
                v-for="schedule in rows"
                :key="schedule.ulid"
                class="flex flex-col gap-2 rounded border border-slate-300 bg-white px-4 py-3"
                :class="schedule.is_active ? null : 'text-slate-600'"
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-medium">{{ schedule.title }}</span>

                    <span class="text-sm">{{ schedule.recurrence }}</span>

                    <span class="text-sm">
                        {{ schedule.dueLabel
                            ? schedule.dueLabel
                            : schedule.done
                                ? t('item.schedule.occurrence.done')
                                : t('item.schedule.no_next_due') }}
                    </span>

                    <span
                        v-if="! schedule.is_active"
                        class="rounded bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700"
                    >
                        {{ t('item.schedule.paused') }}
                    </span>
                </div>

                <p v-if="! schedule.is_active" class="text-sm">
                    {{ t('item.schedule.paused_note') }}
                </p>

                <div class="flex flex-wrap gap-4 text-sm">
                    <!-- Historiken och förekomsterna bor på schemats egen sida
                         (Beslut 1). Länken ritas för alla som får se raden —
                         att läsa historiken är samma grind som att läsa
                         schemat. -->
                    <Link
                        :href="url(schedule)"
                        class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                    >
                        {{ t('item.schedule.occurrence.view') }}
                    </Link>

                    <Link
                        v-if="can.update"
                        :href="editUrl(schedule)"
                        class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                    >
                        {{ t('item.schedule.edit') }}
                    </Link>

                    <button
                        v-if="can.update"
                        type="button"
                        :disabled="pending === schedule.ulid"
                        class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                        @click="toggle(schedule)"
                    >
                        {{ pending === schedule.ulid ? t('common.pending.default') : (schedule.is_active ? t('item.schedule.pause') : t('item.schedule.resume')) }}
                    </button>

                    <button
                        v-if="can.delete"
                        type="button"
                        :disabled="pending === schedule.ulid"
                        class="inline-flex min-h-11 items-center font-medium text-red-700 hover:underline"
                        @click="destroy(schedule)"
                    >
                        {{ pending === schedule.ulid ? t('common.pending.default') : t('item.schedule.destroy') }}
                    </button>
                </div>

                <!-- Den öppna förekomsten med avbockningen (Beslut 1). Ritas
                     bara när det finns en rad att stänga; en stängd
                     engångsuppgift säger det i raden ovan i stället. -->
                <OpenOccurrence
                    v-if="schedule.occurrence"
                    :container-ulid="containerUlid"
                    :item-ulid="itemUlid"
                    :schedule-ulid="schedule.ulid"
                    :occurrence="schedule.occurrence"
                    :container-account="containerAccount"
                    :can="can"
                />
            </li>
        </ul>
    </section>
</template>
