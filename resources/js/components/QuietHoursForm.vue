<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Tysta timmar, se issue 65a § Beslut 4, 5 och 6.
 *
 * **ETT formulär och EN PATCH mot /settings/notifications/quiet-hours.**
 * Preferenserna har sitt eget formulär och sin egen skrivning, och den
 * uppdelningen är hela skälet att sidan har två knappar: ett gemensamt
 * formulär hade behövt slå ihop två requests och två felmängder (Beslut 1).
 *
 * **`<input type="time">` ger `H:i`**, vilket är exakt vad
 * UpdateQuietHoursRequest vill ha — inget tidszons- eller
 * klockslagsbibliotek, och ingen formatering i JavaScript (Beslut 4).
 *
 * **Tomma fält betyder inga tysta timmar.** Båda fälten är `nullable`, och
 * sidan säger det i ord i stället för att visa `--:--`: ett tomt fält är
 * svaret, inte ett saknat värde. Båda fälten skickas alltid — requesten
 * kräver paret, och en PATCH med bara den ena sidan är inget fönster.
 *
 * **Fönstret får passera midnatt.** 22:00–07:00 är det normala fallet och
 * sparas som det står; App\Support\Notification\QuietHours äger tolkningen.
 * Texten säger det, så att ingen tror att hon skrivit fel.
 *
 * **Tidszonen visas men ändras inte här** (Beslut 4). `$user->timezone` är
 * nullbar och betyder "följ kontot"; vyn hittar aldrig på ett värde i det
 * fallet, utan säger att zonen följer kontot och länkar till profilen där den
 * sätts. Ett `timezone`-fält i kroppen passerar valideringen och lämnas orört
 * av kontrollern — två ställen att ändra samma sak är ett ställe för mycket.
 *
 * **Tysta timmar fördröjare, de tar inte bort** (Beslut 5): `available_at`
 * sätts när notisen skapas och kön plockar aldrig rader vars `available_at`
 * ligger i framtiden. Meningen står här för att alternativet är värre — en
 * användare som tror att tysta timmar stänger av notiser stänger av dem
 * i stället.
 */
const props = defineProps({
    quietHoursStart: { type: String, default: null },
    quietHoursEnd: { type: String, default: null },
    timezone: { type: String, default: null },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    quiet_hours_start: props.quietHoursStart,
    quiet_hours_end: props.quietHoursEnd,
});

/*
 * Fälten beskrivs av sina egna fel OCH av de två meningar som gäller båda —
 * annars läser en skärmläsare upp ett tomt fält utan att säga att tomt är
 * själva svaret. Id:na tillhör <p>-elementen nedanför.
 */
const NOTES = ['quiet_hours_empty', 'quiet_hours_midnight'];

const notesFor = (errorId) => [errorId, ...NOTES].filter(Boolean).join(' ');

function submit() {
    form.patch('/settings/notifications/quiet-hours', { onError: focusFirstError });
}
</script>

<template>
    <section class="mt-10">
        <h2 class="text-lg font-semibold">{{ t('notifications.quiet_hours.heading') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ t('notifications.quiet_hours.intro') }}</p>

        <form class="mt-4 flex max-w-lg flex-col gap-4" @submit.prevent="submit">
            <FormField
                v-slot="{ describedBy: errorId }"
                :label="t('notifications.quiet_hours.start')"
                id="quiet_hours_start"
                :error="form.errors.quiet_hours_start"
            >
                <input
                    id="quiet_hours_start"
                    v-model="form.quiet_hours_start"
                    :aria-describedby="notesFor(errorId)"
                    type="time"
                    name="quiet_hours_start"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <FormField
                v-slot="{ describedBy: errorId }"
                :label="t('notifications.quiet_hours.end')"
                id="quiet_hours_end"
                :error="form.errors.quiet_hours_end"
            >
                <input
                    id="quiet_hours_end"
                    v-model="form.quiet_hours_end"
                    :aria-describedby="notesFor(errorId)"
                    type="time"
                    name="quiet_hours_end"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <!-- Tomma fält är ett svar, inte ett saknat värde (Beslut 4). -->
            <p id="quiet_hours_empty" class="text-sm text-slate-600">
                {{ t('notifications.quiet_hours.empty_note') }}
            </p>

            <!-- Midnatt är avsikten, inte ett fel (Beslut 4). -->
            <p id="quiet_hours_midnight" class="text-sm text-slate-600">
                {{ t('notifications.quiet_hours.midnight_note') }}
            </p>

            <!-- Fördröjningen, inte borttagningen (Beslut 5). -->
            <p class="text-sm text-slate-600">{{ t('notifications.quiet_hours.delays_note') }}</p>

            <!-- Tidszonen: visad, inte ändrad här (Beslut 4). -->
            <p class="text-sm text-slate-700">
                {{ props.timezone
                    ? t('notifications.quiet_hours.timezone', { timezone: props.timezone })
                    : t('notifications.quiet_hours.timezone_follows_account') }}
                <Link href="/settings/profile" class="underline">
                    {{ t('notifications.quiet_hours.timezone_link') }}
                </Link>
            </p>

            <button
                type="submit"
                :disabled="form.processing"
                class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.default') : t('notifications.quiet_hours.submit') }}
            </button>
        </form>
    </section>
</template>
