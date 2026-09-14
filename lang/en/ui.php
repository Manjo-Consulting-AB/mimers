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
        'containers' => 'Binders',
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
        'profile-updated' => 'Your profile has been saved.',
        'account-updated' => 'The account details have been saved.',
        'container-created' => 'The binder has been created.',
        'container-updated' => 'The binder has been saved.',
        'access-updated' => 'The access has been saved.',
        'access-revoked' => 'The access has been revoked.',
        'invitation-sent' => 'The invitation has been sent.',
        'invitation-revoked' => 'The invitation has been withdrawn.',
        'invitation-accepted' => 'The invitation has been accepted. The binder is under Binders.',
        'invitation-rejected' => 'The invitation has been declined.',
        'session-expired' => 'Your session expired. Please try again.',
    ],

    'error' => [
        'title' => 'Error :status',
        '403' => 'You do not have access to this page.',
        '404' => 'This page does not exist.',
        '429' => 'Too many attempts. Wait a moment and try again.',
        '500' => 'Something went wrong on our side. Try again in a moment.',

        'generic' => 'Something went wrong. Try again in a moment.',

        'quota' => [
            'containers_exceeded' => 'The account has reached its limit for the number of binders (:used of :limit).',
            // Issue 55b decision 6: the two limits an invitation form can hit.
            'shared_users_exceeded' => 'The sharing has reached the account limit (:used of :limit).',
            'pending_invitations_exceeded' => 'The account has reached its limit for outstanding invitations (:used of :limit).',
        ],

        // Issue 55a decision 9: PATCH on a revoked or expired row answers
        // `container_access.revoked` on /api and this sentence in the web.
        'container_access' => [
            'revoked' => 'The access has been revoked or has expired and cannot be changed.',
        ],

        // Issue 55b: the invitation error codes. Never a raw JSON body in a
        // browser, same rule as issue 54 decision 4.
        'invitation' => [
            'already_pending' => 'This address already has an invitation waiting for an answer.',
            'not_pending' => 'The invitation has already been answered and cannot be withdrawn.',
            'expired' => 'The invitation has expired.',
            'email_mismatch' => 'The invitation is for a different email address than the one you are signed in with.',
            'email_not_verified' => 'Verify your email address first, then try again.',
        ],
    ],

    'settings' => [
        'title' => 'Settings',

        'nav' => [
            'profile' => 'Profile',
            'accounts' => 'Accounts',
            'security' => 'Security',
        ],

        'locales' => [
            'sv_SE' => 'Swedish',
            'en_GB' => 'English',
        ],

        'units' => [
            'metric' => 'Metric',
            'imperial' => 'Imperial',
        ],

        'profile' => [
            'title' => 'Profile',
            'heading' => 'Profile',

            'name' => 'Name',

            'email' => 'Email',
            'email_verified' => 'The address is verified.',
            'email_unverified' => 'The address is not verified yet.',
            'email_no_change' => 'The email address cannot be changed here.',

            'locale' => 'Language',
            'locale_follow' => 'Follow the account language (:account)',
            'locale_follow_plain' => 'Follow the account language',

            'timezone' => 'Time zone',
            'timezone_follow' => 'Follow the account time zone (:timezone)',
            'timezone_follow_plain' => 'Follow the account time zone',

            'unit_system' => 'Unit system',
            'unit_follow' => 'Follow the account unit system (:unit)',
            'unit_follow_plain' => 'Follow the account unit system',

            'submit' => 'Save',
        ],

        'accounts' => [
            'title' => 'Accounts',
            'heading' => 'Accounts',
            'intro' => 'One account at a time. The changes apply to everyone in the account.',

            'roles' => [
                'owner' => 'Owner',
                'admin' => 'Admin',
                'member' => 'Member',
            ],

            'read_only' => 'You can see the details but not change them.',
            'empty' => 'You are not a member of any account.',

            'submit' => 'Save',
        ],

        'security' => [
            'title' => 'Security',
            'heading' => 'Security',

            'totp' => [
                'heading' => 'Two-factor authentication',
                'intro' => 'Two-factor authentication requires a one-time code from an authenticator app every time you log in.',

                'enable' => 'Enable two-factor',
                'setup_intro' => 'Scan the link with your authenticator app, or enter the secret by hand. Then confirm with the code the app shows.',
                'uri_label' => 'Link for the authenticator app',
                'secret_label' => 'Secret to enter by hand',
                'copy' => 'Copy the link',
                'copied' => 'The link is copied',
                'code_label' => 'One-time code',
                'confirm' => 'Confirm and turn on',
                'confirmed_at' => 'Two-factor authentication has been on since :date.',

                'recovery_heading' => 'Recovery codes',
                'recovery_remaining' => 'Codes left: :count',
                'recovery_warning' => 'A new sheet makes every previous code unusable right away.',
                'recovery_generate' => 'Generate new codes',
                'recovery_once' => 'The codes are shown this once only. Save them where you can reach them without the app.',

                'disable_heading' => 'Turn off two-factor authentication',
                'disable_warning' => 'Turning it off deletes the recovery codes. Turning it on again gives you a new sheet.',
                'disable_submit' => 'Turn off two-factor',
            ],
        ],
    ],

    'container' => [
        'kind' => [
            'boat' => 'Boat',
            'caravan' => 'Caravan',
            'house' => 'House',
            'car' => 'Car',
            'other' => 'Other',
        ],

        'nav' => [
            'sharing' => 'Sharing',
            'settings' => 'Settings',
        ],

        'index' => [
            'title' => 'Binders',
            'heading' => 'Binders',
            'create' => 'New binder',
            'empty' => 'You have no binders yet.',
            'shared' => 'Shared with you',
            'active' => 'Active',
            'make_active' => 'Make active',
            'edit' => 'Edit',
        ],

        'create' => [
            'title' => 'New binder',
            'heading' => 'New binder',

            'name' => 'Name',
            'kind' => 'Type',
            'account' => 'Account',
            'account_choose' => 'Choose an account',

            'submit' => 'Create',
        ],

        'edit' => [
            'title' => 'Settings',
            'heading' => 'Settings',

            'name' => 'Name',
            'kind' => 'Type',

            'submit' => 'Save',
        ],
    ],

    // The sharing page, see issue 55a. Two sections with different audiences
    // (decision 3), and the texts follow that split.
    'sharing' => [
        'title' => 'Sharing',
        'heading' => 'Sharing',

        'participants' => [
            'heading' => 'Participants',
            'description' => 'Everyone with access to the binder right now. An account counts as one participant, never as its members.',
        ],

        'role' => [
            'owner' => 'Owner',
            'member' => 'Member',
            'managed' => 'Organisation',
            'guest' => 'Guest',
        ],

        'accesses' => [
            'heading' => 'Accesses',
            'description' => 'Everything shared from the binder, and the history of what has been revoked or expired.',

            // No level may delete the binder, manage accesses or start a
            // transfer of ownership. Stated once on the page, not per row.
            'limits' => 'No access grants the right to delete the binder, manage accesses or start a transfer of ownership. That is always the owner account.',

            'level' => 'Level',
            'grantee' => 'Recipient',
            'granted_by' => 'Granted by',
            'expires' => 'Expires :date',

            // The recipient and the granter are shown by NAME, never by ULID.
            // Neither `User` nor `Account` uses SoftDeletes, so a row can in
            // fact be gone: then it is this sentence and not the ULID. Never
            // an email address ([[Konton och åtkomst]] § Behörighetsregler,
            // last paragraph).
            'grantee_unknown' => 'Removed recipient',
            'granted_by_unknown' => 'Removed user',

            // The expiry field is rendered only on a row that ALREADY has a
            // date, and the sentence below says why it cannot be removed: a
            // guest without an expiry contradicts Beslut 5, and the way from
            // guest to permanent goes through `kind`, which is `prohibited`
            // on purpose.
            'expires_at' => 'Valid until',
            'expires_fixed' => 'The expiry can be moved forward but not removed. A guest that should become permanent is revoked and invited again as a member.',

            'save' => 'Save level',
            'revoke' => 'Revoke',
        ],

        // The third section, see issue 55b decision 5. The list shows the
        // address — that is the whole difference from the access list above,
        // and it is the sender's own list of what she has sent. The gate is
        // the same (`viewAccesses()`), and `invitations` is `null` for anyone
        // who may not see it.
        'invitations' => [
            'heading' => 'Invitations',
            'description' => 'Addresses that have been invited but have not answered yet. An invitation grants no access until it is accepted.',

            'email' => 'Email',
            'item' => 'Scope',
            'item_container' => 'The whole binder',

            'submit' => 'Invite',
            'revoke' => 'Withdraw',
            'expires' => 'Expires :date',
            'invited_by' => 'Invited by',
            'empty' => 'No invitations yet.',

            // The status comes from App\Http\Resources\InvitationResource and
            // is never read from the column: a `pending` row past its
            // `expires_at` is reported as `expired` without the row changing
            // (issue 10a decision 7).
            'status' => [
                'pending' => 'Waiting for an answer',
                'expired' => 'Expired',
                'accepted' => 'Accepted',
                'rejected' => 'Declined',
                'revoked' => 'Withdrawn',
            ],
        ],

        'history' => [
            'heading' => 'History',
            'revoked' => 'Revoked :date',
            'expired' => 'Expired :date',
        ],

        'scope' => [
            'container' => 'The whole binder',
            'item' => ':item reaches :reach items',
        ],

        'kind' => [
            'member' => 'A person — a partner or a co-owner.',
            'managed' => 'An organisation with a service relationship, typically a yard. It does not own the binder, and what it creates is attributed to the organisation.',
            'guest' => 'Temporary access with an expiry date.',
        ],

        'level' => [
            'read' => [
                'label' => 'Read',
                'description' => 'Reads. Touches nothing.',
            ],
            'create' => [
                'label' => 'Add',
                'description' => 'Adds attachments, costs, schedules and new child items — but never touches anything that already exists.',
            ],
            'write' => [
                'label' => 'Change',
                'description' => 'Also changes what is already in the binder.',
            ],
            'delete' => [
                'label' => 'Delete',
                'description' => 'Soft-deletes and restores from the bin.',
            ],
        ],

        'advanced' => 'Advanced',

        'frozen' => 'The account is frozen and cannot change levels. Revoking an access still works.',
    ],

    // The landing page of the email, see issue 55b decisions 2, 3 and 4.
    // App\Http\Controllers\InvitationResponseController renders exactly one of
    // five states, and the texts below are one of the means of keeping them
    // apart. `mismatch` and `unavailable` must be WORDED differently but
    // INFORM equally little: `unavailable` never says whether the token
    // exists, and `mismatch` never reveals which address the invitation is
    // for.
    'invitation' => [
        'title' => 'Invitation',
        'heading' => 'Invitation',

        // The binder's name and the inviter's name are shown to a guest too.
        // That is not new information: InvitationNotification prints the
        // binder's name in both the subject line and the body, and whoever has
        // the link has received the email. The address the invitation is for
        // is never shown.
        'intro' => ':inviter has invited you to the binder :container.',
        'level' => 'Level: :level',

        // A guest has no address to compare with and therefore no form to
        // answer in — the way goes through signing in or registering, and the
        // token stays in the session until then.
        'guest' => 'Sign in or create an account with the address the invitation is for. Then you can answer.',

        'mismatch' => 'This invitation is for a different email address than the one you are signed in with.',
        'unavailable' => 'This invitation can no longer be used.',

        'accept' => 'Accept',
        'reject' => 'Decline',
        'home' => 'Go to the home page',
    ],
];
