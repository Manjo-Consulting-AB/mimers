<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { formatDateOnly } from './itemPresentation.js';
import { scheduleUrl } from './occurrencePresentation.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * En beroendesektion på schemats sida, se issue 63c § Beslut 1, 2, 3, 4, 5, 6
 * och 7.
 *
 * **Beroenden finns på två nivåer och betyder olika saker.** Ett
 * SCHEMA-beroende är en regel — "impellern kan inte bytas innan motorn är
 * servad" — och ärvs av varje ny förekomst. Ett FÖREKOMST-beroende är ett
 * undantag — "den här gången måste jag måla innan jag sjösätter" — och gäller
 * bara den omgången ([[ADR-0005 Schema och förekomst]] § Motivering). Samma
 * komponent ritar båda, men som TVÅ sektioner med var sin rubrik (Beslut 2):
 * rubrikerna bär skillnaden, och en gemensam lista med en typkolumn hade
 * krävt att användaren först förstod modellen.
 *
 * **Motparten är alltid ett schema i listan** (Beslut 3). På förekomstnivån är
 * `ulid` den valda förekomstens ULID — det är den servern tar emot — men
 * raden och väljaren visar schemats titel och dess item, för det är vad
 * användaren känner igen. Ett schema heter "Byt impeller" och betyder
 * ingenting utan sitt item.
 *
 * **Varje rad länkar vidare till motpartens eget schema** (Beslut 5). Ingen
 * graf och ingen kedjevy: den som vill följa en kedja klickar sig vidare.
 *
 * **`satisfied` kommer från servern och avgör hur raden ritas** (Beslut 4).
 * Fältet finns bara på förekomstnivån — `depends_on.status` säger om
 * motparten är öppen — och vyn räknar aldrig fram det själv. En uppfylld rad
 * är avbockad och grå; en öppen rad är det som blockerar och syns som sådan
 * INNAN användaren försöker bocka av och möter spärren i
 * App\Actions\Schedule\CloseOccurrence. En blockerad uppgift som ser klickbar
 * ut är ett fel man bara upptäcker genom att göra det.
 *
 * **Ta bort är att bryta kopplingen och ingenting annat** (Beslut 7). Både
 * schemana och både förekomsterna finns kvar, och bekräftelsen säger det.
 *
 * **Fälten heter `depends_on`**, samma kropp som `/api` tar emot:
 * `StoreScheduleDependencyRequest` och `StoreOccurrenceDependencyRequest` delas
 * rakt av, och ett domänfel ur App\Actions\Schedule\DependSchedule/
 * DependOccurrence blir ett fältfel på just den nyckeln — aldrig en JSON-kropp.
 *
 * Varje sträng kommer ur `lang/` (Beslut 9). Nycklarna väljs på `level`, som
 * är `schedule` eller `occurrence` och samma två ord som de två kontrollernas
 * nivåer.
 */
