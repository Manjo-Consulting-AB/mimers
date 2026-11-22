<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import CostDonut from './CostDonut.vue';
import DashboardStats from './DashboardStats.vue';
import HeroFrame from './HeroFrame.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Dashboardens hjälte, se issue 435 · [[ADR-0090 Dashboardens hjälte]] § 1, 3
 * och 6.
 *
 * **Den översta raden ritas i en bild, som containerns.** Hälsningen,
 * rubriken, undertiteln, de tre brickorna och kostnadsdonuten står inne i
 * `HeroFrame` över `md:` — samma ram, samma toning och samma textspalt som
 * `ContainerHero` ritar (ADR-0090 § 1 och § Konsekvenser). Den här
 * komponenten äger bara innehållet; ramen äger bilden.
 *
 * **Bilden är personens egen, eller standardbilden.** `hero` kommer ur den
 * delade proppen `page.props.auth.user` (App\Http\Resources\AuthUserResource,
 * issue 434) och bär bilagans `ulid`, fokuspunkt och zoom — fokuspunkt och
 * zoom ritas av `ContainerCover` i ramen, samma `coverStyle` som
 * förhandsvisningen på `/settings/profile`. Utan `hero` ritas
 * `/images/dashboard-hero.jpg` centrerad: den inbyggda bilden ur ADR-0090
 * § 3 är ingen bilaga och har därför inget utsnitt.
 *
 * **Brickorna och donuten är desamma som i dag.** Propparna är sidans egna —
 * `stats` och `costs` — och ingenting räknas om här (ADR-0090 § 1). Brickorna
 * ritas i formen `on-image`, som containerns tal, och donutens tal och
 * förklaring ritas vita mot toningen. Saknas kostnader ritas ingen donut och
 * brickorna fyller raden, som i dag. Länkarna är anroparens, som förut:
 * containerbrickan till `/containers`, uppgiftsbrickan till `/tasks` och
 * donutens bitar till containerns översikt.
 *
 * **Under `md:` är hjälten ett lågt band** (ADR-0090 § 6): hälsningen och
 * rubriken står kvar inne i bilden, undertiteln och sifferraden är dolda, och
 * `Dashboard.vue` ritar brickorna och donuten under bandet. Därför är
 * sifferraden här `hidden md:flex` och ramens höjd `min-h-32 md:min-h-56` —
 * samma bild, två höjder, och ingen andra kopia av hälsningen.
 *
 * **Förnamnet är första ordet i `user.name`.** Strängarna
 * `dashboard.hero.greeting` och `dashboard.hero.subtitle` står i
 * `lang/en/ui.php` ([[ADR-0013 Språk och i18n]]): ingen mening formuleras i
 * den här filen.
 */
const props = defineProps({
    /* `{ containers, tasks, overdue }` — talen brickorna visar. */
    stats: { type: Object, required: true },
    /* Månadens kostnader: `{ totals: [...], breakdown: [...] }`, som på sidan. */
    costs: { type: Object, required: true },
});

const page = usePage();
const { t } = useTranslations();

/* Personens hjältebild, ur den delade proppen (issue 434). */
const hero = computed(() => page.props.auth?.user?.hero ?? null);

/* Första ordet i namnet — hälsningen säger *Welcome back, Anna*. */
const firstName = computed(() => (page.props.auth?.user?.name ?? '').trim().split(/\s+/)[0] ?? '');
</script>

<template>
    <!--
        `min-h-32` under `md:` gör hjälten till ett lågt band med hälsningen
        och rubriken; `md:min-h-56` ger den översta raden plats för brickorna
        och donuten. Under `md:` är sifferraden dold och `Dashboard.vue` ritar
        den under bandet (ADR-0090 § 6).
    -->
    <HeroFrame
        :cover="hero"
        src="/images/dashboard-hero.jpg"
        class="min-h-32 md:min-h-56"
    >
        <div class="min-w-0 max-w-lg">
            <p class="text-meta">
                {{ t('dashboard.hero.greeting', { name: firstName }) }}
            </p>

            <h1 class="text-heading font-semibold">{{ t('dashboard.heading') }}</h1>

            <!-- Undertiteln hör till den breda formen; bandet bär bara
                 hälsningen och rubriken (ADR-0090 § 6). -->
            <p class="mt-1 hidden text-body md:block">
                {{ t('dashboard.hero.subtitle') }}
            </p>
        </div>

        <!--
            Sifferraden: brickorna till vänster och kostnadskortet med donuten
            till höger, som i dag. Finns inga kostnader ritas inget kort, och
            brickorna fyller raden — de är då radens enda barn.
        -->
        <div class="mt-2 hidden flex-wrap items-start gap-8 md:flex">
            <DashboardStats
                class="md:flex-1"
                :stats="props.stats"
                :costs="props.costs.totals"
                tone="on-image"
            />

            <section v-if="props.costs.totals.length" class="md:flex-1">
                <h2 class="text-title font-semibold text-ink-on-accent">
                    {{ t('dashboard.costs.heading') }}
                </h2>

                <div class="mt-3">
                    <CostDonut
                        :totals="props.costs.totals"
                        :breakdown="props.costs.breakdown"
                        :slice-href="(ulid) => `/containers/${ulid}`"
                        tone="on-image"
                    />
                </div>
            </section>
        </div>
    </HeroFrame>
</template>
