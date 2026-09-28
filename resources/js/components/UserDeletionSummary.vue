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
 * **Ordet.** Kontot heter *account* i gränssnittet och är något annat än
 * personen: ett konto där någon annan är medlem lämnas, och bara
 * medlemskapet försvinner. Rubrikerna säger därför vad som faktiskt händer
 * med kontona, och copyn säger aldrig *Delete account*.
 */
const props = defineProps({
    accountsToDelete: { type: Array, required: true },
    accountsToLeave: { type: Array, required: true },
    blockers: { type: Array, required: true },
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

    <!-- Spärren står i vyn och läses av skärmläsaren innan knappen klickas —
         inte i en confirm()-dialog, som varken går att översätta eller att
         testa. Sidan som använder komponenten fyller `#blocked` med sin
         rubrik och sin mening. -->
    <div v-if="props.blockers.length > 0" class="flex flex-col gap-2 rounded border border-amber-300 bg-amber-50 p-4">
        <slot name="blocked" />

        <ul class="flex flex-col gap-1 text-sm text-amber-900">
            <li v-for="(blocker, index) in props.blockers" :key="`${blocker.code}-${index}`">
                {{ blockerSentence(blocker) }}
            </li>
        </ul>
    </div>
</template>
