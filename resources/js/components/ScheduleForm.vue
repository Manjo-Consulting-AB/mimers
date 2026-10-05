<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import UiButton from './UiButton.vue';
import UiInput from './UiInput.vue';
import UiSelect from './UiSelect.vue';
import UiTextarea from './UiTextarea.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret för ett schema — se issue 63a § Beslut 1, 3, 4 och 5.
 *
 * Egen komponent och inte ett formulär i var sin sida, av samma skäl som
 * ItemForm ligger i components/: skapandet och redigeringen bär samma sju
 * fält, och den enda skillnaden är startvärdena, metoden och knappens ord.
 * Två avskrifter hade glidit isär vid första ändringen.
 *
 * Komponenten ligger i components/ och inte i pages/: Inertia löser upp
 * sidnamn mot `./pages/**\/*.vue` (resources/js/app.js), så en .vue-fil bland
 * vyerna blir en sida som går att rendera utan att någon rutt pekar på den.
 * Den här är ingen sida — den är en del av Create och Edit.
 *
 * **`is_active` finns inte här.** Pausen är en knapp i listan och inte ett
 * kryss i formuläret (Beslut 6), så `UpdateScheduleRequest`s `sometimes` på
 * fältet är hela mekanismen: formuläret ritar det aldrig och PATCH:en från
 * listan bär bara det.
 *
 * **Skillnaden mellan de tre typerna förklaras med exempel, inte med ordet**
 * (Beslut 3). Meningen under väljaren är [[ADR-0005 Schema och förekomst]]:s
 * egna exempel — försäkringen som förnyas 1 januari och oljebytet tolv månader
 * efter förra bytet — och den byter med valet. Ett val mellan tre ord utan
 * förklaring blir ett val någon gör fel en gång och sedan aldrig ändrar.
 *
 * **`anchor_date` frågas för alla tre typerna, och etiketten följer typen**
 * (Beslut 4). Fältet är alltid synligt men rubriken byter: *Startpunkt i
 * serien* för `fixed`, *Första förfallodatum* annars. Vid REDIGERING av ett
 * icke-`fixed` schema (issue 702 § Beslut 3) är fältet förifyllt med den
 * öppna förekomstens förfall och rubriken är *Nästa förfallodatum*. Ett
 * obligatoriskt fält som ser valfritt ut är ett 422 användaren inte förstår.
 *
 * **Datumet är frivilligt för en engångsuppgift** (M26 · issue 236 § Beslut 1,
 * [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 3).
 * `StoreScheduleRequest`/`UpdateScheduleRequest` kräver det bara för `fixed`
 * och `interval`, och för `none` saknar fältet `required` och bär en knapp
 * som tömmer det. Samma regler delas med `/api`. Masken säger samma sak som
 * servern: ett tomt datum på en engångsuppgift är ett svar och inte ett fel.
 *
 * **Listan väljs i samma formulär** (M26 · issue 236 § Beslut 2; M27 · issue
 * 244). När ett schema SKAPAS frågar *List* efter listan den första
 * förekomsten hamnar i och skickar `gtd_list`; vid REDIGERING visas *Default
 * list for new occurrences* och skickar schemats `default_gtd_list`. Bara det
 * ena fältet finns i `fields`, så en skapande POST bär aldrig
 * `default_gtd_list` och en PATCH aldrig `gtd_list`.
 *
 * **`inbox` är inget val** ([[ADR-0054 Inboxen]] § 5): inboxen är en plats och
 * inte en lista, och en uppgift som skapas på ett inbox-item är obearbetad —
 * den får sin lista först när den bearbetas. Förvalet är därför *Next*, och
 * *Not set* är borta ur förvalsfältet: ett schema utanför inboxen har alltid
 * ett förval, och `waiting` blir aldrig ett (ADR-0052 § 2).
 *
 * **Intervallfälten döljs OCH nollställs när `none` väljs** (Beslut 4).
 * `prohibited_if:recurrence_type,none` i den delade FormRequesten avvisar dem
 * annars — och det är `/api`:s egen regel, inte en vyn hittat på. Därför
 * `watch` och inte bara `v-if`.
 *
 * **`lead_days` förklaras med vad den gör** (Beslut 5): det är
 * `visible_from`, fältet som avgör när uppgiften dyker upp i todo-listan.
 * Standardvärdet är serverns — modellens `lead_days` är 0 i `$attributes` och
 * i migrationen — och vyn hittar inget eget.
 *
 * Ingen egen validering: reglerna bor i StoreScheduleRequest/
 * UpdateScheduleRequest, som delas rakt av med `/api`, och felen renderas av
 * FormField vid sitt fält ([[ADR-0021 Frontendteknik]]).
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /* Schemat som redigeras, eller null när ett nytt schema skapas. */
    schedule: { type: Object, default: null },
    /*
     * Den öppna förekomstens förfallodag, ur ScheduleController::edit() (issue
     * 702 § Beslut 3). Redigeringsformuläret förifyller `anchor_date` med den
     * — det är nästa gång uppgiften förfaller, inte seriens start — och en
     * orörd sparning skriver samma datum som förekomsten redan har. Undantaget
     * är `fixed`, där fältet är seriens startpunkt och behåller schemats eget
     * datum.
     */
    openDueAt: { type: String, default: null },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

/*
 * De tre typerna i väljarens ordning. `none` är förvalt: ett schema som
 * läggs till utan att någon tänker på återkommandet ska bli en engångsuppgift
 * och inte en serie som öppnar förekomster i evighet. Den som vill ha ett
 * intervall väljer det aktivt — och får då förklaringen (Beslut 3).
 */
const fields = {
    title: props.schedule?.title ?? '',
    notes: props.schedule?.notes ?? '',
    recurrence_type: props.schedule?.recurrence_type ?? 'none',
    interval_unit: props.schedule?.interval_unit ?? null,
    interval_count: props.schedule?.interval_count ?? null,
    /*
     * `fixed` är seriens startpunkt i kalendern och behåller schemats eget
     * datum; för de andra är fältet nästa förfallodatum och förifylls med den
     * öppna förekomstens förfall (Beslut 3).
     */
    anchor_date: props.schedule?.recurrence_type === 'fixed'
        ? (props.schedule.anchor_date ?? '')
        : (props.openDueAt ?? props.schedule?.anchor_date ?? ''),
    // Fältet heter *Reminder* i vyn och `lead_days` i koden (Beslut 3):
    // nyckeln `item.schedule.form.reminder` är etiketten, `lead_days` är
    // kolumnen. Utan ett datum ritas fältet inte — men värdet står kvar.
    lead_days: props.schedule?.lead_days ?? 0,
    /*
     * Listan (Beslut 2). Bara det ena fältet finns i formuläret: skapande
     * skickar `gtd_list` och redigering `default_gtd_list`, så POST:en och
     * PATCH:en bär varsitt fält och aldrig båda. Förvalet är `next`: en uppgift
     * som skapas på ett riktigt item börjar där (ADR-0054 § 5), och ett schema
     * utan förval visar `next` — alternativet *Not set* finns inte längre.
     */
    ...(props.schedule === null
        ? { gtd_list: 'next' }
        : { default_gtd_list: props.schedule.default_gtd_list ?? 'next' }),
};

const form = useForm(fields);

watch(() => form.recurrence_type, (type) => {
    if (type !== 'none') {
        return;
    }

    form.interval_unit = null;
    form.interval_count = null;
});

const interval = computed(() => form.recurrence_type !== 'none');

/* Datumet krävs bara av de återkommande typerna (Beslut 1). */
const dateRequired = computed(() => form.recurrence_type !== 'none');

/*
 * Rubriken följer typen OCH ytan (issue 702 § Beslut 3): för `fixed` är
 * datumet seriens startpunkt; för de andra är det första förfallodatumet i
 * skapandeformuläret och NÄSTA förfallodatum när ett befintligt schema
 * redigeras, där fältet är förifyllt med den öppna förekomstens förfall.
 */
const anchorDateLabel = computed(() => {
    if (form.recurrence_type === 'fixed') {
        return t('item.schedule.form.anchor_date_fixed');
    }

    return props.schedule === null
        ? t('item.schedule.form.anchor_date')
        : t('item.schedule.form.anchor_date_next');
});

/*
 * Knappens ord byter medan servern svarar (issue 68a § Beslut 4 och 5): en
 * knapp vars etikett står still medan svaret är på väg ser ut som en död sida.
 */
const submitLabel = computed(() => (form.processing
    ? t('common.pending.default')
    : (props.schedule === null ? t('item.schedule.create.submit') : t('item.schedule.update.submit'))));

function submit() {
    const url = `/containers/${props.containerUlid}/items/${props.itemUlid}/schedules`;

    if (props.schedule === null) {
        form.post(url, { onError: focusFirstError });

        return;
    }

    form.patch(`${url}/${props.schedule.ulid}`, { onError: focusFirstError });
}
</script>

<template>
    <form class="flex max-w-lg flex-col gap-4" @submit.prevent="submit">
        <FormField
            v-slot="{ describedBy }"
            :label="t('item.schedule.form.title')"
            id="title"
            :error="form.errors.title"
        >
            <UiInput
                id="title"
                v-model="form.title"
                :described-by="describedBy"
                name="title"
                required
            />
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.schedule.form.notes')"
            id="notes"
            :error="form.errors.notes"
        >
            <UiTextarea
                id="notes"
                v-model="form.notes"
                :described-by="describedBy"
                name="notes"
                :rows="3"
            />
        </FormField>

        <!--
            Listan (Beslut 2). Skapande frågar efter den första förekomstens
            lista (`gtd_list`), redigering efter schemats förval för nästa
            förekomst (`default_gtd_list`) — bara det ena fältet finns i
            `fields`, så en POST och en PATCH bär aldrig samma nyckel.
            Alternativen är `todo.list.*`, samma ord som kolumnens värden —
            utom `inbox`, som är en plats och inte en lista (ADR-0054 § 5).
        -->
        <FormField
            v-if="schedule === null"
            v-slot="{ describedBy }"
            :label="t('item.schedule.form.gtd_list')"
            id="gtd_list"
            :error="form.errors.gtd_list"
        >
            <UiSelect
                id="gtd_list"
                v-model="form.gtd_list"
                :described-by="describedBy"
                name="gtd_list"
            >
                <option value="next">{{ t('todo.list.next') }}</option>
                <option value="waiting">{{ t('todo.list.waiting') }}</option>
                <option value="someday">{{ t('todo.list.someday') }}</option>
            </UiSelect>
        </FormField>

        <FormField
            v-else
            v-slot="{ describedBy }"
            :label="t('item.schedule.form.default_gtd_list')"
            id="default_gtd_list"
            :error="form.errors.default_gtd_list"
        >
            <UiSelect
                id="default_gtd_list"
                v-model="form.default_gtd_list"
                :described-by="describedBy"
                name="default_gtd_list"
            >
                <option value="next">{{ t('todo.list.next') }}</option>
                <option value="someday">{{ t('todo.list.someday') }}</option>
            </UiSelect>

            <p class="text-body text-ink-muted">{{ t('item.schedule.form.default_gtd_list_hint') }}</p>
        </FormField>

        <!--
            Återkommandet: tre val och meningen som förklarar det valda
            (Beslut 3). Förklaringen står UNDER väljaren och byter med valet —
            den som väljer fel ser skillnaden direkt, utan att läsa en ADR.
        -->
        <FormField
            v-slot="{ describedBy }"
            :label="t('item.schedule.form.recurrence_type')"
            id="recurrence_type"
            :error="form.errors.recurrence_type"
        >
            <UiSelect
                id="recurrence_type"
                v-model="form.recurrence_type"
                :described-by="describedBy"
                name="recurrence_type"
            >
                <option value="none">{{ t('item.schedule.form.type.none') }}</option>
                <option value="fixed">{{ t('item.schedule.form.type.fixed') }}</option>
                <option value="interval">{{ t('item.schedule.form.type.interval') }}</option>
            </UiSelect>

            <p class="text-body text-ink-muted">
                {{ t(`item.schedule.form.recurrence_${form.recurrence_type}`) }}
            </p>
        </FormField>

        <!--
            Intervallfälten finns bara när typen kräver dem, och nollställs när
            den inte gör det (Beslut 4): `prohibited_if` i den delade
            FormRequesten avvisar dem annars, och ett fält som skickas med men
            inte syns är ett 422 användaren inte kan förklara.
        -->
        <template v-if="interval">
            <FormField
                v-slot="{ describedBy }"
                :label="t('item.schedule.form.interval_count')"
                id="interval_count"
                :error="form.errors.interval_count"
            >
                <UiInput
                    id="interval_count"
                    v-model="form.interval_count"
                    :described-by="describedBy"
                    type="number"
                    name="interval_count"
                    min="1"
                    required
                />
            </FormField>

            <FormField
                v-slot="{ describedBy }"
                :label="t('item.schedule.form.unit')"
                id="interval_unit"
                :error="form.errors.interval_unit"
            >
                <UiSelect
                    id="interval_unit"
                    v-model="form.interval_unit"
                    :described-by="describedBy"
                    name="interval_unit"
                    required
                >
                    <option :value="null">{{ t('item.schedule.form.unit_none') }}</option>
                    <option value="day">{{ t('item.schedule.form.units.day') }}</option>
                    <option value="week">{{ t('item.schedule.form.units.week') }}</option>
                    <option value="month">{{ t('item.schedule.form.units.month') }}</option>
                    <option value="year">{{ t('item.schedule.form.units.year') }}</option>
                </UiSelect>
            </FormField>
        </template>

        <!--
            Startpunkten, alltid synlig (Beslut 4). Rubriken följer typen: för
            `fixed` är datumet seriens startpunkt i kalendern, för de andra är
            det första gången uppgiften förfaller. `required` följer SAMMA
            regel som FormRequesten (Beslut 1): bara `fixed` och `interval`
            kräver ett datum, och en engångsuppgift får lämnas tom — därför
            knappen som tömmer fältet.
        -->
        <FormField
            v-slot="{ describedBy }"
            :label="anchorDateLabel"
            id="anchor_date"
            :error="form.errors.anchor_date"
        >
            <div class="flex items-center gap-2">
                <UiInput
                    id="anchor_date"
                    v-model="form.anchor_date"
                    :described-by="describedBy"
                    type="date"
                    name="anchor_date"
                    :required="dateRequired"
                />

                <button
                    v-if="form.anchor_date"
                    type="button"
                    :aria-label="t('item.schedule.form.anchor_date_clear')"
                    class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control border border-border text-ink-muted outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    @click="form.anchor_date = ''"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="h-4 w-4"
                        aria-hidden="true"
                    >
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </FormField>

        <!--
            Påminnelsen (Beslut 3). Utan ett datum gör `lead_days` ingenting —
            `visible_from` räknas bara fram när det finns ett `due_at` — så
            fältet döljs i stället för att stå kvar och lova en notis som
            aldrig kommer. Värdet står kvar i formuläret och skickas med, så
            att ett datum som sätts tillbaka får sin gamla påminnelse.
        -->
        <FormField
            v-if="form.anchor_date"
            v-slot="{ describedBy }"
            :label="t('item.schedule.form.reminder')"
            id="lead_days"
            :error="form.errors.lead_days"
        >
            <UiInput
                id="lead_days"
                v-model="form.lead_days"
                :described-by="describedBy"
                type="number"
                name="lead_days"
                min="0"
                max="365"
                class="self-start"
            />

            <p class="text-body text-ink-muted">{{ t('item.schedule.form.lead_days_hint') }}</p>
        </FormField>

        <UiButton type="submit" :pending="form.processing" class="self-start">
            {{ submitLabel }}
        </UiButton>
    </form>
</template>
