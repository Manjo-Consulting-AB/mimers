<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import FlashMessage from '../components/FlashMessage.vue';
import SearchField from '../components/SearchField.vue';
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
 * Sökfältet kom med issue 59b § Beslut 5: den globala sökningen hör i den
 * delade layouten, för en sökning som bara finns på söksidan är en sökning
 * ingen hittar. Det ritas bara för en inloggad användare — utloggad renderas
 * layouten utan det (`v-if="user"`), och det är hela villkoret: rutten ligger
 * bakom `auth`, så en gäst har inget att söka i. Fältet är ett vanligt
 * GET-formulär mot /search och ligger i
 * resources/js/components/SearchField.vue.
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
 *
 * **Navigeringen fälls ihop på en telefon** (issue 68a § Beslut 2). Vid
 * 375 px ryms varken märket, sökfältet och de fyra länkarna i en rad, och en
 * rad som inte ryms är en rad som klipps av. Länkarna ligger därför bakom en
 * menyknapp och sökfältet på sin egen rad; `menuOpen` är den enda
 * tillståndsvariabeln layouten har. Över `md:` ritas allt som förut och
 * knappen försvinner (`md:hidden`) — den breda skärmen möter exakt den
 * navigering den mötte före genomgången.
 *
 * Länkarna bär `min-h-11` (44 px, issue 68a § Beslut 3). I den hopfällda
 * listan står de under varandra och träffas med tummen, och en rad som är
 * 36 px hög är en rad man missar.
 */
const { t } = useTranslations();
const page = usePage();
const menuOpen = ref(false);
const user = computed(() => page.props.auth.user);
const showsVerificationNotice = computed(
    () => Boolean(user.value) && user.value.email_verified_at === null && !page.url.startsWith('/email/verify'),
);
</script>

<template>
    <div class="flex min-h-full flex-col bg-slate-50 text-slate-900">
        <header class="border-b border-slate-200 bg-white">
            <nav class="mx-auto flex w-full max-w-3xl flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2">
                <div class="flex w-full items-center justify-between gap-4 md:w-auto">
                    <Link href="/" class="inline-flex min-h-11 items-center text-lg font-semibold">
                        {{ t('common.brand') }}
                    </Link>

                    <button
                        v-if="user"
                        type="button"
                        class="inline-flex min-h-11 min-w-11 items-center justify-center rounded border border-slate-300 px-3 text-sm font-medium md:hidden"
                        aria-controls="huvudmenyn"
                        :aria-expanded="menuOpen"
                        @click="menuOpen = !menuOpen"
                    >
                        {{ menuOpen ? t('nav.menu_close') : t('nav.menu') }}
                    </button>
                </div>

                <!-- Sökfältet ligger på sin egen rad under `md:` och skjuts
                     till höger över det. Klasserna sitter på en omslutande
                     div och inte på komponenten: `SokvyTest` läser taggen
                     `<SearchField v-if="user" />` som den står, och villkoret
                     är det testet handlar om. -->
                <div class="w-full md:ml-auto md:w-auto">
                    <SearchField v-if="user" />
                </div>

                <div
                    id="huvudmenyn"
                    class="w-full flex-col gap-1 text-sm md:w-auto md:flex-row md:items-center md:gap-4"
                    :class="menuOpen ? 'flex' : 'hidden md:flex'"
                >
                    <Link v-if="user" href="/dashboard" class="inline-flex min-h-11 items-center hover:underline">
                        {{ t('nav.dashboard') }}
                    </Link>
                    <Link v-if="user" href="/containers" class="inline-flex min-h-11 items-center hover:underline">
                        {{ t('nav.containers') }}
                    </Link>
                    <Link v-if="user" href="/transfers" class="inline-flex min-h-11 items-center hover:underline">
                        {{ t('nav.transfers') }}
                    </Link>
                    <span v-if="user" class="inline-flex min-h-11 items-center text-slate-600">{{ user.name }}</span>
                    <Link
                        v-if="user"
                        href="/logout"
                        method="post"
                        as="button"
                        class="inline-flex min-h-11 items-center hover:underline"
                    >
                        {{ t('auth.logout') }}
                    </Link>
                    <Link
                        v-else
                        href="/login"
                        class="inline-flex min-h-11 items-center hover:underline"
                    >
                        {{ t('nav.login') }}
                    </Link>
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
