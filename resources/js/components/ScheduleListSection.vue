<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { formatDateOnly } from './itemPresentation.js';
import { recurrenceLabel } from './schedulePresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Schemana på itemets detaljvy, se issue 63a § Beslut 1, 2, 6, 7 och 8.
 *
 * **Schemat är regeln och förekomsten den enskilda gången** ([[ADR-0005
 * Schema och förekomst]]). Den här sektionen listar REGLERNA — titeln,
 * återkommandet, nästa förfall och en markering för pausade. Förekomsterna,
 * avbockningen och historiken är 63b och bor inte här.
 *
 * **Nästa förfall är den öppna förekomstens datum** och räknas aldrig om i
 * vyn: servern skickar `nextDue` (schemanas ULID → datum) byggd ur den
 * eager-laddade relationen, och ett schema utan öppen förekomst får sin egen
 * mening i stället för ett tomt fält (Beslut 1).
 *
 * **Egen komponent och inte rader i Show.vue**, av samma skäl som
 * ItemAttachmentSection och ItemLinkSection ligger här: sektionen bär sina
 * egna skrivningar och sina egna fel, så en nekad paus inte färgar resten av
 * sidan.
 *
 * **`can` är presentation** (Beslut 7). Varje knapp ritas efter samma grind
 * som kontrollern prövar — `create` för att lägga till, `update` för att
 * ändra OCH pausa, `delete` för att radera — men det som avgör är
 * `Gate::authorize()` i App\Http\Controllers\ScheduleController. En
 * användare med bara `read` ser ingen skrivyta alls; en `create`-mottagare
 * ser *Nytt schema* men ingen radåtgärd; en `write`-mottagare ser pausen men
 * inte raderingen.
 *
 * **Pausen är en PATCH som bär bara `is_active`** (Beslut 6), och raderingen
 * en DELETE. Båda går mot samma rutt som redigeringen, och båda behåller
 * scrolläget: en paus är en liten ändring i en lista man står mitt i.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /* Schemana ur App\Http\Resources\ScheduleResource, sorterade på titel. */
    schedules: { type: Array, required: true },
    /* Schemats ULID → den öppna förekomstens förfallodatum, eller null. */
    nextDue: { type: Object, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const locale = computed(() => page.props.locale);

/*
 * Raderna: återkommandet formulerat i ord och datumet formaterat utan att
 * flyttas över en tidszon — `due_at` är en DATE-kolumn ([[Scheman och
 * uppgifter]] § schedule_occurrence), och `formatDateOnly()` bygger datumet i
 * lokal tid i stället för att tolka strängen som UTC.
 */
const rows = computed(() => props.schedules.map((schedule) => ({
    ...schedule,
    recurrence: recurrenceLabel(t, schedule),
    due: formatDateOnly(props.nextDue[schedule.ulid] ?? null, locale.value),
})));

function url(schedule) {
    return `/containers/${props.containerUlid}/items/${props.itemUlid}/schedules/${schedule.ulid}`;
}

function editUrl(schedule) {
    return `${url(schedule)}/edit`;
}

function toggle(schedule) {
    router.patch(url(schedule), { is_active: !schedule.is_active }, { preserveScroll: true });
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

    router.delete(url(schedule), { preserveScroll: true });
}
</script>

<template>
    <section class="mt-10">
        <div class="flex flex-wrap items-baseline gap-4">
            <h2 class="text-lg font-semibold">{{ t('item.schedule.heading') }}</h2>

            <Link
                v-if="can.create"
                :href="`/containers/${containerUlid}/items/${itemUlid}/schedules/create`"
                class="text-sm font-medium text-blue-700 hover:underline"
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
                :class="schedule.is_active ? null : 'text-slate-500'"
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-medium">{{ schedule.title }}</span>

                    <span class="text-sm">{{ schedule.recurrence }}</span>

                    <span class="text-sm">
                        {{ schedule.due
                            ? t('item.schedule.next_due', { date: schedule.due })
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
                    <Link
                        v-if="can.update"
                        :href="editUrl(schedule)"
                        class="font-medium text-blue-700 hover:underline"
                    >
                        {{ t('item.schedule.edit') }}
                    </Link>

                    <button
                        v-if="can.update"
                        type="button"
                        class="font-medium text-blue-700 hover:underline"
                        @click="toggle(schedule)"
                    >
                        {{ schedule.is_active ? t('item.schedule.pause') : t('item.schedule.resume') }}
                    </button>

                    <button
                        v-if="can.delete"
                        type="button"
                        class="font-medium text-red-700 hover:underline"
                        @click="destroy(schedule)"
                    >
                        {{ t('item.schedule.destroy') }}
                    </button>
                </div>
            </li>
        </ul>
    </section>
</template>
