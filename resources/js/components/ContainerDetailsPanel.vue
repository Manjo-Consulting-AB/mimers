<script setup>
import { computed } from 'vue';
import UiCard from './UiCard.vue';
import { useRelativeDate } from '../composables/useRelativeDate.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Containerdetaljer, se issue 172 · [[ADR-0050 Desktopdesignen]] § 7 och
 * docs/Design/container.jpeg (panelen *Containerdetaljer*).
 *
 * **Panelen är en definitionslista och ingenting mer.** Fyra fakta om
 * containern — art, valuta, ägarkonto och skapad — kommer färdiga i `details`
 * från App\Http\Controllers\ContainerController::show(). Ingen av dem räknas
 * fram här: valutan är containerns EGNA eller ärvda och avgörs av
 * App\Models\Container::effectiveCurrency(), och ägarkontot är det konto som
 * äger containern ([[ADR-0002 Konto äger container]]).
 *
 * **Arten skrivs ut ORDAGRANT.** Fältet är fritt ([[ADR-0036 Containerns art]]),
 * så ingen översättningsnyckel byggs ur värdet — `t()` skriver nyckeln själv
 * när uppslaget misslyckas, och en nyckel byggd ur strängen hade skrivit
 * `container.overview.details.kind.Segelbåt` på skärmen första gången någon
 * skrev en egen art. Är fältet tomt utelämnas raden: en tom etikett vore ett
 * påstående om att något saknas, precis som i sidans eget huvud.
 *
 * **Datumet följer datumregeln** (issue 104): `eventDate()` ur
 * resources/js/composables/useRelativeDate.js — skapandet är en tidsstämpel
 * och ett minne, som `created_at` på en loggrad — och den här filen
 * formaterar ingenting själv. `<time datetime>` bär tidsstämpeln maskinläsbart
 * jämte det lästa datumet.
 *
 * **Ingen sträng står i filen** (issue 52 · [[ADR-0013 Språk och i18n]]).
 */
const props = defineProps({
    /*
     * `{ kind, currency, account, created_at }` — artens sträng eller null,
     * ISO 4217-koden, ägarkontots namn och en ISO-tidsstämpel.
     */
    details: { type: Object, required: true },
});

const { t } = useTranslations();
const { eventDate } = useRelativeDate();

const created = computed(() => eventDate(props.details.created_at));
</script>

<template>
    <UiCard>
        <template #heading>{{ t('container.overview.details') }}</template>

        <dl class="flex flex-col gap-2">
            <!-- Arten är ett FRITT fält: strängen skrivs ut som den är, och
                 raden ritas bara när fältet är satt. -->
            <div v-if="props.details.kind" class="flex flex-wrap gap-x-2">
                <dt class="font-medium text-ink">{{ t('container.overview.kind') }}</dt>
                <dd class="text-slate-700">{{ props.details.kind }}</dd>
            </div>

            <div class="flex flex-wrap gap-x-2">
                <dt class="font-medium text-ink">{{ t('container.overview.currency') }}</dt>
                <dd class="text-slate-700">{{ props.details.currency }}</dd>
            </div>

            <div class="flex flex-wrap gap-x-2">
                <dt class="font-medium text-ink">{{ t('container.overview.account') }}</dt>
                <dd class="text-slate-700">{{ props.details.account }}</dd>
            </div>

            <div class="flex flex-wrap gap-x-2">
                <dt class="font-medium text-ink">{{ t('container.overview.created') }}</dt>
                <dd class="text-slate-700">
                    <time :datetime="props.details.created_at">{{ created.text }}</time>
                </dd>
            </div>
        </dl>
    </UiCard>
</template>
