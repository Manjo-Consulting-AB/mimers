<script setup>
import { Head } from '@inertiajs/vue3';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import AccountSettingsForm from '../../components/AccountSettingsForm.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Kontosidan, se issue 53c § Beslut 1 och 9.
 *
 * Ett kort per konto användaren är medlem i — listan kommer från
 * $request->user()->accounts, så ett konto hon inte är med i finns inte med
 * och kan inte heller råka visas. Det finns inget "aktivt konto" att välja
 * (issue 8 § Beslut 8): varje kort bär sitt eget konto och sin egen PATCH.
 *
 * Kortet har TVÅ lägen, och `canUpdate` avgör vilket — svaret kommer ur
 * App\Policies\AccountPolicy::update() via kontrollern, aldrig ur en
 * härledning i vyn:
 *
 *   true  — formuläret, ett per kort (AccountSettingsForm).
 *   false — värdena som text, utan formulär. Inte dolda: behörighet görs i
 *           policies, aldrig genom att dölja en knapp ([[ADR-0021
 *           Frontendteknik]]), och den som saknar rollen ska ändå se vad
 *           kontot heter och står inställt på. Inte heller utgråade fält —
 *           en yta som inte går att använda ska inte se ut som om den gör
 *           det.
 *
 * Rollen visas som en etikett på kortet: den säger varför ett kort saknar
 * formulär, och den är användarens EGEN roll (account_user.role), inte en
 * egenskap hos kontot.
 *
 * `empty` kan bara inträffa om ett konto raderas mellan två anrop, men texten
 * finns för att en tom sida annars är en vit yta utan förklaring.
 */
const props = defineProps({
    accounts: { type: Array, required: true },
    timezones: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <SettingsLayout>
        <Head :title="t('settings.accounts.title')" />

        <h1 class="text-2xl font-semibold">{{ t('settings.accounts.heading') }}</h1>
        <p class="mt-2 text-sm text-slate-700">{{ t('settings.accounts.intro') }}</p>

        <p v-if="props.accounts.length === 0" class="mt-8 text-sm text-slate-700">
            {{ t('settings.accounts.empty') }}
        </p>

        <section class="mt-8 flex flex-col gap-6">
            <article
                v-for="account in props.accounts"
                :key="account.ulid"
                class="rounded border border-slate-200 bg-white p-4 md:p-6"
            >
                <header class="flex items-baseline justify-between gap-4">
                    <h2 class="text-lg font-semibold">{{ account.name }}</h2>
                    <p class="text-sm text-slate-600">{{ t(`settings.accounts.roles.${account.role}`) }}</p>
                </header>

                <AccountSettingsForm
                    v-if="account.canUpdate"
                    class="mt-4"
                    :account="account"
                    :timezones="props.timezones"
                />

                <div v-else class="mt-4 flex flex-col gap-2">
                    <p class="text-sm text-slate-600">{{ t('settings.accounts.read_only') }}</p>

                    <dl class="flex flex-col gap-1 text-sm">
                        <div class="flex flex-col gap-1 md:flex-row md:gap-2">
                            <dt class="shrink-0 text-slate-600 md:w-40">{{ t('settings.profile.name') }}</dt>
                            <dd>{{ account.name }}</dd>
                        </div>
                        <div class="flex flex-col gap-1 md:flex-row md:gap-2">
                            <dt class="shrink-0 text-slate-600 md:w-40">{{ t('settings.profile.locale') }}</dt>
                            <dd>{{ t(`settings.locales.${account.locale}`) }}</dd>
                        </div>
                        <div class="flex flex-col gap-1 md:flex-row md:gap-2">
                            <dt class="shrink-0 text-slate-600 md:w-40">{{ t('settings.profile.timezone') }}</dt>
                            <dd>{{ account.timezone }}</dd>
                        </div>
                        <div class="flex flex-col gap-1 md:flex-row md:gap-2">
                            <dt class="shrink-0 text-slate-600 md:w-40">{{ t('settings.profile.unit_system') }}</dt>
                            <dd>{{ t(`settings.units.${account.unitSystem}`) }}</dd>
                        </div>
                    </dl>
                </div>
            </article>
        </section>
    </SettingsLayout>
</template>
