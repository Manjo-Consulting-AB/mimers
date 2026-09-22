<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../../layouts/ContainerLayout.vue';
import ItemAttachmentSection from '../../../components/ItemAttachmentSection.vue';
import ItemLinkSection from '../../../components/ItemLinkSection.vue';
import ItemLoanSection from '../../../components/ItemLoanSection.vue';
import ItemTagList from '../../../components/ItemTagList.vue';
import ScheduleListSection from '../../../components/ScheduleListSection.vue';
import UiTabs from '../../../components/UiTabs.vue';
import { itemFields } from '../../../components/itemPresentation.js';
import { useTranslations } from '../../../composables/useTranslations.js';

/*
 * Itemets detaljvy, se issue 57a § Beslut 4, 5, 6, 7 och 8, och issue 58.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource.
 *
 * **Itemets egna fält, kategorin, taggarna, relationerna, utlåningen,
 * schemana och bilagorna.** Bilagesektionen kom med issue 60 och bor i
 * resources/js/components/ItemAttachmentSection.vue; listan kommer med
 * detaljvyns props och har ingen egen rutt. Schemana kom med issue 63a och
 * gör detsamma — ScheduleListSection.vue, proparna `schedules` och
 * `openOccurrences`. Utlåningen kom med issue 67a och gör detsamma —
 * ItemLoanSection.vue, proparna `openLoan`, `loanHistory`, `openLoanOverdue`
 * och `today`.
 * Kostnaderna 45–47 har fortfarande ingen yta här.
 * Relationssektionen bor i resources/js/components/ItemLinkSection.vue: alla
 * tre bär sitt eget formulär och sina egna fel, precis som ContainerAccessRow
 * gör för åtkomsterna, så ett fältfel på en relation, ett schema eller en fil
 * inte färgar resten av sidan. Ordningen på ytorna är itemets egna uppgifter,
 * sedan relationerna, sedan utlåningen, sedan schemana, sedan bilagorna.
 *
 * **Ett tomt fält utelämnas, aldrig påhittat** (Beslut 8). `fields` filtrerar
 * bort `null` och tomma strängar, så en rad utan beskrivning visar ingen
 * beskrivningsrad — den visar inte en tom etikett och inte ett streck.
 *
 * **Datumen är DATE-kolumner** och formateras med formatDateOnly(), som
 * bygger datumet i lokal tid i stället för att tolka strängen som UTC — se
 * modulens docblock. De räknas aldrig om till en annan tidszon.
 *
 * **`can` ritar skrivytorna** (Beslut 6 och issue 57b § Beslut 2 och 8). Varje
 * flagga är sin egen grind: `can.update` är `ItemPolicy::update()` (`write`-
 * pinnen), `can.delete` är `ItemPolicy::delete()` (`delete`-pinnen, en pinne
 * högre) och `can.create` är `ItemPolicy::create()` på ITEMET. En användare
 * med bara `read` får alla falska och ser ingen skrivyta alls; en
 * `write`-mottagare ser redigeringen men inte raderingsknappen.
 *
 * **`can.create` ritar två ytor** (issue 58 § Beslut 7): länken *Nytt item
 * under det här*, som går till skapandeformuläret med `?parent`, och
 * relationsformuläret inuti ItemLinkSection. Båda är samma grind som
 * `ItemController::store()` prövar mot föräldern, så en `create`-mottagare
 * som nått det här itemet ser dem och en `read`-mottagare inte.
 *
 * **Raderingen bekräftas och säger vad som händer** (§ Beslut 8). Den är mjuk
 * — `deleted_at` sätts och ingenting annat ([[ADR-0008 Soft delete och
 * papperskorg]]) — så texten säger papperskorgen och de 30 dagarna, aldrig
 * "raderas permanent", vilket vore osant. Ingen kaskadtext om bilagor, scheman
 * eller kostnader: de följer itemet, och papperskorgen är issue 62.
 *
 * Kategorinamnet slås upp i `categories` (ULID → namn), byggd bredvid
 * resursen i kontrollern — se App\Http\Controllers\ItemController. ItemResource
 * bär bara kategorins ULID.
 *
 * **Förekomsterna ritas som en brödsmula och en lista** (issue 95 ·
 * [[ADR-0041 Itemets vy]] § Beslut). Ett item som hänger under två föräldrar
 * har två vägar upp, och mockupen visar båda. Ingen kolumn pekar ut en
 * huvudplats: servern löser upp vägarna, querysträngen väljer vilken som är
 * den aktuella, och den här filen vandrar inte i grafen och sorterar inte om
 * listan (issue 57a § Beslut 8).
 *
 * **Sidan är en flikrad med sex flikar** (issue 102 ·
 * [[M17 Designsystemet]] § 102). Fram till dess renderades fälten, taggarna,
 * relationerna, utlåningen, schemana och bilagorna på en enda lång sida; nu
 * ligger var och en i sin flik — fälten på översikten, som är radens första —
 * och raden byggs av `UiTabs` (issue 100) precis som containerns. Issuen är en
 * omfördelning av det som redan hämtas: ingen prop tillkommer, ingen fråga
 * ställs och kontrollern är orörd.
 *
 * **Flikraden ligger inuti containerns ram** ([[ADR-0041 Itemets vy]]
 * § Beslut). Itemet bor i containern, och bildens globala vänstermeny med
 * egna rader för Struktur, Karta, Uppgifter, Dokument och Kostnader tas inte
 * in — den är avvisad två gånger, i ADR-0041 och i [[ADR-0042
 * Designsystemet]] § Beslut. Ramen är `ContainerLayout`, och flikraden står
 * i dess slot: brödsmulan, namnet och radåtgärderna ovanför hör till itemets
 * huvud och står kvar på varje flik.
 *
 * **Den aktiva fliken läses ur adressen, aldrig ur ett eget tillstånd.**
 * `?tab=` väljer panel — samma konstruktion som `?path=` i issue 95 och
 * samma skäl: en flik man kan länka till är en flik man kan dela. `UiTabs`
 * läser samma adress och avgör vilken rad som lyser, så de två kan inte glida
 * isär; den här filen läser parametern för att veta vilken panel som ska
 * renderas och faller tillbaka på översikten när parametern inte är en fliks.
 * Den faller tillbaka på SAMMA flik som `UiTabs` tänder: en okänd `?tab=`
 * matchar ingen fliks `href`, och översikten är den enda som matchar på
 * sökvägen allena (issue 100).
 *
 * **Flikens href bär den aktuella förekomsten.** `?tab=` är flikradens egen
 * nyckel och `?path=` är vyns tillstånd, som raden bara bär med sig: varje
 * flik skriver samma `path` som adressen den står i, så ett flikbyte stannar
 * på samma förekomst. Tappade fliken vägen blev vyn en icke-delbar länk så
 * snart man klickat en gång, och den bytte dessutom rad i brödsmulan och i
 * *Förekomster i struktur* utan att någon bett om det — samma väg läses i
 * strukturpanelen i issue 103 § *Klart när*. Översikten är frånvaron av `tab`
 * och ingenting annat: den skrivs som itemets egen sökväg, med `?path=` när
 * adressen har en och utan när den inte har det. Ordningen är `path` före
 * `tab`, så att jämförelsen i `UiTabs` fortsätter hålla — den aktuella
 * adressen innehåller samma `path`.
 *
 * **Räknaren är antalet rader fliken ritar**, och `null` för översikten, som
 * bär itemets eget innehåll och ingen lista över något annat. En nolla är ett
 * påstående anroparen HAR gjort — fliken ritar noll rader — och `UiTabs`
 * skiljer den från `null`, som betyder att det inte finns något tal att visa
 * (issue 99 och 100).
 */
