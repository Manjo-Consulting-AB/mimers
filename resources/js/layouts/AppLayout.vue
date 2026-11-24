<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import CreateButton from '../components/CreateButton.vue';
import CreateMenu from '../components/CreateMenu.vue';
import FlashMessage from '../components/FlashMessage.vue';
import MobileMenu from '../components/MobileMenu.vue';
import MobileTabBar from '../components/MobileTabBar.vue';
import NotificationBell from '../components/NotificationBell.vue';
import SearchField from '../components/SearchField.vue';
import ShellContainerList from '../components/ShellContainerList.vue';
import ShellSections from '../components/ShellSections.vue';
import UserAvatar from '../components/UserAvatar.vue';
import VerifyEmailNotice from '../components/VerifyEmailNotice.vue';
import { useInlineCreate } from '../composables/useInlineCreate.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Den enda layouten i M10.
 *
 * Varje sida under resources/js/pages/ deklarerar den här i sin layoutkedja
 * (issue 273; kedjan för containerns sidor kom med issue 275) och lägger
 * ingenting eget i navigeringen. Femton issues renderar sina vyer här;
 * uppfinner en av dem en egen header har den byggt den sextonde.
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
 *   - **En mörk topprad** (`--color-shell`) med sidans titel. Ligger sidan i
 *     en container bär raden containerns bild, namn och en tillbakaknapp
 *     (issue 151); annars märket, som i bildens första skärm, och ingen sida
 *     behöver göra något för att få en rad. **Containerns markup ritas av
 *     `ContainerLayout` och teleporteras in hit** (issue 275 · [[ADR-0056
 *     Flytande navigering]] § 1): skalet ligger utanför containerns layout i
 *     kedjan, och en slot kan bara fyllas nedåt, så den inre layouten kan inte
 *     skicka innehåll uppåt. Målet `#shell-topbar` står därför här, och
 *     containern märker raden som tagen — märket viker då för hennes bild och
 *     namn. Skalet självt läser varken `container` eller `can`: den som har
 *     svaret ritar det.
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
 * **Klockan läser notislistan sedan M60 · issue 385.** Den gamla klockan
 * läste `notification` direkt; den här får antalet ur den delade proppen
 * `notifications.unread` och öppnar en panel som hämtar `GET
 * /notifications/panel` ([[ADR-0077 Notislistan i appen]] § 2, 3 och 7).
 * Klockan äger fortfarande sin egen form, sin egen läsning och sin egen
 * stängning (resources/js/components/NotificationBell.vue); skalet skickar
 * ingenting till den och håller inget av dess tillstånd — utom i flikraden,
 * där den ritas i sin flikform och är en länk till `/notifications`
 * (issue 151).
 *
 * **Plusknappen kom med issue 152** · [[ADR-0048 Mobilen och plusknappen]] § 2.
 * Den ritas i flikradens mitt på mobilen och i sidhuvudet över `md:`, och den
 * är samma komponent på båda ställena (resources/js/components/CreateButton.vue).
 *
 *   - **Målet är sidans och kommer som en prop.** `create` bär svaret från
 *     App\Support\Frontend\CreateTarget — en `<Link>` till ett formulär, eller
 *     en meny — och en sida som inte skickar något får ingen knapp. Skalet
 *     räknar alltså inte ut vad knappen gör, och det finns ingen sida vars
 *     knapp kan göra fel sak: de två följs åt i samma kontroller.
 *   - **Menyns öppna-läge bor här**, av samma skäl som sidomenyns: knappen är
 *     en knapp och menyn en yta (CreateMenu), och den som äger båda är
 *     layouten. `createTrigger` är elementet som öppnade menyn — arket fästs
 *     under det över `md:` och lämnar tillbaka fokus till det när det stängs.
 *   - **Knappen ritas bara för en inloggad.** Målet kräver ett konto att skapa
 *     i, och varje sida som bär en knapp ligger bakom `auth`; `user`-villkoret
 *     är detsamma som för flikraden och menyn.
 *
 * **Sektionen *Nyligen besökta* togs bort ur skalet med issue 677.** Den kom
 * med issue 160 · [[ADR-0049 Nyligen besökta]] och stod i skalets band över
 * `md:` — direkt ovanför favoriterna — och i sidomenyn under `md:`, ritad av
 * resources/js/components/RecentVisitList.vue. Komponenten, besöksinspelningen
 * och proppen `recentVisits` står kvar oanvända till dess att städningen blir
 * en egen issue.
 *
 * **SIDOPANELEN kom med issue 169** · [[ADR-0050 Desktopdesignen]] § 1.
 * Desktop fick aldrig sitt skal: layouten var en vit topprad och en
 * innehållsyta på 768 px, medan varje bild designern lämnat ritar en mörk
 * sidopanel och en yta på över 1 200 px. Över `md:` är skalet därför två
 * kolumner — panelen till vänster, innehållet till höger — och panelen bär
 * sektionerna i bildens ordning:
 *
 *   1. **Raderna ur `ShellSections`**, exakt de sex som fanns i dag och i
 *      samma ordning. Inga nya: bildens *Kalender*, *Kostnader* och *Dokument*
 *      är globala sidor som inte finns ([[ADR-0041 Itemets vy]] har redan
 *      avvisat samma sorts navigering).
 *   2. **Containerlistan** (`ShellContainerList`), grupperad per art enligt
 *      [[ADR-0036 Containerns art]]. Listan är en OPTIONAL propp och hämtas
 *      av `isDesktopPanel`. Dess sista rad är *Add container* (issue 677).
 *   3. ***Favoriter*** — samma komponent och samma data som förut, flyttad in
 *      i panelen.
 *
 * **Toppraden bär det som inte är navigering**: plusknappen, sökfältet,
 * klockan och avataren. De tre första är oförändrade komponenter; avataren
 * pekar på `/settings` och ritas sedan issue 382 av
 * `resources/js/components/UserAvatar.vue` ([[ADR-0076 Profilbilden]] § 6
 * och 7) — bilden med sitt utsnitt när den finns, initialerna annars.
 *
 * **Sidopanelen och mobilens sidomeny visar samma sektioner i samma
 * ordning** ([[ADR-0048 Mobilen och plusknappen]] § 1): båda ritar
 * `ShellSections` och `ShellContainerList`. En sektion som
 * finns i den ena och saknas i den andra är ett fel, och det är därför
 * containerlistan ligger i en egen komponent och inte skrivs två gånger.
 *
 * **Linjerna mellan blocken kom med issue 677** ([[ADR-0050 Desktopdesignen]]
 * § 1) och står bara i panelen, aldrig i mobilmenyn. De är fyra: före
 * containerlistan (`v-if="user"`), före raderna, före favoriterna
 * (`v-if="hasFavorites"`) och överst i kontoblocket.
 *
 * **Under `md:` är skalet oförändrat** sedan issue 151 — samma topprad,
 * flikrad och meny — med ett undantag: menyn får containerlistan, för dess
 * innehåll är skalets sektioner och ingenting eget.
 *
 * **Innehållsytan har inget tak** (issue 1026). Den släpptes från 768 px
 * till `max-w-[96rem]` i issue 169, så att panelen fick plats bredvid den och
 * itemets trepanel (issue 181) rymdes. På en bred skärm lämnade taket stora
 * tomma ytor på båda sidor om innehållet, så nu fyller ytan allt bredvid
 * sidopanelen. ADR-0050 kräver "över 1 200 px", inte högst något.
 */
