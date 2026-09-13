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

        'code' => [
            'label' => 'One-time code or recovery code',
        ],

        'register' => [
            'title' => 'Create an account',
            'heading' => 'Create an account',
            'submit' => 'Create an account',
            'link' => 'Create an account',
            'password_hint' => 'At least eight characters.',
            'login' => 'Already have an account? Log in',
        ],

        'magic_link' => [
            'title' => 'Log in with a link',
            'heading' => 'Log in with a link',
            'link' => 'Log in with a link',
            'intro' => 'We will send a login link to your email address. The link can be used once and is valid for fifteen minutes.',
            'submit' => 'Send the link',
            'login' => 'Back to the login',
        ],

        'verify' => [
            'title' => 'Verify your email address',
            'heading' => 'Verify your email address',
            'banner' => 'Your email address is not verified yet.',
            'body' => 'We will send an email with a verification link to your address. Click the link in the email to confirm it.',
            'send' => 'Send the verification email',
        ],

        'logout' => 'Log out',
    ],

    'form' => [
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
    ],

    'flash' => [
        'verification-link-sent' => 'A new verification email has been sent.',
        'magic-link-sent' => 'If the address exists with us, we have sent a link to it.',
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
