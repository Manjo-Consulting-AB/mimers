<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import TemplateRow from '../../components/templates/TemplateRow.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: [AppLayout, SettingsLayout] });

/*
 * Kontots mallar, se issue 422 och [[ADR-0089 Egna fält på items]]
 * § Riktning 6.
 *
 * **Mallarna ägs av kontot.** Sidan listar alla mallar i de konton användaren
 * är medlem i — samma fråga som `/api`:s index och som
 * App\Http\Controllers\Settings\TemplateController gör — och en rad per mall
 * kan döpas om och tas bort. Att SPARA en mall görs från containerns
 * inställningar, där innehållet finns att spara.
 *
 * **Kontonamnet ritas bara när det finns fler än ett konto att skilja på.**
 * En namnrad som säger samma sak på varje rad är brus; två konton och en
 * mall utan konto är en fråga läsaren inte kan svara på.
 *
 * Sidan skickar bara mallarna vidare: raden äger namnbytet, raderingen och
 * bekräftelsedialogen, så den här filen behöver ingen skrivning alls.
 */
const props = defineProps({
    /* Mallarna ur App\Http\Resources\ContainerTemplateResource. */
    templates: { type: Array, required: true },
});

const { t } = useTranslations();
const page = usePage();

const accounts = computed(() => page.props.auth?.accounts ?? []);

/** ULID → kontonamn, så en rad kan säga vilket konto den hör till. */
const accountNames = computed(
    () => Object.fromEntries(accounts.value.map((account) => [account.ulid, account.name])),
);

/* Med ett enda konto är namnet på varje rad samma ord — det säger ingenting. */
const showsAccount = computed(() => accounts.value.length > 1);
</script>

<template>
    <Head :title="t('settings.templates.title')" />

    <h2 class="text-2xl font-semibold">{{ t('settings.templates.heading') }}</h2>
    <p class="mt-2 max-w-2xl text-sm text-slate-700">{{ t('settings.templates.description') }}</p>

    <p v-if="templates.length === 0" class="mt-6 text-sm text-slate-600">
        {{ t('settings.templates.empty') }}
    </p>

    <ul v-else class="mt-6 flex max-w-2xl flex-col gap-3">
        <TemplateRow
            v-for="template in props.templates"
            :key="template.ulid"
            :template="template"
            :account-name="showsAccount ? accountNames[template.account] ?? null : null"
        />
    </ul>
</template>