const props = defineProps({
    /*
     * Plusknappens mål, ur App\Support\Frontend\CreateTarget, eller null.
     * Formen är `{ kind, href }` för ett mål och `{ kind: 'menu', rows }` för
     * en meny — se CreateButton. Kortkommandot `N` frågar samma propp.
     */
    create: { type: Object, default: null },
});

const { t } = useTranslations();
const page = usePage();
const user = computed(() => page.props.auth.user);

/* Sidans formulär i vyn nås genom samma räknare som plusknappens klick. */
const { request } = useInlineCreate();

/*
 * Toppraden på mobilen ägs av skalet men fylls av containern (issue 275 ·
 * [[ADR-0056 Flytande navigering]] § 1). Skalet ritar målet `#shell-topbar`
 * och ett märke; ligger sidan i en container teleporterar `ContainerLayout`
 * in sin bild, sitt namn och sin tillbakaknapp i målet och sätter den här
 * flaggan, så att märket viker. Flaggan är skalets tillstånd och går nedåt
 * som en `provide`: den inre layouten kan inte skriva till en slot, men den
 * kan märka raden som tagen.
 */
const topbarClaimed = ref(false);

provide('shellTopbar', topbarClaimed);

const menuOpen = ref(false);
const menuTrigger = ref(null);