const props = defineProps({
    /* `schedule` eller `occurrence` — väljer rubrik, tomtext och bekräftelse. */
    level: { type: String, required: true },
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /*
     * Beroenderadena, normaliserade av sidan: `{ulid, title, item: {ulid,
     * name}, schedule_ulid, due, satisfied}`. `satisfied` är `null` på
     * schemanivån — där finns ingen motpart att vara klar eller inte.
     */
    rows: { type: Array, required: true },
    /* Insamlings-URL:en; raderingen är samma URL plus motpartens ULID. */
    url: { type: String, required: true },
    /* Motparterna användaren får ändra, i samma pärm (§ Beslut 3). */
    counterparts: { type: Array, required: true },
    /* Falskt när schemat saknar öppen förekomst: då finns inga undantag. */
    active: { type: Boolean, default: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const page = usePage();

const locale = computed(() => page.props.locale);

const title = (key) => t(`item.schedule.dependency.${key}_${props.level}`);

/* Två sektioner på samma sida: id:t måste skilja dem, annars pekar etiketten
 * på fel inmatning (issue 68b). */
const fieldId = computed(() => `dependency-counterpart-${props.level}`);

const form = useForm({ depends_on: '' });

function submit() {
    form.post(props.url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: focusFirstError,
    });
}

/*
 * Upp-brytningen. Bekräftelsen är webbläsarens egen dialog med serverns mening
 * ur `lang/` — ingen modal komponent och ingen sträng i JavaScript, samma
 * mönster som ItemLinkSection och bilagesektionen.
 *
 * `router.delete` och inte en <Link method="delete">: bekräftelsen måste kunna
 * AVBRYTA navigeringen.
 *
 * `pending` är radens vänteläge (issue 68a § Beslut 4 och 5): knappen är
 * stängd och byter ord medan servern svarar. Flaggan bär den anropade radens
 * ULID och inte en boolean — listan ritar flera rader ur samma komponent, och
 * bara knappen man tryckte på ska gå i vänteläge (Beslut 4).
 */
const pending = ref(null);

function remove(row) {
    if (! window.confirm(title('remove_confirm'))) {
        return;
    }

    router.delete(`${props.url}/${row.ulid}`, {
        preserveScroll: true,
        onStart: () => { pending.value = row.ulid; },
        onFinish: () => { pending.value = null; },
    });
}
</script>

<template>
    <section class="mt-10">
        <h2 class="text-lg font-semibold">{{ title('heading') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ title('note') }}</p>

        <p v-if="! active" class="mt-2 text-sm text-slate-600">
            {{ t('item.schedule.dependency.occurrence_none') }}
        </p>

        <template v-else>
            <p v-if="rows.length === 0" class="mt-2 text-sm text-slate-600">
                {{ title('empty') }}
            </p>

            <ul v-else class="mt-2 flex flex-col gap-2">
                <li
                    v-for="row in rows"
                    :key="row.ulid"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded border border-slate-300 bg-white px-4 py-2"
                >
                    <!-- Motpartens item och titel: ett schema betyder ingenting
                         utan sitt item (Beslut 3), och raden länkar vidare till
                         motpartens eget schema (Beslut 5). -->
                    <Link
                        :href="scheduleUrl(containerUlid, itemUlid, row.schedule_ulid)"
                        class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                    >
                        {{ row.item.name }}
                    </Link>

                    <span class="text-sm text-slate-900">{{ row.title }}</span>

                    <span v-if="row.due" class="text-sm text-slate-600">
                        {{ t('item.schedule.occurrence.due', { date: formatDateOnly(row.due, locale) }) }}
                    </span>

                    <!-- Uppfylld eller blockerande, ur serverns `satisfied`
                         (Beslut 4). Bara förekomstnivån har fältet: på
                         schemanivån finns ingen motpart att vara klar. -->
                    <span
                        v-if="row.satisfied !== null"
                        class="rounded px-2 py-0.5 text-xs font-medium"
                        :class="row.satisfied
                            ? 'bg-slate-200 text-slate-700'
                            : 'bg-amber-100 text-amber-900'"
                    >
                        {{ row.satisfied
                            ? t('item.schedule.dependency.satisfied')
                            : t('item.schedule.dependency.blocking') }}
                    </span>

                    <button
                        v-if="can.update"
                        type="button"
                        :disabled="pending === row.ulid"
                        class="inline-flex min-h-11 items-center text-sm text-red-700 hover:underline"
                        @click="remove(row)"
                    >
                        {{ pending === row.ulid ? t('common.pending.default') : t('item.schedule.dependency.remove') }}
                    </button>
                </li>
            </ul>

            <template v-if="can.update">
                <p v-if="counterparts.length === 0" class="mt-4 text-sm text-slate-600">
                    {{ t('item.schedule.dependency.no_counterparts') }}
                </p>

                <form v-else class="mt-4 flex max-w-lg flex-col gap-3" @submit.prevent="submit">
                    <div class="flex flex-col gap-1">
                        <label :for="fieldId" class="text-sm font-medium text-slate-800">
                            {{ t('item.schedule.dependency.counterpart') }}
                        </label>

                        <!-- Motparten visas med sitt item först: listan sorteras
                             på itemets namn och därefter schemats titel, och
                             ordningen ska gå att läsa. -->
                        <select
                            :id="fieldId"
                            v-model="form.depends_on"
                            :aria-describedby="form.errors.depends_on ? `${fieldId}-error` : undefined"
                            name="depends_on"
                            required
                            class="self-start rounded border border-slate-300 bg-white px-3 py-2"
                        >
                            <option value="">{{ t('item.schedule.dependency.counterpart_none') }}</option>
                            <option
                                v-for="candidate in counterparts"
                                :key="candidate.ulid"
                                :value="candidate.ulid"
                            >
                                {{ candidate.item.name }} · {{ candidate.title }}
                            </option>
                        </select>

                        <!-- De sex domänfelen hamnar alla här (Beslut 6):
                             `dependency_self`, `dependency_cycle`,
                             `dependency_not_in_container` och `not_open`
                             handlar om vilket schema eller vilken förekomst
                             som valdes. -->
                        <p
                            v-if="form.errors.depends_on"
                            :id="`${fieldId}-error`"
                            role="alert"
                            class="text-sm text-red-700"
                        >
                            {{ form.errors.depends_on }}
                        </p>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                    >
                        {{ form.processing ? t('common.pending.default') : t('item.schedule.dependency.submit') }}
                    </button>
                </form>
            </template>
        </template>
    </section>
</template>
