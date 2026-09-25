<?php

return [
    'task_due' => [
        'subject' => ':title is due on :date',
        'greeting' => 'Hello!',
        'line' => 'The task ":title" on ":item" in the container ":container" is due on :date.',
        'action' => 'Open task',
    ],
    'task_overdue' => [
        'subject' => ':title was due on :date',
        'greeting' => 'Hello!',
        'line' => 'The task ":title" on ":item" in the container ":container" was due on :date and is now overdue.',
        'action' => 'Open task',
    ],
    'loan_due' => [
        'subject' => ':item is due back :date',
        'greeting' => 'Hello!',
        'line' => 'The ":item" you lent to :borrower is due back :date.',
        'action' => 'Open loan',
    ],
    'quota_warning' => [
        'subject' => 'Your storage is :percent % full',
        'greeting' => 'Hello!',
        'line' => 'You have used :percent % of your storage space. When the quota is full you will not be able to upload more files.',
        'action' => 'View storage usage',
    ],
    'account_inactive' => [
        'subject' => 'Your account will close in :months months',
        'greeting' => 'Hello!',
        'line' => 'Your account has been inactive for :months months. If you do not log in before :close_at, the account will close and its data will be deleted.',
        'action' => 'Log in',
    ],
    'transfer_requested' => [
        'subject' => 'Someone wants to take over the container ":container"',
        'greeting' => 'Hello!',
        'line' => 'A transfer request is waiting for the container ":container". Sign in to see it under Transfers.',
        'action' => 'View transfer',
    ],
    'digest' => [
        'subject' => 'Your week in Mimers: :count reminders',
        'greeting' => 'Hello!',
        'intro' => 'Here is what is coming up.',
        'more' => 'And :count more.',
    ],
    'unsubscribe' => [
        'footer' => 'Do not want this kind of notification? :link',
        'link' => 'Unsubscribe',
        'confirm_heading' => 'Stop receiving :type?',
        'confirm_button' => 'Yes, turn off',
        'done' => 'You will not receive this kind of notification anymore. You can turn it back on under notification settings.',
        'types' => [
            'task_due' => 'task reminders',
            'task_overdue' => 'overdue task alerts',
            'loan_due' => 'loan reminders',
            'quota_warning' => 'storage quota warnings',
            'invitation_received' => 'container invitations',
            'transfer_requested' => 'ownership transfer requests',
            'account_inactive' => 'inactive account warnings',
        ],
    ],
    /*
     * Mejlen som skickas av en Notification-klass direkt och inte genom
     * leveransloopen (app/Notifications/). De står här och inte i en egen fil
     * därför att de är samma slags text som resten: serverrenderat innehåll på
     * mottagarens språk. Nycklarna delar inget med typerna ovan — mejlet till
     * en mottagare som ännu inte har ett konto kan inte gå genom en
     * leveransrad, eftersom raden kräver en `User`.
     */
    'magic_link' => [
        'subject' => 'Your login link',
        'line' => 'Click the link below to log in.',
        'action' => 'Log in',
        'expires' => 'The link stops working in :minutes minutes and can only be used once.',
    ],
    /*
     * Lösenordsbytet, se [[M20 Kontot]] § 129. Transaktionellt och skickat av
     * Notification-klassen själv, så det står här med de andra direkta
     * utskicken och inte bland typerna ovan: det finns ingen notisrad att
     * läsa och ingenting att avregistrera sig från — användaren gjorde det
     * här själv.
     *
     * `not_you` är raden som gör mejlet värt att skicka: ett lösenordsbyte
     * användaren inte har gjort är det tydligaste tecknet på ett kapat konto
     * ([[ADR-0043 Tre loggar]] § Säkerhetsloggen), och av de två halvorna —
     * loggen hos oss och det här hos henne — är det bara den här som når
     * fram.
     */
    'password_changed' => [
        'subject' => 'Your password has been changed',
        'line' => 'The password for your account has been changed. Every other signed-in browser and every API token has been signed out.',
        'not_you' => 'If this was not you, someone else has access to your account. Log in again, change the password, and look through your recent logins under Settings → Security.',
    ],

    /*
     * Adressbytet, se [[M20 Kontot]] § 130. Två mejl och två helt olika
     * uppdrag, så de delar bara namnutrymme och inga nycklar.
     *
     * `confirm` går till den NYA adressen och bär länken — den enda plats
     * klartext-tokenet finns. `requested` går till den GAMLA adressen och har
     * med flit ingen länk och ingen ny adress: den som läser den brevlådan
     * behöver inte veta vart kontot är på väg, bara att något är på väg att
     * hända. Båda är transaktionella utskick i samma form som
     * `password_changed` ovan och står därför här och inte bland typerna.
     */
    'email_change' => [
        'confirm' => [
            'subject' => 'Confirm your new email address',
            'line' => 'Click the link below to start using this address for your Mimers account. Until you do, nothing changes.',
            'action' => 'Confirm the address',
            'expires' => 'The link stops working in :minutes minutes and can only be used once.',
            'not_you' => 'If you did not ask for this, you can ignore this email. The link only works for the account that made the request.',
        ],
        'requested' => [
            'subject' => 'An email change has been requested',
            'line' => 'Someone asked to change the email address of your Mimers account. The new address has been sent a link, and the change only happens if that link is opened.',
            'not_you' => 'If this was not you, someone else has access to your account. Log in, change the password, and look through your recent logins under Settings → Security.',
        ],
    ],

    'invitation' => [
        'subject' => 'You have been invited to :container',
        'line' => 'You have been invited to share ":container".',
        'line_verify' => 'To get access you need to create an account with this email address and verify it — everyone who reads anything in the system must be identified.',
        'action' => 'Open the invitation',
        'expires' => 'The invitation expires in :days days.',
    ],
    'ownership_transfer' => [
        'subject' => 'Someone wants to take over the container ":container"',
        'line' => 'Someone wants to transfer the container ":container" to you.',
        'line_verify' => 'Create an account with this email address and verify it — everyone who reads anything in the system must be identified. Then log in and open the transfers tab to see the request.',
        'action' => 'View ownership transfer',
    ],

    'calendar' => [
        'name' => 'Maintenance: :container',
        'overdue_prefix' => 'Overdue: ',
    ],
];