function openMenu(element) {
    menuTrigger.value = element;
    menuOpen.value = true;
}

function closeMenu() {
    menuOpen.value = false;
}

const createMenuOpen = ref(false);
const createTrigger = ref(null);

function openCreateMenu(element) {
    createTrigger.value = element;
    createMenuOpen.value = true;
}

function closeCreateMenu() {
    createMenuOpen.value = false;
}

/*
 * Menyerna stängs vid navigering, se issue 273.
 *
 * Sedan sidorna deklarerar sin layout med `defineOptions` behåller Inertia
 * skalets instans mellan två sidor — och därmed dess tillstånd. Utan raden
 * står mobilmenyn öppen över nästa sida efter ett klick i den, för `menuOpen`
 * överlevde bytet. `page.url` är den enda klocka som behövs: en visit som
 * byter adress stänger båda menyerna, och en som stannar kvar rör dem inte.
 */
watch(() => page.url, () => {
    menuOpen.value = false;
    createMenuOpen.value = false;
});

/*
 * Kortkommandot `N`, se [[ADR-0074 Registrering i vyn]] § 7 och tillägget
 * 2026-11-18 (issue 1401).
 *
 * **Det första globala kortkommandot i appen**, och därför det enda stället
 * som lyssnar på tangentbordet utanför ett fält. `N` gör samma sak som
 * plusknappen på sidan man står på. Kortkommandot har ingen egen logik för
 * vad som ska skapas: det frågar samma `create`-propp som knappen ritar ur,
 * och en sida utan propp har ingen knapp — där finns ingenting att trycka på.
 *
 * **Lyssnaren sitter på `window` medan skalet är monterat.** Inertia behåller
 * skalet mellan två sidor, så lyssnaren läggs en gång vid monteringen och tas
 * bort vid avmonteringen; en lyssnare som blev kvar hade svarat på nästa sidas
 * tangenttryck också.
 *
 * **`N` ignoreras där bokstaven betyder något annat.** I ett textfält, under
 * IME-inmatning, med Ctrl, Meta eller Alt nedtryckt, med en dialog öppen —
 * skalets egen meny, ett ark eller vilket `<dialog open>` som helst, för de är
 * alla webbläsarens fokusfälla — och när någon redan har hanterat händelsen.
 * Shift är tillåtet, så att Caps Lock inte stänger av kortkommandot. I alla
 * andra fall stryks bokstaven med `preventDefault()`.
 */
const TEXT_FIELDS = ['INPUT', 'TEXTAREA', 'SELECT'];

/* Står fokus i något användaren skriver i? */
function isEditingField(element) {
    return element instanceof HTMLElement
        && (element.isContentEditable || TEXT_FIELDS.includes(element.tagName));
}

/*
 * Plusknappen som hör till skärmen. Två ritas ur samma mål — sidhuvudets över
 * `md:` och flikradens mitt under — och menyn ska fästas vid den som syns och
 * lämna tillbaka fokus dit när den stängs.
 */
function visibleCreateButton() {
    const buttons = Array.from(document.querySelectorAll('[data-create-button]'));

    return buttons.find((button) => button.getClientRects().length > 0) ?? null;
}

function onKeydown(event) {
    if (event.defaultPrevented || event.isComposing) {
        return;
    }

    if (event.key !== 'n' && event.key !== 'N') {
        return;
    }

    if (event.ctrlKey || event.metaKey || event.altKey) {
        return;
    }

    if (isEditingField(event.target)) {
        return;
    }

    if (menuOpen.value || document.querySelector('dialog[open]')) {
        return;
    }

    event.preventDefault();

    if (!props.create) {
        return;
    }

    if (props.create.kind === 'inline') {
        request();
    } else if (props.create.kind === 'menu') {
        openCreateMenu(visibleCreateButton());
    } else if (props.create.href) {
        router.visit(props.create.href);
    }
}

