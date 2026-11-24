<script setup>
import { computed, onMounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import DashboardStats from './DashboardStats.vue';
import HeroCosts from './HeroCosts.vue';
import HeroFrame from './HeroFrame.vue';
import HeroStorage from './HeroStorage.vue';

/*
 * Den delade hjälten, se issue 437 · [[ADR-0090 Dashboardens hjälte]]
 * § Tillägg 2026-11-22. Dashboarden och elva toppnivåsidor ritar samma huvud:
 * bilden, textspalten och sifferraden. Bara rubriken och undertiteln skiljer
 * sidorna åt.
 *
 * **Ramen, textspalten och sifferraden ritas exakt som `DashboardHero` gjorde
 * före utbrytningen** (ADR-0090 § 1 och 6): `HeroFrame` med
 * `min-h-32 md:min-h-56`, hälsningen, `<h1>` och undertiteln i samma spalt,
 * och brickorna före kostnadssummeringen i samma rad. Den som ändrar det ena
 * ser det andra — därför en komponent och inte tolv kopior.
 *
 * **Hälsningen är dashboardens.** Utan `greeting` står en tom rad med samma
 * typografi och höjd, `aria-hidden="true"`, så att `<h1>` hamnar på samma
 * plats i bilden på varje sida. Det är hela skillnaden mellan dashboarden och
 * de andra sidorna i textspalten.
 *
 * **Siffrorna kommer färdigräknade, eller hämtas en gång.** Dashboarden
 * skickar `stats` och `costs` som proppar och gör därför ingen begäran.
 * Övriga sidor har ingen egen beräkning: de delar `heroFigures` som
 * `Inertia::optional` (App\Http\Middleware\HandleInertiaRequests), och
 * hjälten hämtar den med en partiell omladdning av just den nyckeln när den
 * saknas — samma mönster som `ItemTargetPicker` använder för `itemTargets`.
 * Medan svaret är på väg ritas ett skelett med samma mått som den fyllda
 * raden, så att hjälten inte ändrar höjd.
 *
 * **Sifferraden har ett fast golv: brickornas höjd.** `HeroCosts` ritar
 * ingenting utan kostnadsrader och är med en valuta lägre än brickorna, så
 * raden är lika hög för ett konto med en valuta som för ett utan. Klassen
 * `md:min-h-[102px]` är brickornas egen höjd: `UiStat`s `py-3` (24 px) +
 * rubriken (`text-heading`, 24 px × 1,5 = 36 px) + etiketten (`text-meta`,
 * 12 px × 1,5 = 18 px) + ramen (2 px) = 80 px, och uppgiftskolumnens
 * underrad (`gap-1` 4 px + 18 px) gör raden 102 px. Skelettet har samma
 * mått, så ramen står stilla när `heroFigures` kommer. Ändras `text-heading`
 * eller `text-meta` ändras talet med dem — det är priset för att höjden ska
 * stå stilla.
 *
 * **Lagringskortet står sist i sifferraden** (issue 476). Det läser den
 * delade `storageQuota`, som redan finns vid första ritningen, och ritas
 * därför i båda lägena — raden är lika hög före som efter att talen kommer.
 *
 * **Under `md:` är hjälten det låga bandet** (ADR-0090 § 6): hälsningen och
 * rubriken står kvar inne i bilden, undertiteln och sifferraden är dolda. Bara
 * `Dashboard.vue` ritar brickorna och kostnadssummeringen under bandet; de
 * andra sidorna lämnar telefonens listor i fred.
 */
const props = defineProps({
    /* Rubriken, färdigformulerad av sidan — nyckeln är sidans egen. */
    heading: { type: String, required: true },
    /* Undertiteln, ur `hero.subtitle.*` eller dashboardens egen nyckel. */
    subtitle: { type: String, default: '' },
    /*
     * Hälsningen, färdigformulerad av dashboarden. Utan den ritas en tom rad
     * på samma plats — de andra sidorna hälsar inte.
     */
    greeting: { type: String, default: null },
    /* `{ containers, tasks, overdue }` — dashboardens egen propp. */
    stats: { type: Object, default: null },
    /* Månadens kostnader, `{ totals }` — dashboardens egen propp. */
    costs: { type: Object, default: null },
});

const page = usePage();

/* Personens hjältebild, ur den delade proppen (issue 434). */
const hero = computed(() => page.props.auth?.user?.hero ?? null);

/* Den valfria proppen, när sidan inte har egna tal (issue 437). */
const figures = computed(() => page.props.heroFigures ?? null);

/* Sidans egna tal först — dashboarden gör därför ingen begäran. */
const heroStats = computed(() => props.stats ?? figures.value?.stats ?? null);
const heroCosts = computed(() => props.costs ?? figures.value?.costs ?? null);
const loaded = computed(() => heroStats.value !== null && heroCosts.value !== null);

/*
 * Kontots lagring, ur samma delade propp som kvotmodulen (issue 476). Den
 * finns redan vid första ritningen — den hämtas inte med `heroFigures` — så
 * kortet står i raden både före och efter att talen kommer. Då byter hjälten
 * aldrig höjd, och ett konto utan konto (en gäst) ritar inget kort.
 */
const storageQuota = computed(() => page.props.storageQuota ?? null);

/*
 * Siffrorna räknas bara när de efterfrågas: en sida som ritar hjälten men inte
 * fick talen med sig hämtar dem en gång, genom en partiell omladdning av just
 * `heroFigures`. Dashboarden har dem redan och frågar aldrig.
 */
onMounted(() => {
    if (! loaded.value) {
        router.reload({ only: ['heroFigures'] });
    }
});
</script>

<template>
    <!--
        `min-h-32` under `md:` gör hjälten till ett lågt band med hälsningen
        och rubriken; `md:min-h-56` ger den översta raden plats för brickorna
        och donuten. Under `md:` är sifferraden dold (ADR-0090 § 6).
    -->
    <HeroFrame
        :cover="hero"
        src="/images/dashboard-hero.jpg"
        class="min-h-32 md:min-h-56"
    >
        <div class="min-w-0 max-w-lg">
            <p v-if="props.greeting" class="text-meta">
                {{ props.greeting }}
            </p>

            <!--
                Utan hälsning står en tom rad på samma plats och med samma
                typografi, så att `<h1>` hamnar på samma höjd i bilden på
                varje sida. Den är pynt och döljs för skärmläsare.
            -->
            <p v-else class="text-meta" aria-hidden="true">&nbsp;</p>

            <h1 class="text-heading font-semibold">{{ props.heading }}</h1>

            <!-- Undertiteln hör till den breda formen; bandet bär bara
                 hälsningen och rubriken (ADR-0090 § 6). -->
            <p v-if="props.subtitle" class="mt-1 hidden text-body md:block">
                {{ props.subtitle }}
            </p>
        </div>

        <!--
            Sifferraden: brickorna först, kostnadssummeringen efter dem och
            lagringskortet sist (issue 474 och 476), som på dashboarden.
            `HeroCosts` ritar ingenting utan kostnadsrader, så brickorna är
            då radens enda barn. `md:min-h-[102px]` är radens golv — brickornas
            höjd — och lagringskortet ritas i båda lägena, så att raden är
            lika hög före som efter att `heroFigures` kommer.
        -->
        <div class="mt-2 hidden flex-wrap items-start gap-8 md:min-h-[102px] md:flex">
            <DashboardStats
                v-if="loaded"
                class="md:flex-1"
                :stats="heroStats"
                tone="on-image"
            />

            <HeroCosts
                v-if="loaded"
                :totals="heroCosts.totals"
                tone="on-image"
            />

            <!--
                Skelettet medan `heroFigures` hämtas. Måtten är den fyllda
                radens — två taltutor med den försenade underraden och
                summeringens rubrik, period och en rad — så att hjälten inte
                byter höjd när talen kommer. Raden är pynt och döljs för
                skärmläsare.
            -->
            <div v-if="!loaded" class="flex flex-wrap items-start gap-4 md:flex-1" aria-hidden="true">
                <div class="flex min-w-32 flex-col rounded-card border border-glass-border bg-glass px-4 py-3">
                    <span class="block text-heading font-semibold">&nbsp;</span>
                    <span class="block text-meta">&nbsp;</span>
                </div>

                <div class="flex flex-col gap-1">
                    <div class="flex min-w-32 flex-col rounded-card border border-glass-border bg-glass px-4 py-3">
                        <span class="block text-heading font-semibold">&nbsp;</span>
                        <span class="block text-meta">&nbsp;</span>
                    </div>

                    <p class="text-meta">&nbsp;</p>
                </div>
            </div>

            <div
                v-if="!loaded"
                class="min-w-32 rounded-card border border-glass-border bg-glass px-4 py-3"
                aria-hidden="true"
            >
                <h2 class="text-title font-semibold">&nbsp;</h2>
                <p class="text-meta">&nbsp;</p>

                <div class="mt-2 flex items-baseline justify-between gap-4">
                    <span class="block text-meta">&nbsp;</span>
                    <span class="block text-meta font-semibold">&nbsp;</span>
                </div>
            </div>

            <!--
                Lagringskortet står sist i raden (issue 476). Det ritas när
                den delade `storageQuota` finns — den kommer med sidan och
                inte med `heroFigures`, så kortet är på plats både före och
                efter att talen hämtas och hjälten byter därför aldrig höjd
                när de kommer.
            -->
            <HeroStorage
                v-if="storageQuota"
                tone="on-image"
            />
        </div>
    </HeroFrame>
</template>
