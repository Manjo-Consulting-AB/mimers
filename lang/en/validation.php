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

    // App\Rules\IsoCurrency, se [[ADR-0078 Valutor ur ISO 4217]] § 1. Bara en
    // aktiv ISO 4217-kod godtas; meningen säger vad användaren ska göra, inte
    // vilka koder som finns. Nyckeln är regelns felkod på webben — i API:et
    // heter den `currency.invalid` (App\Support\Api\ValidationErrorMapper).
    'iso_currency' => 'Choose a currency from the list.',

    // App\Http\Requests\Container\UpdateContainerVersioningRequest, se issue
    // 411 · [[ADR-0088 Versionshistorik för items]] Riktning 1. Containerns
    // tak får aldrig ligga över kontots. Nyckeln är felkoden: API-höljet
    // skulle svara `versioning.above_account` om rutten låg på `/api`, och
    // webben visar samma mening ur den här filen.
    'versioning' => [
        'above_account' => 'The container cannot keep more versions than the account allows.',
    ],

    // App\Actions\Field\FieldAppliesToItem, se issue 418 och 419 ·
    // [[ADR-0089 Egna fält på items]] § Riktning 9. Ett värde får bara
    // skrivas i ett fält som hör till itemet — containerns fält eller
    // itemets egna. Nyckeln är felkoden: `/api` svarar `field.not_applicable`
    // (App\Support\Api\ValidationErrorMapper) och webben visar samma mening ur
    // den här filen. Itemets formulär ritar den vid raden felet kom på
    // (`fields.N.field`).
    'field' => [
        'not_applicable' => 'That field does not belong to this item.',
    ],
];