const showsVerificationNotice = computed(
    () => Boolean(user.value) && user.value.email_verified_at === null && !page.url.startsWith('/email/verify'),
);

/* Favoriterna är en delad propp, och en tom lista är samma sak som ingen sektion. */
const hasFavorites = computed(() => (page.props.favorites ?? []).length > 0);

/*
 * Ritas desktopens sidopanel? — frågan om containerlistan ställs bara när
 * svaret är ja (issue 169).
 *
 * Panelen är `hidden md:block` och alltså alltid i DOM:en: CSS avgör om den
 * syns, och `v-if` hade tvingat fram en andra brytpunkt i JavaScript.
 * Brytpunkten läses därför ur `matchMedia` i stället — samma 768 px som
 * Tailwinds `md:` — och den läses vid monteringen och inte vid varje
 * omskrivning: fönstret får byta storlek utan att listan frågas om igen.
 *
 * **`false` till dess att svaret är här**, alltså på en telefon. Fönstret
 * finns inte vid en serverrendering, och den här appen renderas i klienten —
 * men en `ref` som startade sant hade ställt frågan en gång för mycket på en
 * telefon, och det är den ena frågan hela den optionala proppen finns för att
 * spara.
 */
const isDesktopPanel = ref(false);

onMounted(() => {
    isDesktopPanel.value = window.matchMedia('(min-width: 768px)').matches;

    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
});

/*
 * Profilbilden i toppraden, se [[ADR-0076 Profilbilden]] § 6 och 7 samt
 * [[M59 Profilbilden]] § 381 och § 382. Cirkeln ritas av
 * resources/js/components/UserAvatar.vue — samma komponent som
 * förhandsvisningen och redigeraren på profilsidan — så initialerna,
 * `?variant=thumb`, utsnittet och `@error`-fallbacken bor på ett ställe.
 * Issue 381 höll formeln här; issue 382 flyttade den när utsnittet kom.
 *
 * Länkens tillgängliga namn är oförändrat: `UserAvatar` ritar `alt=""`, och
 * `aria-label` på länken bär personens namn för en skärmläsare.
 */
</script>

