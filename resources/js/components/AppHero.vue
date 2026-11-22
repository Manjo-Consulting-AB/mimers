<script setup>
import { computed, onMounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import CostDonut from './CostDonut.vue';
import DashboardStats from './DashboardStats.vue';
import HeroFrame from './HeroFrame.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Den delade hjälten, se issue 437 · [[ADR-0090 Dashboardens hjälte]]
 * § Tillägg 2026-11-22. Dashboarden och elva toppnivåsidor ritar samma huvud:
 * bilden, textspalten och sifferraden. Bara rubriken och undertiteln skiljer
 * sidorna åt.
 *
 * **Ramen, textspalten och sifferraden ritas exakt som `DashboardHero` gjorde
 * före utbrytningen** (ADR-0090 § 1 och 6): `HeroFrame` med
 * `min-h-32 md:min-h-56`, hälsningen, `<h1>` och undertiteln i samma spalt,
 * och brickorna före donuten i samma rad. Den som ändrar det ena ser det
 * andra — därför en komponent och inte tolv kopior.
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
 * **Sifferraden har ett fast golv: kostnadskortets höjd.** Ett konto utan
 * kostnadsrader ritar inget kort, och då består raden bara av brickorna.
 * Utan ett golv hade ramen krympt när `heroFigures` kom — skelettet
 * reserverar donuten, men den fyllda raden hade inte gjort det. Klassen
 * `md:min-h-[194.5px]` är kortets egen höjd: rubriken (15 px × 1,5 =
 * 22,5 px) + `mt-3` (12 px) + ringen (`h-40`, 160 px). Raden är därför lika
 * hög fylld som tom, och en dashboard utan kostnader ritar samma hjälte som
 * en med. Ändras `text-title` eller ringens mått ändras talet med dem — det
 * är priset för att höjden ska stå stilla.
 *
 * **Under `md:` är hjälten det låga bandet** (ADR-0090 § 6): hälsningen och
 * rubriken står kvar inne i bilden, undertiteln och sifferraden är dolda. Bara
 * `Dashboard.vue` ritar brickorna och donuten under bandet; de andra sidorna
 * lämnar telefonens listor i fred.
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
    /* Månadens kostnader, `{ totals, breakdown }` — dashboardens egen propp. */
    costs: { type: Object, default: null },
});

const page = usePage();
const { t } = useTranslations();

/* Personens hjältebild, ur den delade proppen (issue 434). */
const hero = computed(() => page.props.auth?.user?.hero ?? null);

/* Den valfria proppen, när sidan inte har egna tal (issue 437). */
const figures = computed(() => page.props.heroFigures ?? null);

/* Sidans egna tal först — dashboarden gör därför ingen begäran. */
const heroStats = computed(() => props.stats ?? figures.value?.stats ?? null);
const heroCosts = computed(() => props.costs ?? figures.value?.costs ?? null);
const loaded = computed(() => heroStats.value !== null && heroCosts.value !== null);

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
            Sifferraden: brickorna till vänster och kostnadskortet med donuten
            till höger, som på dashboarden. Finns inga kostnader ritas inget
            kort, och brickorna fyller raden — de är då radens enda barn.
            `md:min-h-[194.5px]` håller raden på kostnadskortets höjd också
            när kortet saknas, så att ramen inte krymper när talen kommer.
        -->
        <div class="mt-2 hidden flex-wrap items-start gap-8 md:min-h-[194.5px] md:flex">
            <DashboardStats
                v-if="loaded"
                class="md:flex-1"
                :stats="heroStats"
                :costs="heroCosts.totals"
                tone="on-image"
            />

            <section v-if="loaded && heroCosts.totals.length" class="md:flex-1">
                <h2 class="text-title font-semibold text-ink-on-accent">
                    {{ t('dashboard.costs.heading') }}
                </h2>

                <div class="mt-3">
                    <CostDonut
                        :totals="heroCosts.totals"
                        :breakdown="heroCosts.breakdown"
                        :slice-href="(ulid) => `/containers/${ulid}`"
                        tone="on-image"
                    />
                </div>
            </section>

            <!--
                Skelettet medan `heroFigures` hämtas. Måtten är den fyllda
                radens — tre taltutor, den försenade underraden och en ring på
                160 px — så att hjälten inte byter höjd när talen kommer.
                Raden är pynt och döljs för skärmläsare.
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

                <div class="flex flex-col gap-1">
                    <div class="flex min-w-32 flex-col rounded-card border border-glass-border bg-glass px-4 py-3">
                        <span class="block text-heading font-semibold">&nbsp;</span>
                        <span class="block text-meta">&nbsp;</span>
                    </div>

                    <p class="text-meta">&nbsp;</p>
                </div>
            </div>

            <section v-if="!loaded" class="md:flex-1" aria-hidden="true">
                <h2 class="text-title font-semibold text-ink-on-accent">
                    {{ t('dashboard.costs.heading') }}
                </h2>

                <div class="mt-3">
                    <div class="h-40 w-40 rounded-pill border border-glass-border bg-glass"></div>
                </div>
            </section>
        </div>
    </HeroFrame>
</template>
