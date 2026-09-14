<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import FlashMessage from '../components/FlashMessage.vue';
import VerifyEmailNotice from '../components/VerifyEmailNotice.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Den enda layouten i M10.
 *
 * Varje sida under resources/js/pages/ wrappar sitt innehåll i den här
 * komponenten — <AppLayout> ... </AppLayout> — och lägger ingenting eget i
 * navigeringen. Femton issues renderar sina vyer här; uppfinner en av dem
 * en egen header har den byggt den sextonde.
 *
 * Layouten läser de delade propsen (auth och flash) och skickar ingenting
 * vidare nedåt — en sida som behöver användaren läser usePage().props själv.
 * Den håller inget eget tillstånd.
 *
 * URL:er skrivs som strängar i <Link href="/dashboard">. Ingen
 * routinghjälpare i JavaScript, se issue 51 § Beslut 7: en URL som bara
 * servern kan bygga — en signerad länk, en med ett ULID i — skickas som en
 * prop i stället.
 *
 * All text går genom t() sedan issue 52 — ingen sträng i den här filen når
 * användaren utan att först ha passerat lang/{locale}/ui.php.
 *
 * Verifieringsbannern kom med issue 53a § Beslut 7: `email_verified_at` är
 * null hos en overifierad användare (AuthUserResource lämnar fältet, aldrig
 * utelämnat), och det är hela villkoret. På /email/verify visas den inte —
 * sidan bär samma komponent själv, och två likadana knappar på samma sida
 * är en bugg och inte en påminnelse.
 *
 * Utloggningsknappen är en <Link method="post">, inte ett eget formulär:
 * /logout är en POST-rutt (routes/web.php) och Inertia skickar CSRF-tokenet
 * åt oss. Utan den går det att logga in men inte ut i webbläsaren.
 */
const { t } = useTranslations();
const page = usePage();
const user = computed(() => page.props.auth.user);
const showsVerificationNotice = computed(
    () => Boolean(user.value) && user.value.email_verified_at === null && !page.url.startsWith('/email/verify'),
);
</script>

<template>
    <div class="flex min-h-full flex-col bg-slate-50 text-slate-900">
        <header class="border-b border-slate-200 bg-white">
            <nav class="mx-auto flex w-full max-w-3xl items-center justify-between gap-4 px-4 py-3">
                <Link href="/" class="text-lg font-semibold">{{ t('common.brand') }}</Link>

                <div class="flex items-center gap-4 text-sm">
                    <Link v-if="user" href="/dashboard" class="hover:underline">{{ t('nav.dashboard') }}</Link>
                    <Link v-if="user" href="/containers" class="hover:underline">{{ t('nav.containers') }}</Link>
                    <span v-if="user" class="text-slate-600">{{ user.name }}</span>
                    <Link
                        v-if="user"
                        href="/logout"
                        method="post"
                        as="button"
                        class="hover:underline"
                    >
                        {{ t('auth.logout') }}
                    </Link>
                    <Link v-else href="/login" class="hover:underline">{{ t('nav.login') }}</Link>
                </div>
            </nav>
        </header>

        <div v-if="showsVerificationNotice" class="mx-auto w-full max-w-3xl px-4 pt-6">
            <p class="mb-2 text-sm font-medium text-slate-800">{{ t('auth.verify.banner') }}</p>
            <VerifyEmailNotice />
        </div>

        <FlashMessage />

        <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-8">
            <slot />
        </main>

        <footer class="border-t border-slate-200 py-4 text-center text-xs text-slate-500">
            {{ t('common.brand') }}
        </footer>
    </div>
</template>
