<script setup>
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Underlaget för en personradering, se [[M22 Redo för testare]] § 145 och
 * [[ADR-0045 Radering av konto och person]] § Beslut 3 och § Uppföljning
 * 2026-09-28.
 *
 * **Två ytor visar samma sak, och därför ligger listorna här.** Formuläret på
 * säkerhetssidan (resources/js/components/UserDeletionForm.vue) och
 * bekräftelsesidan bakom mejlets länk
 * (resources/js/pages/Settings/ConfirmUserDeletion.vue) visar båda vilka
 * konton som raderas, vilka som lämnas, och vad som i så fall spärrar. En
 * avskrift i den ena hade varit en andra sanning om vad raderingen gör.
 *
 * **Uppdelningen räknas av servern och inte här.** `accountsToDelete` och
 * `accountsToLeave` kommer ur App\Actions\User\DeleteUser, samma uppdelning
 * som raderingen själv gör. Vyn avgör alltså inte vilka konton som försvinner
 * — den visar vad servern säger.
 *
 * **Spärrarna kommer som koder och blir meningar här.** Koden är
 * App\Support\User\DeletionBlocker:s och stavas aldrig i den här filen:
 * meningen väljs på kodens SISTA led (`sole_owner`, `shared_container`,
 * `legal_hold`), och ui.php:s nycklar är döpta efter just det ledet — samma
 * namn som `reason` bär i livscykelns logg. `legal_hold` bär ingen data, så
 * `account` är null och `containers` tom; meningen nämner ingendera, och det
 * är avsiktligt: att ett konto är spärrat är i sig en uppgift om en pågående
 * utredning.
 *
 * **Rubriken och inledningen till spärren är sidans, inte komponentens.**
 * Formuläret och bekräftelsesidan är olika platser med olika saker att säga:
 * den ena kan inte ta EMOT en begäran, den andra kan inte GENOMFÖRA en
 * radering. Därför en slot — `#blocked` — medan listan över spärrar, som är
 * densamma, ritas här. `UserDeletionForm` och `ConfirmUserDeletion` fyller
 * den med var sin mening.
 *
 * **Spärrar och upplysningar är två rutor, inte en.** En spärr säger att
 * raderingen inte går att göra; en upplysning säger att den går men att den
 * får konsekvenser ([[ADR-0045 Radering av konto och person]] § Uppföljning
 * 2026-11-14). De ritas därför aldrig i samma ruta, och upplysningens rubrik
 * och mening är komponentens egna — de är desamma på båda ytorna, till
 * skillnad från spärrens.
 *
 * **Ordet.** Kontot heter *account* i gränssnittet och är något annat än
 * personen: ett konto där någon annan är medlem lämnas, och bara
 * medlemskapet försvinner. Rubrikerna säger därför vad som faktiskt händer
 * med kontona, och copyn säger aldrig *Delete account*.
 */
const props = defineProps({
    accountsToDelete: { type: Array, required: true },
    accountsToLeave: { type: Array, required: true },
    blockers: { type: Array, required: true },
    warnings: { type: Array, required: true },
});

const { t } = useTranslations();

/*
 * Meningen för en spärr. Koden delas på sin sista punkt — se docblocket
 * ovanför: nycklarna i ui.php är döpta efter det ledet, och koden själv
 * upprepas aldrig här.
 */
function blockerSentence(blocker) {
    return t(`settings.security.deletion.blocker.${blocker.code.split('.').pop()}`, {
        account: blocker.account,
        containers: blocker.containers.join(', '),
    });
}

/*
 * Meningen för en upplysning, ur samma namnform som spärrens: kodens sista
 * led är nyckelns sista led. Medlemmarnas namn fyller `:members` — det är
 * personerna som förlorar åtkomsten, inte bara containrarna.
 *
 * **En tom medlemslista har en egen mening.** `members` är tom när containern
 * bara har en obesvarad inbjudan: räkningen ser inbjudan som en aktiv medlem,
 * men mottagaren har inget konto att hämta ett namn ur. Meningen med
 * `:members` hade då slutat i "active members: ." — i stället väljs
 * `shared_container_invited`, som nämner containrarna och inbjudningarna.
 */
function warningSentence(warning) {
    const sort = warning.code.split('.').pop() + (warning.members.length === 0 ? '_invited' : '');

    return t(`settings.security.deletion.warning.${sort}`, {
        account: warning.account,
        containers: warning.containers.join(', '),
        members: warning.members.join(', '),
    });
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <h3 class="font-medium">{{ t('settings.security.deletion.accounts_deleted_heading') }}</h3>

        <!-- En <ul> och ingen tabell: listan är namn att läsa, inte värden att
             jämföra, och en skärmläsare ska höra hur många konton det gäller
             innan den läser det första. -->
        <ul v-if="props.accountsToDelete.length > 0" class="flex flex-col gap-1 text-sm text-slate-700">
            <li v-for="account in props.accountsToDelete" :key="account.ulid">{{ account.name }}</li>
        </ul>

        <p v-else class="text-sm text-slate-700">{{ t('settings.security.deletion.accounts_none') }}</p>
    </div>

    <div class="flex flex-col gap-1">
        <h3 class="font-medium">{{ t('settings.security.deletion.accounts_left_heading') }}</h3>

        <ul v-if="props.accountsToLeave.length > 0" class="flex flex-col gap-1 text-sm text-slate-700">
            <li v-for="account in props.accountsToLeave" :key="account.ulid">{{ account.name }}</li>
        </ul>

        <p v-else class="text-sm text-slate-700">{{ t('settings.security.deletion.accounts_none') }}</p>
    </div>

    <!-- Upplysningen har sin egen ruta och sina egna ord: den säger inte att
         raderingen är stoppad, bara vad den får för konsekvenser. Rutan läses
         av skärmläsaren innan kryssrutan och knappen. -->
    <div v-if="props.warnings.length > 0" class="flex flex-col gap-2 rounded border border-amber-300 bg-amber-50 p-4">
        <h3 class="font-medium text-amber-900">{{ t('settings.security.deletion.warnings_heading') }}</h3>

        <ul class="flex flex-col gap-1 text-sm text-amber-900">
            <li v-for="(warning, index) in props.warnings" :key="`${warning.code}-${index}`">
                {{ warningSentence(warning) }}
            </li>
        </ul>
    </div>

    <!-- Spärren står i vyn och läses av skärmläsaren innan knappen klickas —
         inte i en confirm()-dialog, som varken går att översätta eller att
         testa. Sidan som använder komponenten fyller `#blocked` med sin
         rubrik och sin mening. Det är en EGEN ruta, skild från upplysningen
         ovan: en spärr är inte samma sak som en varning. -->
    <div v-if="props.blockers.length > 0" class="flex flex-col gap-2 rounded border border-amber-300 bg-amber-50 p-4">
        <slot name="blocked" />

        <ul class="flex flex-col gap-1 text-sm text-amber-900">
            <li v-for="(blocker, index) in props.blockers" :key="`${blocker.code}-${index}`">
                {{ blockerSentence(blocker) }}
            </li>
        </ul>
    </div>
</template>
