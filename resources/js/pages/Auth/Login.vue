<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import FlashMessage from '../../components/FlashMessage.vue';
import FormField from '../../components/FormField.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from './useErrorFocus.js';

/*
 * Issue 203 · Inloggningssidan i den nya formen, se `docs/Design/frontpage.png`
 * och [[ADR-0050 Desktopdesignen]].
 *
 * **Sidan ritas utan AppLayout** (Beslut 1). Mockupen har varken sidopanel
 * eller sidhuvud: `/login` är en egen helsida i två kolumner, och `<div
 * class="flex min-h-screen">` är hela skalet. Flashmeddelandet ritas ändå —
 * `<FlashMessage />` överst i högerkolumnen, samma komponent som AppLayout
 * lägger i innehållsytan, så att ett "status" ur sessionen inte tappas på
 * vägen in.
 *
 * **Vänsterkolumnen** (Beslut 2) är `hidden lg:flex` — på en telefon finns
 * bara kortet — och bär märket, rubriken, brödtexten och de fyra punkterna ur
 * `auth.landing.features.*`. Ikonerna är inline-SVG:er som i NotificationBell.
 * Fotot, raden *Används för* och rutan om molnlagringen ritas inte: de har
 * ingen datakälla och byggs inte nu (Beslut 6).
 *
 * **Högerkolumnen** (Beslut 3) är dagens formulär i en ny ram. Fälten, deras
 * `id`, `name` och `autocomplete`, `useForm`, `submit()` och kodfältet när
 * `codeRequested` står oförändrade — bara klasserna byter skepnad. *Glömt
 * lösenordet?* leder till `/login/magic-link` och ersätter den separata
 * magic link-länken (Beslut 4): det finns ingen återställning av lösenord för
 * en utloggad, och en inloggningslänk är vägen in. Under kortet ligger de
 * fyra länkarna till informationssidorna ur issue 202.
 *
 * Texten är engelsk och kommer ur `lang/en/ui.php` under `auth` (Beslut 5).
 */

/*
 * Den arbetade förlagan för varje formulär i M10, se issue 51 § Beslut 9.
 *
 * Mönstret, i tre steg:
 *
 *   1. `useForm({...})` håller värdena, skickar dem och bär serverns svar.
 *   2. `form.post('/login')` postar till den befintliga rutten som redan
 *      validerar. Ingen egen FormRequest, ingen ny rutt som tar emot data,
 *      ingen klientvalidering — skiljer sig valideringen mellan webben och
 *      /api är det en bugg, inte en designfråga ([[ADR-0021
 *      Frontendteknik]]).
 *   3. `form.errors.<fält>` fylls av Inertia ur sessionens felpåse efter en
 *      ValidationException. Ingen delar `errors` — Inertias middleware gör
 *      det redan — och ingen skriver `<p v-if="errors.x">` för hand;
 *      FormField renderar felet och kopplar det till fältet.
 *
 * Etiketterna och knapptexten kommer ur lang/{locale}/ui.php sedan issue 52 —
 * `form.email` och `auth.login.*`. Serverns valideringsfel är redan översatta
 * när de når hit: `form.errors.email` bär meningen ur validation.php på
 * användarens språk.
 *
 * Issue 53a § Beslut 4 · engångskoden. `code` skickas med i varje anrop men
 * RENDERAS först när `form.errors.code` finns, alltså när servern har bett om
 * den — AuthenticatedSessionController binder TotpRequiredException dit.
 * Fältet får aldrig synas i förväg: att visa det för en besökare som ännu inte
 * angett rätt lösenord avslöjar både att adressen finns och att kontot har
 * tvåfaktor, precis den sidokanal LoginRequest::authenticate() prövar
 * lösenordet före koden för att undvika. Ett felaktigt lösenord sätter
 * `errors.email`, aldrig `errors.code`, och fältet förblir dolt.
 *
 * Etiketten nämner återställningskoden: LoginRequest provar samma inskickade
 * värde som engångskod och som återställningskod och ger samma fel oavsett
 * vilket som misslyckades (tests/Feature/Auth/AterstallningskoderTest.php).
 * En vy som frågade efter "kod från appen" och gömde återställningskoden
 * bakom en egen länk skulle återinföra en skillnad servern med flit raderat.
 *
 * Fältet är `type="text"` utan `inputmode`: engångskoden är sex siffror, men
 * återställningskoden är tio tecken ur `Str::random()`s alfanumeriska alfabet
 * (App\Support\Auth\RecoveryCodeBroker), och en numerisk tangentbordsknapp
 * hade stängt ute den på en telefon.
 *
 * Fokuset på det fält som just dykt upp ägs av `focusFirstError` och ingen
 * annan. Ett eget `watch` på `codeRequested` som fokuserade själva inmatningen
 * körde samtidigt som `useErrorFocus` fokuserade `#code-error`, i samma
 * serversvar och utan inbördes ordning — vann inmatningen tystades exakt det
 * Beslut 10 kräver. En mekanism, inte två: `errors.code` är det första felet
 * servern sätter här, så `focusFirstError` landar på `#code-error`.
 */