const props = defineProps({
    container: { type: Object, required: true },
    item: { type: Object, required: true },
    /* Kategori-ULID → namn; tom när itemet saknar kategori. */
    categories: { type: Object, required: true },
    /*
     * Itemets förekomster i strukturen, ur
     * App\Actions\Item\ResolveItemPaths och byggda bredvid resursen i
     * kontrollern (issue 95): alla vägar från en rot ned till itemet, varje
     * väg som sina led `{ulid, name}`, i serverns ordning — namnen längs
     * vägen — och exakt en av dem märkt `current`.
     *
     * Listan är TOM för ett item utan väg: en ren cykel i grafen har ingen
     * rot, och då ritas varken brödsmulan eller listan. Det är rotregeln och
     * inte ett feltillstånd — se App\Actions\Item\ResolveItemPaths.
     *
     * En väg som inte längre finns är redan utbytt mot den första i ordningen
     * när den här proppen kommer hit: vyn får aldrig veta att något föll
     * bort, och den ska inte kunna räkna det ur svaret (issue 73 § Beslut 6).
     */
    paths: { type: Array, required: true },
    /*
     * Itemets bilagor ur App\Http\Resources\AttachmentResource, nyast först —
     * samma lista och samma ordning som `/api` ger (issue 60 § Beslut 2).
     */
    attachments: { type: Array, required: true },
    /*
     * Bilagans ULID → de derivatvarianter som FINNS, byggd på servern bredvid
     * AttachmentResource (issue 61b § Beslut 1). Vyn gissar aldrig: en
     * `?variant=thumb` mot en bilaga utan derivat är 404, och en miniatyr
     * ritas därför bara när varianten står i den här tabellen.
     */
    variants: { type: Object, required: true },
    /*
     * Sant när användarfiler levereras från en egen origin (issue 61b
     * § Beslut 2). Är den falsk är allt `attachment`, och då ritas varken
     * bildvisaren eller PDF-ramen — bilagesektionen ser ut som i 60a.
     */
    inlineEnabled: { type: Boolean, required: true },
    /*
     * Det TEKNISKA taket på en fil, ur `config('files.max_upload_bytes')`
     * (issue 60b § Beslut 5). Bilagesektionen avvisar en för stor fil med
     * det här talet innan bytena skickas; servern prövar samma tak igen i
     * StoreAttachmentRequest.
     */
    maxUploadBytes: { type: Number, required: true },
    /*
     * Relationerna grupperade i överordnade, underordnade och syskon — redan
     * filtrerade per omfång av servern (issue 58 § Beslut 2 och 3).
     */
    links: { type: Object, required: true },
    /* Items användaren får ändra och som inte redan är kopplade. */
    counterparts: { type: Array, required: true },
    /*
     * Itemets scheman ur App\Http\Resources\ScheduleResource, sorterade på
     * titel — samma lista och samma ordning som `/api` ger (issue 63a
     * § Beslut 1). Schemat är REGELN; förekomsterna är 63b och bor inte här.
     */
    schedules: { type: Array, required: true },
    /*
     * Schemats ULID → den öppna förekomsten ur ScheduleOccurrenceResource,
     * eller `null`, byggd på servern bredvid ScheduleResource (issue 63a
     * § Beslut 1, issue 63b § Beslut 1). Resursen bär inget `next_due_at` med
     * flit — nästa förfall bor på förekomsten, aldrig på schemat — så
     * uppslaget kommer som en egen prop. Sedan 63b är det förekomsten och
     * inte datumet: avbockningen från sektionen behöver ULID:n att posta mot,
     * `overdue` att märka raden med och `visible_from` att visa glappet med.
     */
    openOccurrences: { type: Object, required: true },
    /*
     * Den öppna utlåningen ur App\Http\Resources\LoanResource, eller `null`
     * (issue 67a § Beslut 2). Itemets enda status en annan medlem behöver se
     * på en sekund, och den kommer färdigräknad från servern: `returned_at IS
     * NULL` är den öppna.
     */
    openLoan: { type: Object, default: null },
    /*
     * Är den öppna utlåningen försenad? Räknat på serverns datum (Beslut 5) —
     * samma regel som `overdue` i ScheduleOccurrenceResource, så en klient med
     * fel klocka inte kan färga en utlåning röd.
     */
    openLoanOverdue: { type: Boolean, required: true },
    /* De avslutade utlåningarna, i samma ordning som `/api` ger dem. */
    loanHistory: { type: Array, required: true },
    /* Serverns datum, `Y-m-d` — "Tillbaka idag" sätter det (Beslut 3). */
    today: { type: String, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const locale = computed(() => page.props.locale);

/*
 * Itemets egna fält, filtrerade och formaterade i
 * resources/js/components/itemPresentation.js — se den modulens docblock för
 * varför tomma rader utelämnas och varför datumen inte räknas om.
 *
 * Det här är FÄLTRADERNA på översiktsfliken, under itemets ledande stycken.
 * `description` och `notes` står inte bland dem: de är styckena och hade i en
 * lista bland tillverkare och modell varit två rader av fel sort (issue 96
 * och 102).
 */
const fields = computed(() =>
    itemFields(props.item, locale.value).map((field) => ({
        ...field,
        label: t(`item.show.${field.key}`),
    })),
);

const categoryName = computed(() => props.categories[props.item.category] ?? null);

/*
 * Översikten med ingenting i: varken ledande stycken eller ett enda fält.
 * Raden är HELA panelens tillstånd och inte de två styckenas — ett item med
 * en tillverkare men utan beskrivning är skrivet, och "ingenting är skrivet om
 * det här itemet än" över en fylld fältlista hade varit osant.
 */
const overviewEmpty = computed(
    () => ! props.item.description && ! props.item.notes && fields.value.length === 0 && categoryName.value === null,
);

/*
 * Flikraden i den form `UiTabs` vill ha: `{ key, label, href, count }`.
 *
 * **Raden har SEX flikar, och översikten ÄR fältens flik.** Bilden ritar sju
 * minus historiken och kostnaderna — fem — och utlåningen läggs till som
 * sjätte; "fälten" ur de sex proparna är översiktsfliken och ingen egen rad.
 * Det finns alltså ingen sjunde flik som bär två stycken text: anteckningen
 * och beskrivningen står överst på översikten och fältlistan under dem
 * ([[ADR-0041 Itemets vy]] § Beslut, issue 96).
 *
 * **Ordningen är bildens, och de två rader bilden inte har kommer sist.**
 * `docs/Design/struktur - item.jpeg` ritar översikt, relationer, dokument,
 * uppgifter och historik; kostnaden och historiken byggs inte här
 * (kostnadsfliken väntar på trepanelslayouten och historiken på
 * instrumenteringen), och utlåningen och taggarna har ingen rad i bilden men
 * måste ändå få en plats — en yta ingen hittar är samma sak som en yta som
 * inte finns (issue 62a:s och 67c:s motivering). De står därför efter de fem,
 * i den ordning issuen räknar dem: översikten, relationerna, bilagorna,
 * schemana, utlåningen och taggarna.
 *
 * **Etiketten är sektionens eget ord.** Fem av flikarna bär samma rubrik som
 * sektionen de visar — `item.links.heading`, `item.attachment.heading`,
 * `item.schedule.heading`, `item.loan.heading` och `item.show.tags` — så att
 * fliken och rubriken strax under den aldrig kan säga olika saker. Bara
 * översikten lånar inget ord: ingen sektion äger den, och den har därför en
 * egen nyckel.
 *
 * **`href` byggs ur itemets egen adress och bär den aktuella förekomsten.**
 * Flikarna ligger på samma sökväg och skiljs av `?tab=` (issue 100); `path`
 * är vyns tillstånd och skrivs FÖRE `tab`, i samma ordning som adressen vyn
 * står i, så att `UiTabs` känner igen den aktuella raden även när en väg är
 * vald. `tab` och `path` är engelska med flit (AGENTS.md § Språk i koden).
 *
 * **`count` räknas ur proparna och aldrig ur en egen förfrågan.** Det är
 * antalet rader fliken ritar: motparterna i sina tre grupper, bilagorna,
 * schemana, utlåningarna — den öppna är en rad — och taggarna. En flik som
 * ritar en lista bär sin räknare även när den är noll: `0` säger att listan är
 * tom och en saknad räknare att det inte finns någon lista (issue 99).
 * Översikten bär `null` — den ritar itemets eget innehåll och ingen lista över
 * något annat.
 */
const tabs = computed(() => {
    const base = `/containers/${props.container.ulid}/items/${props.item.ulid}`;
    const path = new URLSearchParams(page.url.split('?')[1] ?? '').get('path');
    const here = path === null ? '' : `?path=${path}`;

    const tabHref = (key) => (path === null ? `${base}?tab=${key}` : `${base}?path=${path}&tab=${key}`);

    return [
        { key: 'overview', label: t('item.show.overview'), href: `${base}${here}`, count: null },
        { key: 'relations', label: t('item.links.heading'), href: tabHref('relations'), count: props.links.parent.length + props.links.child.length + props.links.related.length },
        { key: 'attachments', label: t('item.attachment.heading'), href: tabHref('attachments'), count: props.attachments.length },
        { key: 'schedules', label: t('item.schedule.heading'), href: tabHref('schedules'), count: props.schedules.length },
        { key: 'loans', label: t('item.loan.heading'), href: tabHref('loans'), count: props.loanHistory.length + (props.openLoan ? 1 : 0) },
        { key: 'tags', label: t('item.show.tags'), href: tabHref('tags'), count: props.item.tags.length },
    ];
});

/*
 * Fliken vars panel renderas, läst ur adressen — se docblocken ovan.
 *
 * Bara EN panel ritas: sidan var en enda lång rad av sektioner, och det är
 * hela skillnaden issuen gör. Panelen är därför inte gömd med CSS utan
 * frånvarande, och de sektioner som inte visas mountas inte alls.
 *
 * Fallbacken är översikten, och den är inte en gissning: en `?tab=` som inte
 * är någon fliks matchar ingen `href`, och översikten är den enda flik som
 * matchar på sökvägen allena (issue 100). `UiTabs` tänder alltså samma rad som
 * den här raden visar panel för.
 */
const activeTab = computed(() => {
    const requested = new URLSearchParams(page.url.split('?')[1] ?? '').get('tab');

    return tabs.value.some((tab) => tab.key === requested) ? requested : 'overview';
});

/*
 * Den AKTUELLA förekomsten — den väg servern märkte. Vyn sorterar aldrig om
 * listan och väljer aldrig själv: markeringen kommer ur querysträngen, och
 * den som inte pekar på en väg som finns får den första i ordningen märkt utan
 * att vyn ser någon skillnad.
 *
 * `null` bara när `paths` är tom, alltså för ett item utan väg — då ritas
 * varken brödsmulan eller listan.
 */
const currentPath = computed(() => props.paths.find((path) => path.current) ?? null);

/*
 * Länken till en förekomst: ledets SISTA item med sin egen väg i
 * querysträngen. Ett klick på ett led i brödsmulan landar därför på samma
 * förekomst och inte på en godtycklig — vägen följer med upp, och `?path=`
 * betyder samma sak på varje items sida. Det är samma regel som filtret i
 * issue 59a § Beslut 1: ett läge är en delbar länk.
 */
function pathHref(nodes) {
    const last = nodes[nodes.length - 1];
    const chain = nodes.map((node) => node.ulid).join('.');

    return `/containers/${props.container.ulid}/items/${last.ulid}?path=${chain}`;
}

/*
 * Raderingen. Bekräftelsen är webbläsarens egen dialog med serverns mening ur
 * `lang/` — ingen modal komponent och ingen sträng i JavaScript.
 *
 * `router.delete` och inte en <Link method="delete">: bekräftelsen måste
 * kunna AVBRYTA navigeringen, och en knapp vars enda väg vidare är ett
 * klick-handtag är lättare att läsa än en länk vars klick går att stoppa.
 * CSRF-tokenet skickar Inertia åt oss.
 *
 * `pending` är radens eget vänteläge (issue 68a § Beslut 4 och 5): knappen är
 * stängd och byter ord medan servern svarar, så ett långsamt svar inte ser ut
 * som en död sida.
 */
const pending = ref(false);

function destroy() {
    if (! window.confirm(t('item.destroy.confirm'))) {
        return;
    }

    router.delete(`/containers/${props.container.ulid}/items/${props.item.ulid}`, {
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="item.name" />

        <!--
            Brödsmulan (issue 95): vägen från roten ned till itemet, den
            aktuella förekomsten. Sista ledet är itemet självt, alltså ingen
            länk — rubriken strax under säger samma namn. Sista ledet bär
            `aria-current="page"`, och <nav> bär sitt namn ur `lang/`:
            `breadcrumb` är ordet för ytan, inte för någon av förekomsterna.
        -->
        <nav
            v-if="currentPath"
            :aria-label="t('item.show.breadcrumb')"
            class="mb-2 text-sm text-slate-600"
        >
            <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <li
                    v-for="(node, index) in currentPath.nodes"
                    :key="`${node.ulid}-${index}`"
                    class="flex items-center gap-2"
                >
                    <Link
                        v-if="index < currentPath.nodes.length - 1"
                        :href="pathHref(currentPath.nodes.slice(0, index + 1))"
                        class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                    >
                        {{ node.name }}
                    </Link>
                    <span v-else aria-current="page">{{ node.name }}</span>

                    <span v-if="index < currentPath.nodes.length - 1" aria-hidden="true">›</span>
                </li>
            </ol>
        </nav>

        <h1 class="text-2xl font-semibold">{{ item.name }}</h1>

        <div class="mt-4 flex flex-wrap gap-4 text-sm">
            <Link
                v-if="can.update"
                :href="`/containers/${container.ulid}/items/${item.ulid}/edit`"
                class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
            >
                {{ t('item.edit.action') }}
            </Link>

            <button
                v-if="can.delete"
                type="button"
                :disabled="pending"
                class="inline-flex min-h-11 items-center font-medium text-red-700 hover:underline"
                @click="destroy"
            >
                {{ pending ? t('common.pending.default') : t('item.destroy.action') }}
            </button>

            <!--
                Barn-itemet (issue 58 § Beslut 7). Föräldern kommer ur länken
                och formuläret visar den som en rad text — den här vyn är
                detaljvyn för just det itemet, så frågan "under vad?" är redan
                besvarad och ställs inte igen.
            -->
            <Link
                v-if="can.create"
                :href="`/containers/${container.ulid}/items/create?parent=${item.ulid}`"
                class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
            >
                {{ t('item.links.create_child.action') }}
            </Link>
        </div>

        <!--
            Förekomstlistan (issue 95 · [[ADR-0041 Itemets vy]] § Beslut):
            samma vägar som brödsmulan visar en av, med den aktuella utmärkt.
            Raderna kommer i serverns ordning och sorteras aldrig här — för
            samma användare står brödsmulan och listan därför alltid i samma
            ordning (issue 57a § Beslut 8).

            Listan ritas bara när itemet har MER än en förekomst: med en enda
            hade den upprepat brödsmulan ordagrant och tillagt en rad utan
            innehåll. Antalet är antalet vägar mottagaren ser, och det avslöjar
            ingenting om dem hon inte ser.

            Rubriken är listans namn och kopplas till den med
            `aria-labelledby` — därför behövs ingen egen `aria-label`. Den
            aktuella raden bär `aria-current="true"` och ordet *Current* som
            SYNLIG text: markeringen får aldrig vara en färg allena. Orden
            kommer ur `lang/en/ui.php` (`item.show.placements`,
            `item.show.placement_current`), och ordet är *placement* och inte
            *occurrence* — se nyckelns kommentar där.
        -->
        <section v-if="paths.length > 1" class="mt-6">
            <h2 id="item-placements-heading" class="text-sm font-medium text-slate-600">
                {{ t('item.show.placements') }}
            </h2>

            <ul aria-labelledby="item-placements-heading" class="mt-2 space-y-1 text-sm">
                <li
                    v-for="(occurrence, index) in paths"
                    :key="index"
                    class="flex flex-wrap items-center gap-2"
                >
                    <Link
                        :href="pathHref(occurrence.nodes)"
                        :aria-current="occurrence.current ? 'true' : null"
                        class="flex min-h-11 flex-wrap items-center gap-1"
                        :class="occurrence.current
                            ? 'font-semibold text-slate-900'
                            : 'text-blue-700 hover:underline'"
                    >
                        <template v-for="(node, step) in occurrence.nodes" :key="`${node.ulid}-${step}`">
                            <span>{{ node.name }}</span>
                            <span v-if="step < occurrence.nodes.length - 1" aria-hidden="true">›</span>
                        </template>
                    </Link>

                    <span v-if="occurrence.current" class="rounded bg-slate-200 px-2 py-1 text-sm font-medium">
                        {{ t('item.show.placement_current') }}
                    </span>
                </li>
            </ul>
        </section>

        <!--
            Flikraden (issue 102). `label` är tablistens tillgängliga namn och
            är itemets namn — samma namn rubriken ovanför bär, och det som
            säger vilket item raden hör till (issue 100, samma val som
            containerns layout gör).
        -->
        <UiTabs class="mt-8" :tabs="tabs" :label="item.name" />

        <!--
            Översikten (issue 102 · [[ADR-0041 Itemets vy]] § Beslut):
            anteckningen och beskrivningen som vyns ledande stycken, inte som
            rader bland tillverkare och modell, och fältlistan under dem. De är
            två fält sedan issue 96 — beskrivningen säger vad itemet ÄR,
            anteckningen vad användaren VET om det — och de står därför var för
            sig med sin egen etikett, och aldrig som en sammanslagen text.

            **Översikten ÄR fältens flik.** Bildens *Detaljer* är ingen egen
            rad: raden har sex flikar och ingen sjunde som bär två stycken
            text, så tillverkaren, modellen, kategorin och resten står här,
            under styckena. Kategorin hör hemma i listan — den är ett
            strukturerat fält och ingen tagg — och ett tomt fält utelämnas
            (se itemPresentation.js).

            Ett item utan både stycken och fält är oskrivet och inte trasigt,
            och raden i stället för innehållet säger vilket: fliken är den
            första en läsare möter, och en tom panel där hade sagt att sidan
            är sönder.
        -->
        <section v-if="activeTab === 'overview'" class="mt-8 space-y-8">
            <dl v-if="item.description || item.notes" class="flex flex-col gap-6">
                <div v-if="item.description">
                    <dt class="text-sm font-medium text-slate-600">{{ t('item.show.description') }}</dt>
                    <dd class="mt-1 whitespace-pre-line text-slate-900">{{ item.description }}</dd>
                </div>

                <div v-if="item.notes">
                    <dt class="text-sm font-medium text-slate-600">{{ t('item.show.notes') }}</dt>
                    <dd class="mt-1 whitespace-pre-line text-slate-900">{{ item.notes }}</dd>
                </div>
            </dl>

            <dl
                v-if="fields.length > 0 || categoryName"
                class="grid grid-cols-1 gap-x-8 gap-y-4 md:grid-cols-2"
            >
                <div v-for="field in fields" :key="field.key">
                    <dt class="text-sm font-medium text-slate-600">{{ field.label }}</dt>
                    <dd class="mt-1 whitespace-pre-line text-slate-900">{{ field.value }}</dd>
                </div>

                <div v-if="categoryName">
                    <dt class="text-sm font-medium text-slate-600">{{ t('item.show.category') }}</dt>
                    <dd class="mt-1 text-slate-900">{{ categoryName }}</dd>
                </div>
            </dl>

            <p v-if="overviewEmpty" class="text-sm text-slate-600">{{ t('item.show.overview_empty') }}</p>
        </section>

        <!-- Relationerna (issue 58 § Beslut 9), i sin egen flik: sektionen
             finns redan och byter bara plats. -->
        <ItemLinkSection
            v-if="activeTab === 'relations'"
            :container-ulid="container.ulid"
            :item-ulid="item.ulid"
            :links="links"
            :counterparts="counterparts"
            :can="can"
        />

        <!-- Bilagorna (issue 60 § Beslut 1): itemets innehåll och inte en egen
             vy. `container.account` är containerns ägarkonto — sektionens
             förval när användaren är medlem i det. -->
        <ItemAttachmentSection
            v-if="activeTab === 'attachments'"
            :container-ulid="container.ulid"
            :item-ulid="item.ulid"
            :attachments="attachments"
            :variants="variants"
            :inline-enabled="inlineEnabled"
            :max-upload-bytes="maxUploadBytes"
            :container-account="container.account"
            :can="can"
        />

        <!--
            Schemana (issue 63a § Beslut 1): de är itemets egna uppgifter och
            inte en egen vy. Sedan issue 63b bär sektionen också den öppna
            förekomsten och avbockningen — det är produktens vanligaste
            skrivning och ska kosta en knapptryckning från itemet. Historiken
            ligger på schemats egen sida; beroendena är 63c och har ingen yta
            här. `container.account` är containerns ägarkonto och avbockningens
            förval när användaren är medlem i det.
        -->
        <ScheduleListSection
            v-if="activeTab === 'schedules'"
            :container-ulid="container.ulid"
            :item-ulid="item.ulid"
            :schedules="schedules"
            :open-occurrences="openOccurrences"
            :container-account="container.account"
            :can="can"
        />

        <!--
            Utlåningen (issue 67a § Beslut 2): den öppna utlåningen överst,
            historiken under. Sektionen får `today` — serverns datum — och
            `openLoanOverdue` från detaljvyns props och jämför aldrig något
            datum själv; se ItemLoanSection.vue.

            Den har ingen flik i bilden (issue 102) och får en ändå: itemets
            enda status en annan medlem behöver se på en sekund får inte
            försvinna i en omfördelning, och en yta ingen hittar är samma sak
            som en yta som inte finns.
        -->
        <ItemLoanSection
            v-if="activeTab === 'loans'"
            :container-ulid="container.ulid"
            :item-ulid="item.ulid"
            :open-loan="openLoan"
            :open-loan-overdue="openLoanOverdue"
            :loan-history="loanHistory"
            :today="today"
            :can="can"
        />

        <!-- Taggarna (issue 56a): itemets fria ord bredvid kategorin. Ett item
             utan taggar ritar ingenting här, och räknaren i fliken säger det. -->
        <section v-if="activeTab === 'tags'" class="mt-8">
            <template v-if="item.tags.length > 0">
                <h2 class="text-sm font-medium text-slate-600">{{ t('item.show.tags') }}</h2>

                <ItemTagList class="mt-2" :tags="item.tags" />
            </template>
        </section>
    </ContainerLayout>
</template>
