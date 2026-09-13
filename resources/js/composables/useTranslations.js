import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { translate } from '../i18n/translate.js';

/*
 * `t()` — komponenternas väg till texten, se issue 52 § Beslut 4.
 *
 *   const { t } = useTranslations()
 *   t('nav.dashboard')
 *   t('error.title', { status: 404 })   // :status byts ut
 *
 * Strängarna kommer ur den delade prop `translations`, som är
 * `lang/{locale}/ui.php` och ingenting annat — servern formulerar texten ur
 * `lang/`, klienten slår bara upp den ([[ADR-0021 Frontendteknik]] § Beslut:
 * "Webbens text formuleras på servern ur lang/. En katalog, inte två").
 *
 * Ingen egen katalog, ingen konstantmodul, ingen sträng i en komponent: en
 * text som ligger i en .vue-fil blir aldrig engelsk.
 */
export function useTranslations() {
    const translations = computed(() => usePage().props.translations ?? {});

    return {
        t: (key, params) => translate(translations.value, key, params),
    };
}