const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

/*
 * De fyra punkterna i vänsterkolumnen, i bildens ordning (Beslut 2). Nycklarna
 * står utskrivna och byggs inte ur `key`: en dynamisk `auth.landing.features.
 * ${key}.title` hade gömt dem för källkodsprovet som letar efter dem, och
 * listan är kort nog att läsas som den är.
 */
const landingFeatures = [
    {
        title: 'auth.landing.features.documents.title',
        body: 'auth.landing.features.documents.body',
        icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z',
    },
    {
        title: 'auth.landing.features.maintenance.title',
        body: 'auth.landing.features.maintenance.body',
        icon: 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    },
    {
        title: 'auth.landing.features.costs.title',
        body: 'auth.landing.features.costs.body',
        icon: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    },
    {
        title: 'auth.landing.features.relations.title',
        body: 'auth.landing.features.relations.body',
        icon: 'M13.828 10.172a4 4 0 0 0-5.656 0l-4 4a4 4 0 1 0 5.656 5.656l1.102-1.101m-.758-4.899a4 4 0 0 0 5.656 0l4-4a4 4 0 0 0-5.656-5.656l-1.1 1.1',
    },
];

const form = useForm({
    email: '',
    password: '',
    code: '',
});

const codeRequested = computed(() => Boolean(form.errors.code));

function submit() {
    form.post('/login', {
        // Tangentbordsanvändaren ska hamna på felet, inte kvar på knappen —
        // se useErrorFocus.js.
        onError: focusFirstError,

        // Lösenordet töms när svaret kommit. Undantaget är när servern bad om
        // engångskoden: då är lösenordet redan rätt och att tömma det tvingar
        // fram en omskrivning av ett fält användaren just skrev — samma skäl
        // som gör att takgränsen i bootstrap/app.php inte skickar tillbaka
        // lösenordet. Ett felaktigt lösenord sätter `errors.email` och töms.
        onFinish: () => {
            if (!codeRequested.value) {
                form.reset('password');
            }
        },
    });
}
</script>

