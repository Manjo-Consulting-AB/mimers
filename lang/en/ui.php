<?php

/*
 * The interface text, se issue 52 § Beslut 4 och 5. Samma nycklar som
 * lang/sv/ui.php — en nyckel utan engelskt värde är en bugg, inte en
 * "tills vidare"-platshållare (Beslut 6).
 */
return [
    'common' => [
        'brand' => 'Mimers',
        'tagline' => 'The binder for the boat, the caravan, the house and the car.',
        'to_dashboard' => 'Go to the dashboard',
        'home' => 'Back to the start page',
    ],

    'nav' => [
        'dashboard' => 'Dashboard',
        'login' => 'Log in',
    ],

    'auth' => [
        'login' => [
            'title' => 'Log in',
            'heading' => 'Log in',
            'submit' => 'Log in',
        ],
    ],

    'form' => [
        'email' => 'Email',
        'password' => 'Password',
    ],

    'flash' => [
        'verification-link-sent' => 'A new verification email has been sent.',
        'magic-link-sent' => 'We have sent a login link to your email.',
        'totp-confirmed' => 'Two-factor authentication is on.',
        'totp-disabled' => 'Two-factor authentication is off.',
        'session-expired' => 'Your session expired. Please try again.',
    ],

    'error' => [
        'title' => 'Error :status',
        '403' => 'You do not have access to this page.',
        '404' => 'This page does not exist.',
        '429' => 'Too many attempts. Wait a moment and try again.',
        '500' => 'Something went wrong on our side. Try again in a moment.',
    ],
];
