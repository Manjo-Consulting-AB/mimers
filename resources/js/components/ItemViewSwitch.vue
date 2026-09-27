<script setup>
import { Link } from '@inertiajs/vue3';

/*
 * Växeln i containerns itemflik — se issue 154 ·
 * [[ADR-0046 Containerns karta]] § Beslut och [[M23 Mobilen och kartan]] § 154.
 *
 * **Läget är en adress och inget tillstånd.** Varje läge är en `<Link>`, och
 * den som är vald bär `aria-current`. Komponenten håller inget `ref` med
 * valet: en flik man kan länka till är en flik man kan dela, och ett val i
 * minnet försvinner vid en omladdning — eller står kvar och pekar fel när
 * webbläsaren går bakåt. Det är samma konstruktion som `?tab=` i
 * UiTabs.vue och `?path=` i issue 95.
 *
 * **`current` kommer från anroparen och läses inte ur adressen här.** Servern
 * har redan läst läget en gång — samma sträng avgör vilken yta sidan ritade —
 * och en andra läsning i klienten vore en andra regel som kan glida ifrån den
 * första. UiTabs löser samma sak genom att jämföra adresser, men den bär sex
 * flikar på samma sökväg och behöver rangordningen; här finns två lägen och
 * ett svar.
 *
 * **Ordningen är anroparens**, och den är bindande på ett sätt: det FÖRSTA
 * läget är förvalet, och dess adress är den utan `view` — samma val som
 * översiktsfliken gör med `tab` (issue 100 och 102). Komponenten skriver
 * ingen adress själv och vet inte vilka lägen som finns: *Karta* är § 157 och
 * dyker upp här först när den är byggd, utan att den här filen ändras.
 *
 * **Träffytan är `min-h-11`** — 44 px ur issue 68a § Beslut 3 — och raden
 * följer varandra i en grupp med ett gemensamt namn, så en skärmläsare säger
 * vad de två valen hör till. Rubriken kommer som prop och aldrig ur en sträng
 * i filen: texten formuleras på servern ([[ADR-0021 Frontendteknik]]).
 */
defineProps({
    /* Lägena i ritad ordning: `{ key, label, href }`. */
    views: { type: Array, required: true },
    /* Nyckeln för läget som gäller, ur serverns läsning av querysträngen. */
    current: { type: String, required: true },
    /* Gruppens tillgängliga namn, ur `lang/` hos anroparen. */
    label: { type: String, required: true },
});
</script>

<template>
    <ul
        role="group"
        :aria-label="label"
        class="inline-flex flex-wrap gap-1 rounded-control border border-border p-1"
    >
        <li v-for="view in views" :key="view.key">
            <Link
                :href="view.href"
                :aria-current="view.key === current ? 'true' : null"
                class="inline-flex min-h-11 items-center rounded-control px-4 text-body outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :class="view.key === current
                    ? 'bg-accent font-medium text-ink-on-accent'
                    : 'text-ink-muted hover:bg-surface-sunken'"
            >
                {{ view.label }}
            </Link>
        </li>
    </ul>
</template>
