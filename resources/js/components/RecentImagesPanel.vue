<script setup>
import { Link } from '@inertiajs/vue3';
import UiCard from './UiCard.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Panelen *Senaste bilder* på containerns översikt, se issue 173 ·
 * [[ADR-0050 Desktopdesignen]] § 7 och docs/Design/container.jpeg.
 *
 * **Panelen ställer ingen fråga.** Raderna kommer färdiga i `images`, ur
 * App\Actions\Attachment\ListRecentImages — samma urval, samma ordning och
 * samma omfång som servern räknade. Den här filen sorterar inte om, klipper
 * inte och filtrerar inte på nytt: en panel med en egen fråga hade varit den
 * andra sanningen om vilka bilder användaren når, och den hade glidit isär
 * från listan ([[ADR-0024 Tunna controllers och actions]]).
 *
 * **Miniatyren ritas bara när `hasThumb` är sann.** Servern har prövat att
 * `thumb`-varianten finns, och `?variant=thumb` mot en bilaga utan derivat
 * svarar 404 (issue 61b § Beslut 1) — en `<img>` mot en sådan adress är en
 * trasig bild. Utan derivat ritas den neutrala filikonen i stället, samma
 * yta och samma ord som i ItemAttachmentSection: en nyss uppladdad bild är
 * ingen trasig bild.
 *
 * **Varje bild är en länk till SITT item** och ingenting annat: panelen leder
 * inte till filen, och den öppnar ingen bildvisare. Att klicka på ett foto i
 * containern är att vilja se var det hör hemma, och den ytan är itemets egen
 * ([[ADR-0041 Itemets vy]]). Det är också därför klicket inte skriver någon
 * öppningsrad: en miniatyr i en lista är ingen öppning (issue 177).
 *
 * **Panelen har inget tomt läge.** Den ritas bara när listan har något i sig —
 * översikten avgör det ur `recentImages.length` — så en rubrik över en tom
 * ruta finns inte. Samma regel som kostnadspanelen, och samma skäl: en rubrik
 * över ingenting påstår att det finns något att visa.
 *
 * **Ingen "Visa alla"-länk.** Mockupen ritar en, men målet hade varit
 * dokumentfliken (issue 178), som inte finns ännu. Uppgiftspanelen länkade
 * till `/tasks` för att den listan fanns; här finns ingen motsvarande sida,
 * och en länk till en flik som inte finns är en återvändsgränd.
 *
 * **Ingen sträng står i filen** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * rubriken kommer ur `container.overview.images`, och filikonens namn ur
 * itemets egen nyckel — samma ord om samma yta.
 */
const props = defineProps({
    /* Högst fem bilder användaren når, nyast först (issue 173). */
    images: { type: Array, required: true },
    /*
     * Containerns ULID: länken till ett item behöver BÅDA ULID:na, och
     * containern är given här — panelen står på dess sida.
     */
    containerUlid: { type: String, required: true },
});

const { t } = useTranslations();

const itemUrl = (image) => `/containers/${props.containerUlid}/items/${image.item.ulid}`;

const thumbUrl = (image) => `/files/${image.ulid}?variant=thumb`;
</script>

<template>
    <UiCard>
        <template #heading>{{ t('container.overview.images') }}</template>

        <!--
            Bilden och de fyra små, som docs/Design/container.jpeg ritar dem:
            den första över båda kolumnerna, resten i ett 2×2-rutnät. Antalet
            är serverns (`IMAGE_LIMIT`), och panelen ritar precis de rader den
            fick — den fyller ingen tom ruta med en platshållare.
        -->
        <ul class="grid grid-cols-2 gap-2">
            <li
                v-for="(image, index) in props.images"
                :key="image.ulid"
                :class="index === 0 ? 'col-span-2' : ''"
            >
                <Link :href="itemUrl(image)" class="flex min-h-11 w-full items-center justify-center">
                    <img
                        v-if="image.hasThumb"
                        :src="thumbUrl(image)"
                        :alt="image.filename"
                        class="w-full rounded border border-slate-200 object-cover"
                        :class="index === 0 ? 'h-40' : 'h-24'"
                    >

                    <!--
                        Den neutrala ytan (issue 61b § Beslut 1): en bilaga
                        utan `thumb` — nyss uppladdad — ritas som en filikon
                        och ALDRIG som en trasig bild. Samma markup och samma
                        nyckel som ItemAttachmentSection.
                    -->
                    <span
                        v-else
                        role="img"
                        :aria-label="t('item.attachment.file_icon')"
                        class="flex h-24 w-full items-center justify-center rounded border border-slate-300 bg-slate-50 text-slate-600"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="h-8 w-8"
                            aria-hidden="true"
                        >
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                            <path d="M14 2v6h6" />
                        </svg>
                    </span>
                </Link>
            </li>
        </ul>
    </UiCard>
</template>