<template>
    <div class="flex min-h-full flex-col bg-slate-50 text-slate-900 md:flex-row">
        <!--
            SIDOPANELEN över `md:` — desktopens skal, se issue 169 och
            [[ADR-0050 Desktopdesignen]] § 1. Mörk yta ur `--color-shell`, och
            sektionerna i mockupens ordning (issue 653 och 677): översikten,
            containrarna, resten av raderna, favoriterna, papperskorgen — och
            användaren med vägen ut sist, tryckt till botten av `mt-auto`.

            **Sektionerna ritas av samma komponenter som mobilens sidomeny**
            (ShellSections, ShellContainerList), och samma proppar går till
            båda: `isDesktopPanel` är det här skalets svar på "panelen ritas",
            och MobileMenu svarar med sin egen öppning.

            **Linjerna mellan blocken står bara här** (issue 677): mobilmenyn
            får inga. Den första och den tredje ritas bara när blocket under
            dem har ett innehåll.

            **`shell-tone` binder om textrollerna inuti ytan** — se
            resources/css/app.css. Utan den hade UiListRows titel stått i
            nästan-svart på den mörka ytan; med den ritas samma komponenter i
            både den mörka panelen och den ljusa menyn.

            **Favoriterna ritas bara när listan har rader** (ShellSections),
            men containerblocket ritas även tomt: dess sista rad är *Add
            container*, och den finns för varje inloggad.
        -->
        <aside
            class="shell-tone hidden bg-shell text-ink md:sticky md:top-0 md:flex md:h-screen md:w-64 md:shrink-0 md:flex-col md:gap-2 md:overflow-y-auto md:px-4 md:py-4"
        >
            <Link href="/" class="inline-flex min-h-11 items-center px-2 text-lg font-semibold">
                {{ t('common.brand') }}
            </Link>

            <!--
                Navigeringen: översikten, containrarna och resten av raderna.
                `id="huvudmenyn"` står kvar på omslutningen: det är samma
                navigering som förut, flyttad in i panelen, och namnet är det
                mobilskalet och proven känner den under.
            -->
            <nav id="huvudmenyn" class="w-full">
                <ShellSections part="top" />

                <hr v-if="user" class="my-2 border-white/10" />

                <ShellContainerList v-if="user" :load="isDesktopPanel" />

                <hr class="my-2 border-white/10" />

                <ShellSections />
            </nav>

            <hr v-if="hasFavorites" class="my-2 border-white/10" />

            <ShellSections part="favorites" />

            <!--
                Användarens namn och vägen ut, tryckta till panelens botten.
                Papperskorgen står i samma block och strax ovanför avdelaren
                (M39 · issue 320 · [[ADR-0065 Den samlade papperskorgen]] § 1):
                den hör till panelens nederkant och inte till raderna, och
                därför ritas den ur sin egen del.
            -->
            <div class="mt-auto">
                <ShellSections part="trash" />

                <hr class="my-2 border-white/10" />

                <ShellSections part="account" />
            </div>
        </aside>

        <div class="flex min-h-full min-w-0 flex-1 flex-col">
            <!--
                Toppraden över `md:`. Den bär plusknappen, sökfältet, klockan
                och avataren ([[ADR-0050 Desktopdesignen]] § 1), och raderna
                den hade förut står nu i sidopanelen.

                **Bredden har inget tak** (issue 1026), samma som
                innehållsytan under: raden fyller allt bredvid sidopanelen, så
                att sökfältet står i linje med innehållets högerkant.
            -->
            <header class="hidden border-b border-slate-200 bg-white md:block">
                <div class="flex w-full items-center gap-2 px-4 py-2">
                    <!--
                        Sökfältet ligger på sin egen rad under `md:` och skjuts
                        till höger över det. Klasserna sitter på en omslutande
                        div och inte på komponenten: `SokvyTest` läser taggen
                        `<SearchField v-if="user" />` som den står, och villkoret
                        är det testet handlar om.
                    -->
                    <div class="flex w-full items-center gap-2 md:ml-auto md:w-auto">
                        <!--
                            Plusknappen i sidhuvudet, se issue 152. Den står först i
                            skalets åtgärdsgrupp — före sökfältet och klockan — för
                            att skapa är det man gör och de två andra är ytor man
                            tittar i. Över `md:` finns ingen flikrad, och det här är
                            samma knapp på samma plats i skalet.
                        -->
                        <CreateButton v-if="create" :create="create" @open="openCreateMenu" />

                        <SearchField v-if="user" />
                        <NotificationBell v-if="user" />

                        <!--
                            Avataren, se issue 169 och [[ADR-0050
                            Desktopdesignen]] § 1. Den är användarens egen rad i
                            toppraden och pekar på `/settings`, samma mål som
                            raden i sidopanelen.

                            **Bilden när den finns, initialerna annars** (issue
                            381 och 382 · [[ADR-0076 Profilbilden]] § 6 och 7).

                            Cirkeln ritas av `UserAvatar` med storleken 44 px,
                            samma mått som raden. `alt=""` med flit: bilden är
                            användarens eget ansikte i hennes egen rad, och
                            länkens tillgängliga namn kommer ur `user.name` i
                            `aria-label` — samma namn för en skärmläsare som
                            för en muspekare.
                        -->
                        <Link
                            v-if="user"
                            href="/settings"
                            :aria-label="user.name"
                            :title="user.name"
                            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-pill bg-surface-sunken text-title font-semibold text-ink-muted outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        >
                            <UserAvatar :user="user" :size="44" />
                        </Link>
                    </div>
                </div>
            </header>

            <!--
                Toppraden på mobilen. Ligger sidan i en container bär den
                containerns bild, namn och en tillbakaknapp (issue 151), och
                märket annars.

                **Innehållet kommer från `ContainerLayout`, teleporterat in i
                `#shell-topbar`** (issue 275 · [[ADR-0056 Flytande navigering]]
                § 1). Skalet ligger utanför containerns layout i kedjan, och en
                slot fylls nedåt — så den inre layouten kan inte fylla en slot
                här, men den kan teleportera sin markup in i målet och märka
                raden som tagen. Skalet läser därför varken `container` eller
                `can`; den som har svaret ritar det.
            -->
            <header class="bg-shell text-white md:hidden">
                <div class="flex w-full items-center gap-2 px-4 py-2">
                    <!--
                        Målet för containerns teleport. `contents` gör att
                        omslaget inte deltar i flexraden: innehållet lägger sig
                        där målet står, jämte märket.
                    -->
                    <div id="shell-topbar" class="contents"></div>

                    <!-- Märket, så länge ingen container har tagit raden. -->
                    <p v-if="!topbarClaimed" class="text-title font-semibold">{{ t('common.brand') }}</p>

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

            <div v-if="showsVerificationNotice" class="w-full px-4 pt-6">
                <p class="mb-2 text-sm font-medium text-slate-800">{{ t('auth.verify.banner') }}</p>
                <VerifyEmailNotice />
            </div>

            <FlashMessage />

            <!--
                Innehållsytan. Den var 768 px, sedan `max-w-[96rem]` (issue
                169), och har sedan issue 1026 inget tak: på en bred skärm ska
                presentationen använda hela ytan bredvid sidopanelen.
            -->
            <main class="w-full flex-1 px-4 pt-8 pb-24 md:pb-8">
                <slot />
            </main>

            <!--
                Sidfoten — bara över `md:`. Raden med de juridiska sidorna kom
                med M86 · issue 480 och läser samma `auth.landing.links.*` som
                inloggningssidans rad, så ingen nyckel dubbleras. Under `md:`
                ritas ingen sidfot, och inloggningssidans rad syns på mobilen.
            -->
            <footer class="hidden border-t border-slate-200 py-4 text-center text-xs text-slate-600 md:block">
                <nav class="mb-2 flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
                    <Link href="/about" class="inline-flex min-h-11 items-center hover:underline">{{ t('auth.landing.links.about') }}</Link>
                    <Link href="/privacy" class="inline-flex min-h-11 items-center hover:underline">{{ t('auth.landing.links.privacy') }}</Link>
                    <Link href="/terms" class="inline-flex min-h-11 items-center hover:underline">{{ t('auth.landing.links.terms') }}</Link>
                    <Link href="/subprocessors" class="inline-flex min-h-11 items-center hover:underline">{{ t('auth.landing.links.subprocessors') }}</Link>
                </nav>

                {{ t('common.footer', { year: page.props.app.year, version: page.props.app.version }) }}
            </footer>
        </div>

        <!--
            Mobilskalet, se issue 151. Flikraden ligger fast i botten och
            menyn är en dialog; båda ritas bara för en inloggad, för det är
            hennes mål de bär. De står utanför innehållskolumnen: de är
            skalets egna ytor och inte sidans, och de ligger utanför flödet
            ändå.
        -->
        <template v-if="user">
            <MobileTabBar
                :create="create"
                :menu-open="menuOpen"
                @open-create="openCreateMenu"
                @open-menu="openMenu"
            />
            <MobileMenu :open="menuOpen" :trigger="menuTrigger" @close="closeMenu" />
        </template>

        <!--
            Menyn ritas bara när målet ÄR en meny. En sida vars knapp leder
            till ett formulär har ingenting att öppna, och ett ark utan rader
            vore en tom yta.

            **`create.heading` är en NYCKEL och ingen färdig mening.** Målet
            kommer ur App\Support\Frontend\CreateTarget, som skickar
            `pick_item` för containerns flikar (M28), och nyckeln går därför in
            som `headingKey`, som CreateMenu slår upp. CreateMenus `heading` är
            en annan prop: den bär färdig text — fokuskartans nodnamn (issue
            156) — och ritas ordagrant, för användarens eget namn får aldrig
            slås upp som en nyckel. Ett mål utan rubrik får skalets ord.
        -->
        <CreateMenu
            v-if="create && create.kind === 'menu'"
            :open="createMenuOpen"
            :trigger="createTrigger"
            :rows="create.rows"
            :heading-key="create.heading"
            @close="closeCreateMenu"
        />
    </div>
</template>
