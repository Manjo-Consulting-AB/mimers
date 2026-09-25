<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import FlashMessage from '../components/FlashMessage.vue';
import NotificationBell from '../components/NotificationBell.vue';
import SearchField from '../components/SearchField.vue';
import UiListRow from '../components/UiListRow.vue';
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
 * **Länken till sökningen kom med issue 78 § Beslut 2.** Fältet i headern är
 * en väg in, men bara för den som redan vet att sökningen finns; den som
 * letar efter den letar i menyn. Raden ligger därför innanför `#huvudmenyn`
 * och följer med i hopfällningen.
 *
 * **Länken till inställningarna kom med issue 79 § Beslut 1.** Sju
 * inställningssidor var byggda och nåddes bara av den som redan stod inne i
 * dem: sektionslistan i SettingsLayout renderas först på en inställningssida.
 * En rad här räcker — den pekar på `/settings`, som omdirigerar till profilen
 * (issue 53c), och därifrån ligger varje sektion ett klick bort. Ingen
 * användarmeny med utfällning: det är en egen designfråga.
 *
 * **Länken till uppgifterna kom med issue 122.** Todo-vyn flyttade från
 * `/dashboard` till `/tasks` när dashboarden tog över startsidan, och en vy
 * som bara nås genom att skriva adressen är en vy ingen hittar. Raden ligger
 * bredvid dashboarden — de två sidorna var en fram till dess — och bär sidans
 * eget ord (`todo.heading`) i stället för ruttens: användaren ska möta samma
 * ord i menyn som på sidan.
 *
 * **Navigeringen fälls ihop på en telefon** (issue 68a § Beslut 2). Vid
 * 375 px ryms varken märket, sökfältet och de sju länkarna i en rad, och en
 * rad som inte ryms är en rad som klipps av. Länkarna ligger därför bakom en
 * menyknapp och sökfältet på sin egen rad; `menuOpen` är den enda
 * tillståndsvariabeln layouten har. Över `md:` ritas allt som förut och
 * knappen försvinner (`md:hidden`) — den breda skärmen möter exakt den
 * navigering den mötte före genomgången.
 *
 * Länkarna bär `min-h-11` (44 px, issue 68a § Beslut 3). I den hopfällda
 * listan står de under varandra och träffas med tummen, och en rad som är
 * 36 px hög är en rad man missar.
 *
 * **`FAVORITER` kom med issue 106** — sidopanelens sektion ur bilden, se
 * [[M17 Designsystemet]] § 106 och [[ADR-0042 Designsystemet]]
 * § Konsekvenser. Den ritas ur den delade proppen `favorites` och ställer
 * ingen egen fråga: listan är redan filtrerad genom `ResolveItemScope` på
 * servern, och en vy som prövade omfånget en gång till vore den andra regeln
 * om vad man når.
 *
 * **Sektionen ritas bara när det finns något i den.** En rubrik över en tom
 * lista är en yta som lovar något den inte har, och en användare utan
 * favoriter ska inte mötas av ett tomt fack. Villkoret är listans längd och
 * ingenting annat — ingen egen flagga, och ingen rad som säger att listan är
 * tom.
 *
 * **Raden är `UiListRow`** (issue 99), samma form som resten av skalet, och
 * titeln är en `<Link>` till itemets detaljvy. Adressen kommer färdig i
 * proppen: den bär två ULID:n, och skalet bygger inga adresser av delar.
 *
 * **Ingen räknare och ingen antydan.** Antalet favoriter står ingenstans —
 * varken som tal, som "dolda rader" eller som en gråad rad — eftersom servern
 * redan utelämnat det användaren inte når och en siffra hade läckt skillnaden
 * (issue 73 § Beslut 6). Sektionen är listan, och listan är det man når.
 *
 * **Sektionen ligger i skalet och inte i en sidorail.** Bilden ritar
 * `FAVORITER` i en mörk vänsterkolumn, men den globala vänstermenyn tas inte
 * in ([[ADR-0042 Designsystemet]] § Beslut: raderna för Struktur, Karta,
 * Uppgifter, Dokument och Kostnader avvisas), och en rail som bara bar den
 * här sektionen hade lagt om varje sida i produkten — utanför den här
 * issuen. Sektionen ritas därför som sitt eget band i skalet, på samma plats
 * och i samma form som `FlashMessage` och verifieringspåminnelsen ovanför.
 *
 * **Notisklockan kom med issue 127** och ligger i sidhuvudet, bredvid
 * sökfältet: båda är sidoberoende ytor som hör till skalet och inte till en
 * sida, och en klocka som bara fanns på dashboarden hade varit osynlig på
 * varje sida man faktiskt arbetar i. Den ritas bara för en inloggad
 * användare — samma villkor och samma skäl som sökfältet: en gäst har inga
 * notiser att läsa, och de delade propsen bär noll för henne.
 *
 * Klockan äger sin egen form och sin egen läsning
 * (resources/js/components/NotificationBell.vue): den kostar en delad siffra
 * per sidladdning och hämtar sin lista först när den öppnas. Skalet skickar
 * ingenting till den och håller inget av dess tillstånd.
 */
const { t } = useTranslations();
const page = usePage();
const menuOpen = ref(false);
const user = computed(() => page.props.auth.user);
const favorites = computed(() => page.props.favorites ?? []);
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
                <div class="flex w-full items-center gap-2 md:ml-auto md:w-auto">
                    <SearchField v-if="user" />
                    <NotificationBell v-if="user" />
                </div>

                <div
                    id="huvudmenyn"
                    class="w-full flex-col gap-1 text-sm md:w-auto md:flex-row md:items-center md:gap-4"
                    :class="menuOpen ? 'flex' : 'hidden md:flex'"
                >
                    <Link v-if="user" href="/dashboard" class="inline-flex min-h-11 items-center hover:underline">
                        {{ t('nav.dashboard') }}
                    </Link>
                    <Link v-if="user" href="/tasks" class="inline-flex min-h-11 items-center hover:underline">
                        {{ t('nav.tasks') }}
                    </Link>
                    <Link v-if="user" href="/containers" class="inline-flex min-h-11 items-center hover:underline">
                        {{ t('nav.containers') }}
                    </Link>
                    <Link v-if="user" href="/transfers" class="inline-flex min-h-11 items-center hover:underline">
                        {{ t('nav.transfers') }}
                    </Link>
                    <Link v-if="user" href="/search" class="inline-flex min-h-11 items-center hover:underline">
                        {{ t('nav.search') }}
                    </Link>
                    <Link v-if="user" href="/settings" class="inline-flex min-h-11 items-center hover:underline">
                        {{ t('nav.settings') }}
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

        <!-- Sektionen ritas bara när listan har rader: en tom rubrik är en yta
             som lovar något den inte har (issue 106). -->
        <nav
            v-if="favorites.length"
            :aria-label="t('nav.favorites')"
            class="mx-auto w-full max-w-3xl px-4 pt-6"
        >
            <h2 class="text-meta font-semibold uppercase tracking-wide text-ink-subtle">
                {{ t('nav.favorites') }}
            </h2>

            <ul class="flex flex-col">
                <UiListRow v-for="favorite in favorites" :key="favorite.url">
                    <template #title>
                        <Link :href="favorite.url" class="flex min-h-11 items-center hover:underline">
                            {{ favorite.name }}
                        </Link>
                    </template>
                </UiListRow>
            </ul>
        </nav>

        <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-8">
            <slot />
        </main>

        <footer class="border-t border-slate-200 py-4 text-center text-xs text-slate-600">
            {{ t('common.brand') }}
        </footer>
    </div>
</template>
