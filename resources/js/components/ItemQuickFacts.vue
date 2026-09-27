<script setup>
import { computed } from 'vue';
import { itemQuickFacts } from './itemPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Snabbfakta på itemets översikt — se issue 154 ·
 * [[M23 Mobilen och kartan]] § 154 och [[ADR-0041 Itemets vy]] § Beslut.
 *
 * **Urvalet bor i modulen och inte här.** `itemQuickFacts()` i
 * itemPresentation.js väljer de tre fälten och utelämnar dem som saknar
 * värde; den här filen slår upp etiketten och ritar raden. Regeln går att
 * köra i node och en mall gör det inte — samma skäl som `itemFields` ligger
 * där, och samma skäl att den inte skrivs om här: två avskrifter av "vilka
 * fält är snabbfakta" glider isär vid första ändringen.
 *
 * **Rubriken ritas bara när något står under den.** Ett item utan tillverkare,
 * modell och serienummer har inga snabbfakta, och en rubrik över en tom lista
 * hade sagt att något fattas — vilket är fel: fälten är frivilliga, och de
 * fylla i dem bor på informationsfliken bakom *Visa alla fält*
 * ([[ADR-0041 Itemets vy]] § Beslut: ett tomt fält utelämnas, aldrig
 * påhittat).
 *
 * **Etiketten är densamma som på informationsfliken** (`item.show.<key>`):
 * samma fält bär samma ord på båda ytorna, och en egen nyckel per yta hade
 * varit två sanningar om vad kolumnen heter.
 *
 * `locale` kommer som prop och läses inte ur sidan här: översikten har redan
 * räknat ut den, och ett datumformat är det enda språkberoendet i raden.
 */
const props = defineProps({
    /* Itemet ur detaljvyns props, ur App\Http\Resources\ItemResource. */
    item: { type: Object, required: true },
    /* Användarens språk, ur den delade `locale`-proppen. */
    locale: { type: String, required: true },
});

const { t } = useTranslations();

const facts = computed(() =>
    itemQuickFacts(props.item, props.locale).map((fact) => ({
        ...fact,
        label: t(`item.show.${fact.key}`),
    })),
);
</script>

<template>
    <section v-if="facts.length > 0">
        <h2 class="text-sm font-medium text-slate-600">{{ t('item.show.quick_facts') }}</h2>

        <dl class="mt-2 flex flex-col gap-2">
            <div v-for="fact in facts" :key="fact.key">
                <dt class="text-sm text-slate-600">{{ fact.label }}</dt>
                <dd class="text-slate-900">{{ fact.value }}</dd>
            </div>
        </dl>
    </section>
</template>