<template>
    <Head :title="t('auth.login.title')" />

    <div class="flex min-h-screen">
        <!--
            Vänsterkolumnen, se Beslut 2. `hidden lg:flex` — på en telefon är
            hela ytan kortet. Märket överst, allt annat tryckt mot botten av
            `justify-between`.
        -->
        <div class="hidden lg:flex lg:w-1/2 flex-col justify-between bg-shell text-white p-12">
            <p class="text-lg font-semibold uppercase tracking-[0.3em]">{{ t('common.brand') }}</p>

            <div>
                <p class="text-4xl font-semibold">
                    {{ t('auth.landing.heading') }}
                    <span class="text-sky-300">{{ t('auth.landing.heading_accent') }}</span>
                </p>

                <p class="mt-6 text-body text-white/80">{{ t('auth.landing.body') }}</p>

                <ul class="mt-8 flex flex-col gap-6">
                    <li v-for="feature in landingFeatures" :key="feature.title" class="flex items-start gap-4">
                        <span class="shrink-0 rounded-control bg-white/10 p-2">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="h-6 w-6"
                                aria-hidden="true"
                            >
                                <path :d="feature.icon"></path>
                            </svg>
                        </span>

                        <span>
                            <span class="block font-semibold">{{ t(feature.title) }}</span>
                            <span class="block text-body text-white/80">{{ t(feature.body) }}</span>
                        </span>
                    </li>
                </ul>
            </div>
        </div>

        <!--
            Högerkolumnen, se Beslut 3. Kortet är dagens formulär i en ny ram —
            fälten och beteendet står oförändrade (Beslut 3), bara klasserna
            byter skepnad. Länkarna under kortet pekar på informationssidorna
            ur issue 202.
        -->
        <div class="flex w-full lg:w-1/2 flex-col items-center justify-center bg-surface-muted px-4 py-12">
            <FlashMessage />

            <div class="w-full max-w-md rounded-card border border-border bg-surface p-8 shadow-sm">
                <p class="text-center text-lg font-semibold">{{ t('common.brand') }}</p>

                <h1 class="mt-4 text-center text-2xl font-semibold">{{ t('auth.login.heading') }}</h1>
                <p class="mt-2 text-center text-body text-ink-muted">{{ t('auth.login.subheading') }}</p>

                <form class="mt-6 flex flex-col gap-4" @submit.prevent="submit">
                    <FormField v-slot="{ describedBy }" :label="t('form.email')" id="email" :error="form.errors.email">
                        <input
                            id="email"
                            v-model="form.email"
                            :aria-describedby="describedBy"
                            type="email"
                            name="email"
                            autocomplete="email"
                            required
                            class="w-full rounded-control border border-border px-3 py-2"
                        >
                    </FormField>

                    <FormField v-slot="{ describedBy }" :label="t('form.password')" id="password" :error="form.errors.password">
                        <input
                            id="password"
                            v-model="form.password"
                            :aria-describedby="describedBy"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                            class="w-full rounded-control border border-border px-3 py-2"
                        >
                    </FormField>

                    <!--
                        *Glömt lösenordet?* ersätter den separata magic
                        link-länken (Beslut 4). Den står direkt under
                        lösenordsfältet och före knappen, högerställd.
                    -->
                    <div class="text-right">
                        <Link href="/login/magic-link" class="text-body text-blue-700 hover:underline">{{ t('auth.login.forgot') }}</Link>
                    </div>

                    <FormField
                        v-if="codeRequested"
                        v-slot="{ describedBy }"
                        :label="t('auth.code.label')"
                        id="code"
                        :error="form.errors.code"
                    >
                        <input
                            id="code"
                            v-model="form.code"
                            :aria-describedby="describedBy"
                            type="text"
                            name="code"
                            autocomplete="one-time-code"
                            class="w-full rounded-control border border-border px-3 py-2"
                        >
                    </FormField>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex min-h-11 w-full items-center justify-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                    >
                        {{ form.processing ? t('common.pending.default') : t('auth.login.submit') }}
                    </button>
                </form>

                <p class="mt-6 text-center text-body text-ink-muted">
                    {{ t('auth.login.no_account') }}
                    <Link href="/register" class="font-medium text-blue-700 hover:underline">{{ t('auth.register.link') }}</Link>
                </p>
            </div>

            <nav class="mt-8 flex flex-wrap items-center justify-center gap-x-6 gap-y-2">
                <Link href="/about" class="text-meta text-ink-subtle hover:underline">{{ t('auth.landing.links.about') }}</Link>
                <Link href="/privacy" class="text-meta text-ink-subtle hover:underline">{{ t('auth.landing.links.privacy') }}</Link>
                <Link href="/terms" class="text-meta text-ink-subtle hover:underline">{{ t('auth.landing.links.terms') }}</Link>
                <Link href="/help" class="text-meta text-ink-subtle hover:underline">{{ t('auth.landing.links.help') }}</Link>
            </nav>
        </div>
    </div>
</template>
