<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CurrencyField from './CurrencyField.vue';
import FormField from './FormField.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret för ETT konto, se issue 53c § Beslut 9.
 *
 * Kontosidan renderar ett kort per konto, och varje kort har sitt EGET
 * useForm och sin EGEN PATCH — därför en komponent och inte ett formulär i
 * en v-for. Ett valideringsfel på ett konto färgar annars de andra korten
 * röda, och ett `form.processing` hade låst varje spara-knapp på sidan.
 *
 * Komponenten ligger i components/ och inte i pages/Settings/: Inertia löser
 * upp sidnamn mot `./pages/**\/*.vue` (resources/js/app.js), så en .vue-fil
 * bredvid vyerna blir en sida som går att rendera utan att någon rutt pekar
 * på den. Den här är ingen sida — den är en del av Settings/Accounts.
 *
 * Kontots värden är OBLIGATORISKA (Beslut 5), till skillnad från
 * användarens: kontot är botten i kedjan, det som gäller när användaren inte
 * valt något. Därför ingen "följ ..."-post i de här väljarna, och därför
 * `required` i markupen — samma markör som på profilen.
 *
 * `currency` kom hit i issue 85 · [[ADR-0037 Valutans arv]]: kontot är botten
 * i valutans arv, och containern faller tillbaka på det här värdet när den
 * saknar en egen. Fältet är förifyllt med kontots värde och ändringsbart,
 * precis som containerns valutafält i pages/Containers/Edit.vue. Sedan issue
 * 1253 är det en sökruta över de aktiva ISO 4217-koderna
 * ([[ADR-0078 Valutor ur ISO 4217]] § 2) och ingen fritext: bara en kod ur
 * listan kan skickas. Att byta värdet skriver bara `account.currency` — ingen
 * skriven kostnadsrad märks om.
 *
 * `useErrorFocus` importeras ur pages/Auth/ där filen bor sedan issue 53a;
 * flytten till composables/ ligger utanför den här issuen (se filens eget
 * docblock).
 */
const props = defineProps({
    account: { type: Object, required: true },
    timezones: { type: Array, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

/* Fältens id:n måste vara unika på sidan — varje kort har samma fältnamn. */
const field = (name) => `account-${props.account.ulid}-${name}`;

const form = useForm({
    name: props.account.name,
    locale: props.account.locale,
    timezone: props.account.timezone,
    unit_system: props.account.unitSystem,
    currency: props.account.currency,
});

const locales = computed(() => ['sv_SE', 'en_GB']);
const units = computed(() => ['metric', 'imperial']);

function submit() {
    form.patch(`/settings/accounts/${props.account.ulid}`, { onError: focusFirstError });
}
</script>

<template>
    <form class="flex max-w-lg flex-col gap-4" @submit.prevent="submit">
        <FormField
            v-slot="{ describedBy }"
            :label="t('settings.profile.name')"
            :id="field('name')"
            :error="form.errors.name"
        >
            <input
                :id="field('name')"
                v-model="form.name"
                :aria-describedby="describedBy"
                type="text"
                name="name"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('settings.profile.locale')"
            :id="field('locale')"
            :error="form.errors.locale"
        >
            <select
                :id="field('locale')"
                v-model="form.locale"
                :aria-describedby="describedBy"
                name="locale"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
                <option v-for="locale in locales" :key="locale" :value="locale">
                    {{ t(`settings.locales.${locale}`) }}
                </option>
            </select>
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('settings.profile.timezone')"
            :id="field('timezone')"
            :error="form.errors.timezone"
        >
            <select
                :id="field('timezone')"
                v-model="form.timezone"
                :aria-describedby="describedBy"
                name="timezone"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
                <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
            </select>
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('settings.profile.unit_system')"
            :id="field('unit_system')"
            :error="form.errors.unit_system"
        >
            <select
                :id="field('unit_system')"
                v-model="form.unit_system"
                :aria-describedby="describedBy"
                name="unit_system"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
                <option v-for="unit in units" :key="unit" :value="unit">
                    {{ t(`settings.units.${unit}`) }}
                </option>
            </select>
        </FormField>

        <!-- Kontots valuta, se issue 85 · [[ADR-0037 Valutans arv]]. Fältet
             är förifyllt och ändringsbart, aldrig dolt — det är botten i
             arvet, och en container utan egen valuta visar det här värdet i
             sin tur. Sedan issue 1253 är det en sökruta över de aktiva ISO
             4217-koderna ([[ADR-0078 Valutor ur ISO 4217]] § 2): bara en kod
             ur listan kan skickas. Att byta värdet skriver bara kontot; ingen
             skriven kostnadsrad märks om. -->
        <FormField
            v-slot="{ describedBy }"
            :label="t('settings.accounts.currency')"
            :id="field('currency')"
            :error="form.errors.currency"
        >
            <CurrencyField
                :id="field('currency')"
                v-model="form.currency"
                :described-by="describedBy"
            />
        </FormField>

        <button
            type="submit"
            :disabled="form.processing"
            class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
        >
            {{ form.processing ? t('common.pending.default') : t('settings.accounts.submit') }}
        </button>
    </form>
</template>
