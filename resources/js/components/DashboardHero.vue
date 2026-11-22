<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppHero from './AppHero.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Dashboardens hjälte, se issue 435 · [[ADR-0090 Dashboardens hjälte]] § 1, 3
 * och 6 — sedan issue 437 ett tunt omslag runt den delade `AppHero`.
 *
 * **Formen bor i `AppHero`.** Ramen, textspalten, sifferraden och reglerna för
 * bilden är desamma på dashboarden och på de elva toppnivåsidorna, och de står
 * därför på ett ställe. Den här komponenten äger bara det som är dashboardens:
 * hälsningen, rubriken och undertiteln, som den formulerar ur `lang/` och
 * skickar in.
 *
 * **Siffrorna är dashboardens egna proppar.** `stats` och `costs` kommer ur
 * `DashboardController` — som sedan issue 437 hämtar dem ur
 * App\Actions\Dashboard\HeroFigures — och skickas rakt vidare. Hjälten räknar
 * ingenting och gör ingen begäran: den har talen redan.
 *
 * **Förnamnet är första ordet i `user.name`.** Strängarna
 * `dashboard.hero.greeting` och `dashboard.hero.subtitle` står i
 * `lang/en/ui.php` ([[ADR-0013 Språk och i18n]]): ingen mening formuleras i
 * den här filen.
 */
const props = defineProps({
    /* `{ containers, tasks, overdue }` — talen brickorna visar. */
    stats: { type: Object, required: true },
    /* Månadens kostnader, `{ totals, breakdown }`, som på sidan. */
    costs: { type: Object, required: true },
});

const page = usePage();
const { t } = useTranslations();

/* Första ordet i namnet — hälsningen säger *Welcome back, Anna*. */
const firstName = computed(() => (page.props.auth?.user?.name ?? '').trim().split(/\s+/)[0] ?? '');
</script>

<template>
    <AppHero
        :greeting="t('dashboard.hero.greeting', { name: firstName })"
        :heading="t('dashboard.heading')"
        :subtitle="t('dashboard.hero.subtitle')"
        :stats="props.stats"
        :costs="props.costs"
    />
</template>
