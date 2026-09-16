<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import NotificationPreferenceRow from '../../components/NotificationPreferenceRow.vue';
import QuietHoursForm from '../../components/QuietHoursForm.vue';
import { modeOf, valuesFor } from '../../components/notificationPresentation.js';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from '../Auth/useErrorFocus.js';

/*
 * Notisinställningarna, se issue 65a § Beslut 1, 3, 4 och 6.
 *
 * Sidan svarar på EN fråga — "när och hur vill jag bli störd?" — och bär
 * därför två formulär mot två rutter: typerna (PUT /settings/notifications)
 * och de tysta timmarna (PATCH /settings/notifications/quiet-hours). De är två
 * skrivningar mot två olika `/api`-rutter och behåller den uppdelningen här;
 * ett gemensamt formulär hade behövt slå ihop två requests och två
 * felmängder. Ett fel på en tid får inte nollställa preferenserna.
 *
 * **Listan kommer färdig ur serverns svar** — varje typ ur
 * `NotificationPreferences::types()`, i konstanternas ordning, med det
 * effektiva värdet och `is_default`. Vyn känner inte till förvalen, den
 * visar dem (31b § Beslut 2, 65a § Beslut 3).
 *
 * **Bara de typer användaren rört skickas** (Beslut 3). `edits` är tom från
 * början och fylls på när en radio väljs; en typ som ingen rört får ingen rad
 * i databasen, och en saknad rad fortsätter betyda förvalet i kod (31a
 * § Beslut 2). En omdirigering från PUT är en ny sidvisning, så `edits` töms
 * av sig själv — därför finns ingen "återställ"-knapp och ingen kopia av
 * serverns lista i klienten.
 *
 * **Rört, inte ändrat** — skillnaden är hela Beslut 3. Väljer användaren det
 * läge som redan är förvalet har hon uttryckt en åsikt, och den ska bli en rad:
 * annars kan ett framtida ändrat förval tysta köra över valet, och servern kan
 * inte se skillnad på "valde samma värde som koden" och "har ingen åsikt" —
 * bara en rad kan. En jämförelse mot `modeOf()` skulle dessutom lämna
 * Spara-knappen avstängd, så att valet varken sparades eller kunde tvingas
 * fram. Därför räknas `changed` ur `edits` nycklar och aldrig ur värdet.
 *
 * **Veckosammanfattningen och varför**, i en mening ur lang/ (Beslut 3):
 * förvalet är inte en detalj i en tabell, och den som inte förstår varför
 * stänger av det.
 */
const props = defineProps({
    preferences: { type: Array, required: true },
    quietHoursStart: { type: String, default: null },
    quietHoursEnd: { type: String, default: null },
    timezone: { type: String, default: null },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

/* `type` → läge, och bara för de typer användaren faktiskt rört. */
const edits = ref({});

const modeFor = (preference) => edits.value[preference.type] ?? modeOf(preference);

/* Rörd = användaren har valt ett läge för typen, oavsett vilket. */
const touched = (preference) => Object.prototype.hasOwnProperty.call(edits.value, preference.type);

const changed = computed(() => props.preferences.filter(touched));

function setMode(type, mode) {
    edits.value = { ...edits.value, [type]: mode };
}

const form = useForm({ preferences: [] });

/*
 * Serverns fel för preferenslistan hamnar på `preferences.N.<fält>` och hör
 * inte till ett renderat fält — fältet är en radio användaren valt ur en
 * sluten mängd, så felet kan bara komma ur något annat. Det visas ändå: ett
 * sparande som tyst inte blev av är värre än en ful mening.
 */
const preferencesError = computed(() => Object.entries(form.errors)
    .filter(([key]) => key.startsWith('preferences'))
    .map(([, message]) => message)[0] ?? null);

function submitPreferences() {
    form.preferences = changed.value.map((preference) => ({
        type: preference.type,
        channel: preference.channel,
        ...valuesFor(edits.value[preference.type]),
    }));

    form.put('/settings/notifications', {
        onError: focusFirstError,
        onSuccess: () => {
            edits.value = {};
        },
    });
}
</script>

<template>
    <SettingsLayout>
        <Head :title="t('notifications.title')" />

        <h1 class="text-2xl font-semibold">{{ t('notifications.heading') }}</h1>
        <p class="mt-2 text-sm text-slate-700">{{ t('notifications.intro') }}</p>

        <form class="mt-8 flex flex-col gap-4" @submit.prevent="submitPreferences">
            <h2 class="text-lg font-semibold">{{ t('notifications.types_heading') }}</h2>

            <!-- Förvalet och skälet till det (Beslut 3): säsongen. -->
            <p class="text-sm text-slate-700">{{ t('notifications.digest_default') }}</p>

            <NotificationPreferenceRow
                v-for="preference in props.preferences"
                :key="preference.type"
                :preference="preference"
                :mode="modeFor(preference)"
                :touched="touched(preference)"
                @update:mode="setMode(preference.type, $event)"
            />

            <p v-if="preferencesError" role="alert" tabindex="-1" class="text-sm text-red-700 outline-none">
                {{ preferencesError }}
            </p>

            <button
                type="submit"
                :disabled="form.processing || changed.length === 0"
                class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.default') : t('notifications.submit') }}
            </button>
        </form>

        <QuietHoursForm
            :quiet-hours-start="props.quietHoursStart"
            :quiet-hours-end="props.quietHoursEnd"
            :timezone="props.timezone"
        />
    </SettingsLayout>
</template>
