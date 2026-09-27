<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import FlashMessage from '../components/FlashMessage.vue';
import MobileMenu from '../components/MobileMenu.vue';
import MobileTabBar from '../components/MobileTabBar.vue';
import NotificationBell from '../components/NotificationBell.vue';
import SearchField from '../components/SearchField.vue';
import ShellSections from '../components/ShellSections.vue';
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
 * Den håller nästan inget eget tillstånd: `menuOpen` och elementet som
 * öppnade menyn är allt.
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
 * **Länken till sökningen kom med issue 78 § Beslut 2.** Fältet i headern är
 * en väg in, men bara för den som redan vet att sökningen finns; den som
 * letar efter den letar i menyn. Raden ligger därför bland skalets sektioner
 * och följer med både sidhuvudet och sidomenyn.
 *
 * **Länken till inställningarna kom med issue 79 § Beslut 1.** Sju
 * inställningssidor var byggda och nåddes bara av den som redan stod inne i
 * dem: sektionslistan i SettingsLayout renderas först på en inställningssida.
 * En rad räcker — den pekar på `/settings`, som omdirigerar till profilen
 * (issue 53c), och därifrån ligger varje sektion ett klick bort. Ingen
 * användarmeny med utfällning: det är en egen designfråga.
 *
 * **Länken till uppgifterna kom med issue 122.** Todo-vyn flyttade från
 * `/dashboard` till `/tasks` när dashboarden tog över startsidan, och en vy
 * som bara nås genom att skriva adressen är en vy ingen hittar. Raden ligger
 * bredvid dashboarden — de två sidorna var en fram till dess — och bär sidans
 * eget ord (`todo.heading`) i stället för ruttens.
 *
 * **Raderna är `ShellSections` sedan issue 151.** Navigeringen var förut
 * skriven här, rad för rad. Nu ritas den ur en lista i
 * resources/js/components/ShellSections.vue, och samma komponent ritar
 * sidomenyn under `md:` — sektionen finns på båda ställena eller på inget av
 * dem, och ordningen kan inte glida isär. Det är `Klart när`-punkten *"samma
 * sektioner i samma ordning ur samma data"*, och den är skälet att raderna
 * flyttade ut.
 *
 * **Mobilskalet kom med issue 151** · [[M23 Mobilen och kartan]] och
 * [[ADR-0048 Mobilen och plusknappen]] § 1. Under `md:` ritas i stället för
 * den hopfällda desktopraden (issue 68a § Beslut 2):
 *
 *   - **En mörk topprad** (`--color-shell`) med sidans titel. Sidor som vet
 *     vad de heter skickar in den i sloten `topbar` — ContainerLayout lägger
 *     containerns namn och en tillbakaknapp där. Utan slot ritas märket, som i
 *     bildens första skärm; ingen sida behöver göra något för att få en rad.
 *   - **En flikrad i botten** (MobileTabBar) med *Översikt*, *Sök*,
 *     plusknappens plats, *Notiser* och *Meny*.
 *   - **En sidomeny bakom *Meny*** (MobileMenu), med skalets sektioner.
 *
 * Desktopraden är kvar oförändrad och döljs (`hidden md:block`): över `md:`
 * möter användaren exakt den navigering hon mötte före genomgången. Att
 * sökfältet och klockan flyttar ner i flikraden på mobilen är samma beslut —
 * de är sidoberoende ytor, och flikraden är där tummen är.
 *
 * **Menyns öppna-läge bor här** och inte i knappen eller i menyn: knappen är
 * en knapp (MobileTabBar) och menyn en yta (MobileMenu), och den som äger
 * båda är layouten. `menuTrigger` är elementet som öppnade menyn — fokus ska
 * tillbaka till *Meny* när menyn stängs, och det elementet känner bara
 * knappen.
 *
 * **Notisklockan kom med issue 127** och ligger i sidhuvudet över `md:`,
 * bredvid sökfältet: båda är sidoberoende ytor som hör till skalet och inte
 * till en sida, och en klocka som bara fanns på dashboarden hade varit osynlig
 * på varje sida man faktiskt arbetar i. Den ritas bara för en inloggad
 * användare — samma villkor och samma skäl som sökfältet.
 *
 * Klockan äger sin egen form och sin egen läsning
 * (resources/js/components/NotificationBell.vue): den kostar en delad siffra
 * per sidladdning och hämtar sin lista först när den öppnas. Skalet skickar
 * ingenting till den och håller inget av dess tillstånd — utom i flikraden,
 * där den ritas i sin flikform (issue 151).
 */
