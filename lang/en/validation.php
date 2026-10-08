<?php

/*
 * Egna valideringsmeddelanden. Filen fyller PÅ Laravels egen
 * lang/en/validation.php (ramverkets fil ligger kvar under vendor/ och
 * läses först — appens fil ersätter bara de nycklar den anger), så
 * `validation.required` och de andra inbyggda meddelandena är orörda.
 *
 * `redeemable_voucher` hör till App\Rules\RedeemableVoucher och används av
 * ADR-0055 § 8: en okänd, återkallad, utgången eller förbrukad kod ger
 * samma svar, så det inte går att pröva sig fram till vilka koder som
 * finns.
 */

return [
    'redeemable_voucher' => 'This invite code is not valid.',
];