const { t } = useTranslations();
const page = usePage();
const user = computed(() => page.props.auth.user);

const menuOpen = ref(false);
const menuTrigger = ref(null);

function openMenu(element) {
    menuTrigger.value = element;
    menuOpen.value = true;
}

function closeMenu() {
    menuOpen.value = false;
}

const showsVerificationNotice = computed(
    () => Boolean(user.value) && user.value.email_verified_at === null && !page.url.startsWith('/email/verify'),
);
</script>

<template>
    <div class="flex min-h-full flex-col bg-slate-50 text-slate-900">
        <!--
            Desktopraden. Dold under `md:`, där mobilskalet tar över: en rad
            som fälls ihop kräver två tryck för allt, och flikraden i botten
            gör de fyra vanligaste målen nåbara med tummen.
        -->
        <header class="hidden border-b border-slate-200 bg-white md:block">
            <nav class="mx-auto flex w-full max-w-3xl flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2">
                <div class="flex w-full items-center justify-between gap-4 md:w-auto">
                    <Link href="/" class="inline-flex min-h-11 items-center text-lg font-semibold">
                        {{ t('common.brand') }}
                    </Link>
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

                <div id="huvudmenyn" class="hidden w-full md:flex md:w-auto">
                    <ShellSections />
                </div>
            </nav>
        </header>

        <!--
            Toppraden på mobilen. Titeln kommer ur sloten när sidan har en
            egen — ContainerLayout lägger containerns namn och en
            tillbakaknapp där — och är märket annars.
        -->
        <header class="bg-shell text-white md:hidden">
            <div class="mx-auto flex w-full max-w-3xl items-center gap-2 px-4 py-2">
                <slot name="topbar">
                    <p class="text-title font-semibold">{{ t('common.brand') }}</p>
                </slot>

                <!-- Gästen har ingen flikrad (den är mål för en inloggad) och
                     behöver ändå en väg in. -->
                <Link
                    v-if="!user"
                    href="/login"
                    class="ml-auto inline-flex min-h-11 items-center text-body hover:underline"
                >
                    {{ t('nav.login') }}
                </Link>
            </div>
        </header>

        <div v-if="showsVerificationNotice" class="mx-auto w-full max-w-3xl px-4 pt-6">
            <p class="mb-2 text-sm font-medium text-slate-800">{{ t('auth.verify.banner') }}</p>
            <VerifyEmailNotice />
        </div>

        <FlashMessage />

        <!--
            FAVORITER över `md:`, se issue 106. Sektionen är den samma i
            sidomenyn under `md:` och ritas där av samma komponent, samma
            anrop — se MobileMenu.

            Sektionen ritas bara när listan har rader: en tom rubrik är en yta
            som lovar något den inte har.
        -->
        <div class="hidden md:block">
            <ShellSections part="favorites" />
        </div>

        <main class="mx-auto w-full max-w-3xl flex-1 px-4 pt-8 pb-24 md:pb-8">
            <slot />
        </main>

        <footer class="hidden border-t border-slate-200 py-4 text-center text-xs text-slate-600 md:block">
            {{ t('common.brand') }}
        </footer>

        <!--
            Mobilskalet, se issue 151. Flikraden ligger fast i botten och
            menyn är en dialog; båda ritas bara för en inloggad, för det är
            hennes mål de bär.
        -->
        <template v-if="user">
            <MobileTabBar :menu-open="menuOpen" @open-menu="openMenu" />
            <MobileMenu :open="menuOpen" :trigger="menuTrigger" @close="closeMenu" />
        </template>
    </div>
</template>
