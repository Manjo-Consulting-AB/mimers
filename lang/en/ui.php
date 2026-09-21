<?php

/*
 * The interface text, se issue 52 § Beslut 4 och 5. Engelska är enda
 * levererade språket ([[ADR-0034 Engelska vid lansering]]) och det här är
 * den enda katalogen: en nyckel utan värde är en bugg, inte en
 * "tills vidare"-platshållare (Beslut 6).
 *
 * Copyn beskriver produkten enligt [[ADR-0033 Produktens omfång]] § Beslut:
 * containern är ett sammanhang — en båt, en bil, en fastighet, en kund eller
 * ett projekt — och exemplen spänner över bredden. Den som skriver en ny
 * sträng ska läsa det beslutet först; den smala formuleringen kröp in en
 * sträng i taget och var svår att upptäcka i efterhand.
 */
return [
    'common' => [
        'brand' => 'Mimers',
        'tagline' => 'The place for everything you own, use or work with.',
        'to_dashboard' => 'Go to the dashboard',
        'home' => 'Back to the start page',
        'pending' => [
            'default' => 'Working…',
            'upload' => 'Uploading…',
            'export' => 'Preparing the export…',
            'complete' => 'Checking off…',
            'transfer' => 'Taking over the container…',
        ],
    ],

    'nav' => [
        'menu' => 'Menu',
        'menu_close' => 'Close the menu',
        'dashboard' => 'Dashboard',
        'containers' => 'Containers',
        'transfers' => 'Ownership transfers',
        // The way into the global search, see issue 78 decision 2: the field
        // in the header is not enough of a way in — someone who does not
        // already know the search exists looks for a row in the menu.
        'search' => 'Search',
        // The way into the settings, see issue 79 decision 1: the section list
        // only renders once you are already on a settings page, so without
        // this row the security page — and two-factor — was reachable only by
        // someone already standing there. The link points at /settings, which
        // redirects to the profile (issue 53c).
        'settings' => 'Settings',
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

            // Steg två, se issue 80 § Beslut 2 och
            // resources/js/pages/Auth/MagicLinkCode.vue — samma nycklar som
            // lang/sv/ui.php.
            'code' => [
                'title' => 'Enter your code',
                'heading' => 'Enter your code',
                'intro' => 'The link has been used. Enter the code from your authenticator app, or a recovery code, to log in.',
                'submit' => 'Log in',
            ],
            'request_again' => 'Ask for a new link',
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

        // Issue 65a § Decision 6. Two codes and not one: the page has two
        // forms, and "saved" without saying what had been true but not an
        // answer to what happened.
        'notification-preferences-updated' => 'The notification settings have been saved.',
        'quiet-hours-updated' => 'The quiet hours have been saved.',
        'container-created' => 'The container has been created.',
        'container-updated' => 'The container has been saved.',
        'access-updated' => 'The access has been saved.',
        'access-revoked' => 'The access has been revoked.',
        'invitation-sent' => 'The invitation has been sent.',
        'invitation-revoked' => 'The invitation has been withdrawn.',
        'invitation-accepted' => 'The invitation has been accepted. The container is under Containers.',
        'invitation-rejected' => 'The invitation has been declined.',
        'category-created' => 'The category has been created.',
        'category-updated' => 'The category has been saved.',
        'category-deleted' => 'The category has been deleted.',
        'category-preset-applied' => 'The categories have been added. Change them however you like.',
        'tag-created' => 'The tag has been created.',
        'tag-updated' => 'The tag has been saved.',
        'tag-deleted' => 'The tag has been deleted.',
        'item-created' => 'The item has been created.',
        'item-updated' => 'The item has been saved.',
        'item-deleted' => 'The item is in the trash. It can be restored within 30 days.',
        'item-link-created' => 'The relation has been created.',
        // Unlinking removes the connection and nothing else — the row is
        // deleted hard (issue 14 decision 10), but both items remain.
        'item-link-removed' => 'The link is gone. Both items remain.',

        // Issue 60 decision 9. The attachment is soft-deleted — `deleted_at`
        // is set and the bytes stay until the trash purges them (ADR-0008) —
        // so the sentence says the trash and the 30 days, never "deleted".
        'attachment-uploaded' => 'The attachment has been uploaded.',
        'attachment-deleted' => 'The attachment is in the trash. It can be restored within 30 days.',

        // Issue 67a decision 1. Three codes and not one: the three buttons do
        // three different things, and "saved" without saying what would have
        // been true but not an answer to what happened. `loan-deleted` says the
        // ROW is gone and never that the thing is back — the row is
        // soft-deleted and does not enter the trash (issue 76 decision 3), so
        // the sentence promises no restore.
        'loan-created' => 'The loan has been registered.',
        'loan-updated' => 'The loan has been saved.',
        'loan-deleted' => 'The loan row is gone.',

        // Issue 62a decision 7. ONE code for all four types: the restore takes
        // `type` in the body and shares one list, so the view has no reason to
        // know which of them just came back — but the sentence says content,
        // not item (same reason as issue 20a decision 1).
        'trash-restored' => 'The content has been restored.',

        // Issue 62b decisions 5 and 6. The deletion lands on the container list —
        // and the sentence points at the trash, where the link sits right
        // below. The restore says the container is back; it does NOT become
        // active on its own, because choosing a container is the user's action.
        'container-trashed' => 'The container is in the trash.',
        'container-restored' => 'The container has been restored.',

        // Issue 63a decisions 6 and 8. The pause is the same write as a
        // changed title and therefore gets its own sentence: "saved" would
        // have been true but would not have answered what happened.
        // `schedule-deleted` mentions neither the trash nor 30 days — the
        // schedule cannot be restored from a view (issue 20a decision 3), and
        // a restore that does not exist must not be promised in a flash.
        'schedule-created' => 'The schedule has been created.',
        'schedule-updated' => 'The schedule has been saved.',
        'schedule-paused' => 'The schedule is paused. It opens no new occurrences until you resume it.',
        'schedule-resumed' => 'The schedule is active again.',
        'schedule-deleted' => 'The schedule has been removed.',

        // Issue 63b decisions 5 and 8. Checking off and skipping get one
        // sentence each: they close the same row but say different things
        // about the work, and a shared "the occurrence is closed" would make
        // the log unreadable.
        'occurrence-completed' => 'The task has been checked off.',
        'occurrence-skipped' => 'The task was skipped and saved as such — not as done.',

        // Issue 63c decision 7. `removed` says that nothing else disappeared:
        // the row is hard-deleted (issue 23 decision 7) and what is lost is the
        // link — both schedules and both occurrences remain.
        'schedule-dependency-created' => 'The dependency has been added.',
        'schedule-dependency-removed' => 'The dependency is gone. Both schedules remain.',
        'occurrence-dependency-created' => 'The exception has been added.',
        'occurrence-dependency-removed' => 'The exception is gone. Both schedules and both occurrences remain.',

        // Issue 65b decisions 2, 3 and 4. Creating a feed and an endpoint has
        // no code of its own: there the secret itself is the message, and a
        // "created" line above it would only repeat it. Revoking and the two
        // webhook writes get one sentence each — the one says the link is dead,
        // the other that the row was saved.
        'calendar-feed-revoked' => 'The calendar link has been revoked. It will stop updating in the calendar.',
        'webhook-updated' => 'The webhook has been saved.',
        'webhook-destroyed' => 'The webhook is gone. The secret that belonged to it is gone too.',

        // Issue 67b decisions 4 and 7. The first three belong to the sender —
        // she sends, withdraws, and learns that the recipient declined — and
        // the fourth to the recipient. `transfer-rejected` says what the
        // decision means for HER (the container is not hers), not what the server
        // did, and `transfer-accepted` says that the container is now in her list.
        'transfer-created' => 'The transfer has been sent. The recipient sees it under Ownership transfers.',
        'transfer-revoked' => 'The transfer has been withdrawn. The row stays in the history.',
        'transfer-accepted' => 'The container is yours. You will find it in the container list.',
        'transfer-rejected' => 'You have declined. The sender must send a new transfer if you change your mind.',

        // Issue 67c decision 3. The order answers immediately, and the
        // sentence says what happens next — that the row is already in the
        // list and that it is being packed. Without it a click would look like
        // it did nothing, because the new row is the only visible change.
        'export-requested' => 'The export has been ordered. The bag is being packed and shows up here when it is done.',

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
            'containers_exceeded' => 'The account has reached its limit for the number of containers (:used of :limit).',
            // Issue 55b decision 6: the two limits an invitation form can hit.
            'shared_users_exceeded' => 'The sharing has reached the account limit (:used of :limit).',
            'pending_invitations_exceeded' => 'The account has reached its limit for outstanding invitations (:used of :limit).',

            // Issue 60 decision 5: the two upload limits on the web.
            // :limit_bytes, :used_bytes and :file_bytes are formatted into
            // readable numbers by App\Support\Frontend\ApiErrorTranslator
            // (decision 6) — a limit of "5368709120" is not a limit anyone
            // understands. The sentences must use the numbers: a message that
            // throws away `data` is worse than the error code it replaced.
            'max_file_size_exceeded' => 'The file is too large. The limit is :limit_bytes, and this one is :file_bytes.',
            'storage_exceeded' => 'The storage is full. The account has :used_bytes of :limit_bytes, and the file is :file_bytes.',
        ],

        // Issue 55a decision 9: PATCH on a revoked or expired row answers
        // `container_access.revoked` on /api and this sentence in the web.
        'container_access' => [
            'revoked' => 'The access has been revoked or has expired and cannot be changed.',
        ],

        // Issue 62a decision 7: `RestoreContent` throws `trash.parent_deleted`
        // when the parent is still in the trash — an attachment whose item is
        // deleted, or a subcategory whose parent is. On the web the code
        // becomes this sentence in a box above the list, never a JSON body.
        'trash' => [
            'parent_deleted' => 'It cannot be restored: what the content belongs to is still in the trash. Restore that first.',
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

        // The category error codes, see issue 56a decision 4. The first three
        // belong to a MOVE and land on the `parent` field; the last two belong
        // to a DELETION and land on the `category` form key.
        'category' => [
            'max_depth_exceeded' => 'A category can be at most :max_depth levels deep.',
            'cycle' => 'A category cannot be moved into itself or into one of its own subcategories.',
            'parent_not_in_container' => 'The chosen parent category is not in this container.',
            'has_children' => 'The category has :children subcategories and cannot be deleted.',
            'has_items' => 'The category has :items items and cannot be deleted.',
        ],

        // The relation form's four domain errors, see issue 58 decision 6.
        // They arrive as App\Exceptions\Api\ApiException from
        // App\Actions\Item\LinkItems and become field errors in
        // App\Http\Controllers\ItemLinkController — never a raw JSON body in
        // the middle of a page.
        //
        // `self`, `cross_container` and `pair_exists` belong to the `item`
        // field (which item was chosen), `cycle` to `relation` (which
        // direction was chosen). `pair_exists` carries the existing relation
        // in `data.relation` and the sentence MUST say it — the words below
        // are the code turned into an adjective, and a message that throws
        // `data` away is worse than the error code it replaced.
        'item_link' => [
            'self' => 'An item cannot be linked to itself.',
            'cross_container' => 'Relations only go between items in the same container.',
            'pair_exists' => 'The two are already linked: the counterpart is :relation.',
            'relation_word' => [
                'parent' => 'a parent',
                'child' => 'a child',
                'related' => 'a related item',
            ],
            'cycle' => 'That direction would make a circle: this item is already above the counterpart, directly or through other items.',
        ],

        // The loan domain error on the web, see issue 67a decision 6 and issue
        // 76 decision 4. The code comes from App\Exceptions\Api\ApiException
        // and `data.loan` carries the ULID of the blocking row — it is kept for
        // the client, but the sentence is the user's.
        'loan' => [
            'already_open' => 'The item is already lent out. Register the return first.',
        ],

        // The export's only domain error on the web, see issue 67c decision 4.
        // `export.already_running` carries the ULID of the running row in
        // `data.export` — it is kept for an API client, but the sentence is
        // the user's and a ULID tells her nothing. What she needs to know is
        // that a bag is already being packed and WHERE it is: the row sits in
        // the list just below the button, with the status "the bag is being
        // packed". The same trade-off `error.loan.already_open` made in 67a.
        'export' => [
            'already_running' => 'An export is already being packed. It is in the list below — wait until it is done.',
        ],

        // The ownership transfer's domain errors on the web, see issue 67b
        // decisions 3, 4, 6 and 9.
        //
        // **The branch is called `transfer`, not `ownership_transfer`**, even
        // though the sentences belong to the ownership transfer: the API's
        // error codes are `transfer.*` (39a and 39b), and
        // App\Support\Frontend\ApiErrorTranslator looks a code up by replacing
        // the first segment with `error.` — `transfer.expired` is looked up as
        // `error.transfer.expired`. An `error.ownership_transfer.expired` would
        // be a key nobody asks for, and every refused transfer would fall back
        // to `error.generic`.
        'transfer' => [
            'already_pending' => 'The container already has a transfer waiting for an answer. Withdraw it first if you want to change the recipient.',
            'not_pending' => 'The transfer has already been answered or withdrawn and cannot be changed.',
            'expired' => 'The transfer has expired. The sender must send a new one.',
            'account_frozen' => 'Your account is frozen and cannot receive a container right now.',
        ],

        // The close flow's domain errors on the web, see issue 63b decision 6.
        // They arrive as App\Exceptions\Api\ApiException from
        // App\Actions\Schedule\CloseOccurrence and become a form error on the
        // `occurrence` key — never a raw JSON body in the middle of a page.
        //
        // `blocked` is two keys, not one: `data.blocked_by` is a LIST, and
        // ApiErrorTranslator passes `data` straight into `trans()` as
        // replacements. The controller writes the leading sentence itself and
        // appends one `blocked_row` per blocker after it.
        'occurrence' => [
            'blocked' => 'This task cannot be checked off yet — these are not done:',
            'blocked_row' => '• :title — due :date',
            'not_open' => 'This occurrence is already closed and cannot be checked off again.',

            // Issue 63c decision 6: the three error codes of the occurrence
            // dependency, from App\Actions\Schedule\DependOccurrence. All land
            // on the `depends_on` field. The cycle sentence uses `data`: the
            // code carries the two ULIDs of the edge that was attempted, and
            // the sentence names them with their schedule titles.
            'dependency_self' => 'An occurrence cannot wait for itself.',
            'dependency_cycle' => 'This direction would create a circle: ":schedule" already waits for ":depends_on", directly or through other occurrences.',
            'dependency_not_in_container' => 'Dependencies only run between occurrences in the same container.',
        ],

        'schedule' => [
            'inactive' => 'The schedule is paused, and checking off would open a new occurrence on a schedule nobody wants occurrences on. Resume the schedule first.',

            // Issue 63c decision 6: the three error codes of the schedule
            // dependency, from App\Actions\Schedule\DependSchedule.
            'dependency_self' => 'A schedule cannot wait for itself.',
            'dependency_cycle' => 'This direction would create a circle: ":schedule" already waits for ":depends_on", directly or through other schedules.',
            'dependency_not_in_container' => 'Dependencies only run between schedules in the same container.',
        ],

        // Issue 65b decision 5: the feature gate. `Entitlements::assertFeature()`
        // throws `plan.feature_unavailable` with the feature name in
        // `data.feature` — a CODE (`webhooks`), like `item_link.pair_exists`
        // carries one — and App\Http\Controllers\WebhookEndpointController
        // translates it in two steps: first to a name, then into the sentence.
        // The sentence is the same on the page and in the field error, because
        // it is written once and sent both as a prop and as an error.
        //
        // The plan is named because webhooks is the Pro feature: the server
        // answers which feature is missing, not which plan applies, and Pro is
        // the only plan that has it. If a second plan gets the feature, or a
        // second feature gets a web surface, this is where the sentence becomes
        // per-feature.
        'plan' => [
            // The sentence deliberately carries no link, also now that the plan page
            // exists (66a). The string is also delivered inside the API error envelope,
            // and markup in a translation string becomes either escaped text for an API
            // client or `v-html` in the view. If someone wants a link to the plan it is
            // a separate key and a separate link beside the error box in Webhooks.vue,
            // not an `<a>` in here.
            'feature_unavailable' => ':feature requires the Pro plan.',
            'feature_name' => [
                'webhooks' => 'Webhooks',
                // Issue 67b decision 3: the ownership transfer's web surface
                // reads the same gate, and `data.feature` is the code
                // `ownership_transfer`. The name lives here and not in a
                // sentence per surface — the question "which plan is needed"
                // is answered in one place.
                'ownership_transfer' => 'Ownership transfer',
            ],
        ],

        // Issue 65b decision 7: the SSRF answer on the web. `UrlSafetyValidator`
        // answers `webhook.unsafe_url` with a REASON in `data.reason` — also a
        // code (`reserved_ip`, `invalid_scheme`, …) — and the controller
        // translates it in two steps just like above. The error lands on the
        // `url` field: it is about what the user typed, and the server's
        // sentence is what the user meets. The page runs no check of its own
        // for private ranges, `localhost` or metadata services.
        'webhook' => [
            'unsafe_url' => 'The address cannot be used: :reason.',

            'unsafe_reason' => [
                'unparseable_url' => 'it cannot be read as an address',
                'invalid_scheme' => 'it has to start with https://',
                'userinfo' => 'it must not carry a user name or password',
                'invalid_port' => 'the port is not allowed',
                'reserved_hostname' => 'it points at the server itself',
                'dns_lookup_failed' => 'the address cannot be looked up',
                'reserved_ip' => 'it points at a private network',
            ],
        ],
    ],

    'settings' => [
        'title' => 'Settings',

        'nav' => [
            'profile' => 'Profile',
            'accounts' => 'Accounts',
            // Issue 66a decision 1: the plan page sits after the accounts —
            // both are about the ACCOUNT, and the plan is the answer to what it
            // may do.
            'plan' => 'Plan',
            // Issue 66b decision 1: the storage page sits directly after the
            // plan — it is step 2 of the downgrade and the answer to the
            // plan's own prompt, and the plan page links here.
            'storage' => 'Storage',
            'notifications' => 'Notifications',
            // Issue 65b decision 1: the webhooks belong to the ACCOUNT and
            // therefore live among the settings, after the notifications — both
            // are about what leaves the system, but one is about the person and
            // the other about the account.
            'webhooks' => 'Webhooks',
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

            // Issue 85 · [[ADR-0037 Valutans arv]] — the account carries the
            // currency and is the bottom of the inheritance: a container
            // without one of its own falls back on this value. Three letters,
            // no list to pick from ([[ADR-0033 Produktens omfång]]), and no
            // "follow ..." option — the account has nothing to follow.
            'currency' => 'Currency',

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

    // Notification settings, see issue 65a. The type keys are the values
    // of `notification.type` (`task.due`) and are nested under `type`:
    // the key form is `notifications.type.<type>.label`, and the dot in
    // the type name is the same dot that separates the parts of a
    // translation key.
    'notifications' => [
        'title' => 'Notifications',
        'heading' => 'Notifications',
        'intro' => 'Choose what you want email about, and when you do not want to be disturbed.',

        'types_heading' => 'What you want email about',

        // The default and the reason for it (Decision 3).
        'digest_default' => 'The weekly summary is the default for task reminders. In April everything falls due at once, and twenty separate emails in one morning are harder to read than one collected.',

        // The mark on a type the user has never touched. The value comes
        // from `is_default` in the server response (31b § Decision 2).
        'default_badge' => 'Default',

        'type' => [
            'task' => [
                'due' => [
                    'label' => 'Task falls due',
                    'description' => 'The day a scheduled task is to be done.',
                ],
                'overdue' => [
                    'label' => 'Task is overdue',
                    'description' => 'When a task has passed its date without being ticked off.',
                ],
            ],
            'loan' => [
                'due' => [
                    'label' => 'Loan is due back',
                    'description' => 'When an item you have lent out nears its return date.',
                ],
            ],
            'quota' => [
                'warning' => [
                    'label' => 'Storage is running out',
                    'description' => 'When a quota in your plan nears its limit.',
                ],
            ],
            'invitation' => [
                'received' => [
                    'label' => 'Invitation to a container',
                    'description' => 'When someone invites you to a container.',
                ],
            ],
            'transfer' => [
                'requested' => [
                    'label' => 'Someone wants to take over an account',
                    'description' => 'When a request for a change of owner is waiting for you.',
                ],
            ],
            'account' => [
                'inactive' => [
                    'label' => 'Account closes from inactivity',
                    'description' => 'Before an account you are a member of closes because it has not been used.',
                ],
            ],
        ],

        // The three modes, see Decision 2.
        'mode' => [
            'direct' => [
                'label' => 'Straight away',
                'description' => 'An email when it happens.',
            ],
            'digest' => [
                'label' => 'In the weekly summary',
                'description' => 'Collected in one email a week.',
            ],
            'never' => [
                'label' => 'Never',
                'description' => 'No email of this kind.',
            ],
        ],

        'submit' => 'Save',

        'quiet_hours' => [
            'heading' => 'Quiet hours',
            'intro' => 'No email is sent during these hours.',

            'start' => 'From',
            'end' => 'To',

            'empty_note' => 'Empty fields mean no quiet hours. Fill in both if you want a window.',
            'midnight_note' => 'The window may cross midnight: 22:00 to 07:00 means evening to morning.',

            'delays_note' => 'A notification that falls inside the quiet window arrives afterwards instead — it is not lost.',

            'timezone' => 'The hours apply in the time zone :timezone.',
            'timezone_follows_account' => 'The hours apply in the account time zone.',
            'timezone_link' => 'Change the time zone on your profile.',

            'submit' => 'Save quiet hours',
        ],
    ],

    // The container's calendar link, see issue 65b decisions 2 and 4 and
    // resources/js/pages/Containers/CalendarFeed.vue.
    //
    // The address is in practice a password to the container's tasks ([[Notiser]]
    // § ICS-kalenderfeed), and the texts say so in two places: `url_once` at
    // the display and `revoke_confirm` at the revocation. Whoever lost the link
    // has nothing to retrieve — the answer is to revoke and create a new one.
    'calendar' => [
        'title' => 'Calendar',
        'heading' => 'Calendar',
        'intro' => 'Subscribe to the container\'s tasks in the calendar you already use. The link is personal and shows only what you can see yourself.',

        'url_label' => 'The calendar address',
        'url_description' => 'Add the address to your calendar app. It fetches the tasks itself and keeps itself up to date.',
        'url_once' => 'This is the only time the address is shown. If you lose it, revoke the link and create a new one.',

        'copy' => 'Copy the address',
        'copied' => 'The address is copied',

        'create' => 'Create a calendar link',

        'list_heading' => 'Your links to this container',
        'empty' => 'You have no calendar links to this container yet.',

        'revoke' => 'Revoke',
        'revoke_confirm' => 'Revoke the calendar link? The calendar stops updating, and the address cannot be retrieved.',

        // A revoked row STAYS in the list (decision 4) and says when the link
        // died — whoever wonders why the calendar stopped updating should see
        // the answer rather than an empty list.
        'row' => [
            'created' => 'Created :date',
            'last_fetched' => 'Last fetched :date',
            'never_fetched' => 'Not fetched yet',
            'revoked_badge' => 'Revoked',
            'revoked_note' => 'The link was revoked :date and the calendar no longer updates.',
        ],
    ],

    // The account's webhooks, see issue 65b decisions 1, 3, 5, 6, 7 and 8 and
    // resources/js/pages/Settings/Webhooks.vue.
    //
    // Two secrets in the same shape: `secret_once` matches the calendar's
    // `url_once` and for the same reason. `secret_description` explains what
    // the secret is FOR — HMAC-SHA256 over the body — because a secret without
    // an explanation is a string you paste somewhere and forget.
    'webhook' => [
        'title' => 'Webhooks',
        'heading' => 'Webhooks',
        'intro' => 'Send events to your own systems. Every delivery is signed, so the receiver can check that it comes from us.',

        'account_label' => 'Account',

        'secret_label' => 'The secret',
        'secret_description' => 'Verify the signature with it: HMAC-SHA256 over the body, with the secret as the key. Without it our deliveries cannot be told apart from anyone else\'s.',
        'secret_once' => 'This is the only time the secret is shown. If you lose it, remove the webhook and create a new one.',

        'copy' => 'Copy the secret',
        'copied' => 'The secret is copied',

        'create_heading' => 'New webhook',

        'url_label' => 'Address',
        // The page runs no check of its own for private ranges, `localhost` or
        // metadata services (decision 7) — but whoever types an address should
        // know the rules before the server answers.
        'url_hint' => 'A public https address. Addresses on your own network, on the server itself, or without https are rejected.',

        'event_types_label' => 'Events',

        // A name and a one-line explanation per type, like the notification
        // types in issue 65a decision 7. The keys follow the type name in
        // App\Models\WebhookEndpoint::EVENT_TYPES (`task.due` becomes
        // `event_type.task.due`), so a new type puts its text here and nowhere
        // else.
        'event_type' => [
            'task' => [
                'due' => [
                    'label' => 'Task falls due',
                    'description' => 'When a scheduled task becomes visible or falls due.',
                ],
                'overdue' => [
                    'label' => 'Task is overdue',
                    'description' => 'When a task has passed its date without being checked off.',
                ],
            ],
            'loan' => [
                'due' => [
                    'label' => 'Loan is due back',
                    'description' => 'When a lent item approaches its return date.',
                ],
            ],
            'quota' => [
                'warning' => [
                    'label' => 'Storage is running out',
                    'description' => 'When a quota in the plan passes 80 or 100 percent.',
                ],
            ],
            'invitation' => [
                'received' => [
                    'label' => 'Invitation to a container',
                    'description' => 'When someone invites a member to the account.',
                ],
            ],
            'transfer' => [
                'requested' => [
                    'label' => 'Ownership transfer requested',
                    'description' => 'When someone wants to take over an account.',
                ],
            ],
            'account' => [
                'inactive' => [
                    'label' => 'The account is closed for inactivity',
                    'description' => 'Before an account is closed because it has not been used.',
                ],
            ],
        ],

        'create' => 'Create webhook',

        // Edit mode (decision 3): the SAME form as create, but PATCH instead
        // of POST and without the secret — changing the address or adding an
        // event type must not rotate the secret, because then the receiver
        // would have to be reconfigured for a reason that is not the secret's.
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',

        'list_heading' => 'The account\'s webhooks',
        'empty' => 'The account has no webhooks yet.',

        'inactive_badge' => 'Disabled',

        // The difference is the whole point of the flag from the server's
        // answer (decision 8): a row that merely looked disabled would look as
        // if the user had done it. The system sentence also says the counter
        // starts over, because that is what reactivation does on the server.
        'disabled_by_system' => 'The system disabled it after repeated delivery failures. Turn it back on once the address works — the count then starts over from zero.',
        'disabled_by_user' => 'You disabled it. Turn it back on when you want the deliveries again.',

        'deactivate' => 'Disable',
        'activate' => 'Turn on',

        'destroy' => 'Remove',
        'destroy_confirm' => 'Remove the webhook? The secret goes with it, and a new webhook gets a new secret.',
    ],

    // The plan page, see issue 66a decisions 4–9 and [[Planer och kvoter]].
    //
    // A branch of its own at the top level, like `trash` and `sharing`: the
    // plan page is a surface with a vocabulary of its own, and the keys live
    // under `plan.*` and nowhere else (decision 9).
    'plan' => [
        'title' => 'Plan and usage',
        'heading' => 'Plan and usage',
        'intro' => 'What the account has, how much of each limit is used, and what a downgrade would mean.',

        'account_label' => 'Account',
        'name_label' => 'Plan',
        'price_label' => 'Price',
        'current_heading' => 'Current plan',

        // Plan names per `code` (decision 9). The keys are the value of the
        // `plan.code` column, never the plan name from the database: a
        // translation per code gives the answer in the reader's language, and
        // a plan whose code is missing shows up as `plan.names.…` instead of
        // silently turning English.
        'names' => [
            'free' => 'Free',
            'pro' => 'Pro',
            'broker' => 'Broker',
            'yard' => 'Yard',
            'charter' => 'Charter',
        ],

        'price_free' => 'Free of charge',
        'period' => [
            'year' => 'per year',
            'month' => 'per month',
        ],

        // The status of the account stands at the top and is not buried
        // (decision 5). The keys are the values of `account.read_only_reason`
        // — a code, like `attachment.kind` — so a new reason is a new line here
        // and no `if` in the view. An `active` account has no reason and gets
        // no box.
        //
        // The days are phrased with 62a's two plural keys
        // (`trash.expires.day` and `trash.expires.days`), see Plan.vue: the
        // same sentence about the same thing, and `t()` has no pluralisation
        // (issue 52 decision 4).
        'status' => [
            'payment_failed' => 'The account is frozen: a payment has not gone through.',
            'over_quota' => 'The account is frozen: it is over the limit for its plan.',
            'inactivity' => 'The account is frozen: it has not been used for a long time.',
            'grace' => 'The grace period ends:',
        ],

        'usage_heading' => 'Usage and limits',

        // The four numeric limits (decision 4). The keys are the keys of
        // `plan.limits`, never names of our own making.
        'limits' => [
            'containers' => 'Containers',
            'storage_bytes' => 'Storage',
            'max_file_bytes' => 'Largest file size',
            'shared_users_per_container' => 'Shared users per container',
        ],

        // `:used` and `:limit` are formatted by the server — the bytes with
        // Number::fileSize(), the same formatting as the quota sentences in
        // 60a.
        'of' => ':used of :limit',
        'of_unlimited' => ':used of unlimited',
        'per_container' => ':limit per container',

        // `null` is unlimited and is written as a word, never as zero and
        // never as a full bar (decision 4).
        'unlimited' => 'Unlimited',
        'storage_bar' => ':percent percent of the limit used',

        // The feature table: one row per key in ReadPlanUsage::FEATURES
        // (decision 9).
        'features_heading' => 'Features',
        'features' => [
            'webhooks' => 'Webhooks',
            'pdf_binder' => 'PDF binder',
            'ownership_transfer' => 'Ownership transfer',
            'loan_reminders' => 'Loan reminders',
            'cost_reports' => 'Cost reports',
        ],
        'included' => 'Included',
        'not_included' => 'Not included',

        // The five steps of the downgrade, word for word from [[Planer och
        // kvoter]] § Nedgradering (decision 6) — and what does NOT happen,
        // which is the most important thing on the page: items are never
        // deleted, cost rows are never deleted.
        'downgrade' => [
            'heading' => 'If you downgrade',
            'intro' => 'Your items are never deleted — only attachments are. It works like this:',

            'steps' => [
                'freeze' => 'The payment fails and the account is frozen. Nothing is deleted.',
                'choose' => 'You get your attachments listed and choose yourself what has to go — you know which forty holiday photos can go and which inspection report cannot.',
                'grace' => 'You have three months to pay or export.',
                'purge' => 'If nothing happens, the attachments are deleted automatically, newest first, until the account fits within Free.',
                'restore' => 'The account returns to active on the free level.',
            ],

            'kept' => [
                'items' => 'Your items are never deleted.',
                'costs' => 'Cost rows are never deleted. The receipts may go with the attachments — the numbers stay.',
            ],

            // The preview (decision 7). Concrete when the account is over the
            // free limit, and "everything fits" with no numbers about deletion
            // when it is not. `remove_one`/`remove_many` are two keys for the
            // same reason as `trash.expires.day`/`days`: `t()` does not
            // pluralise.
            'preview' => [
                'over' => 'The account is :over over :free.',
                'remove_one' => 'One attachment would be deleted, newest first.',
                'remove_many' => ':count attachments would be deleted, newest first.',
                'fits' => 'Everything fits within Free.',
                'cleanup_link' => 'Choose yourself what has to go',
            ],
        ],
    ],

    // The storage page, see issue 66b decisions 1–9 and [[Planer och kvoter]]
    // § Nedgradering. **A branch of its own at the top level**, like `plan`
    // and `trash`: the cleanup is step 2 of the downgrade, and the keys live
    // under `storage.*` and nowhere else. No string in a .vue file.
    //
    // Two keys for the same sentence where the number inflects
    // (`preview.one`/`many`, `confirm.one`/`many`, `result.one`/`many`/`none`):
    // `t()` does not pluralise (issue 52 decision 4), so the number picks the
    // key. `none` is a case of its own and not a zero in a plural form — a
    // cleanup where every selected row had already gone is no cleanup, and the
    // sentence should say so rather than count zero files.
    //
    // The wording comes from the document's own: "you know which forty holiday
    // photos can go and which inspection report cannot." The text points at
    // the trash and the 30 days (62a) and never says "deleted permanently" —
    // the attachments are soft-deleted ([[ADR-0008 Soft delete och
    // papperskorg]]).
    'storage' => [
        'title' => 'Storage',
        'heading' => 'Storage',
        'intro' => 'Choose yourself what has to go. The attachments move to the trash and can be restored there within 30 days.',

        'account_label' => 'Account',
        'usage_heading' => 'Storage space',

        'list_heading' => 'Attachments',
        'list_intro' => 'Largest first. Tick what can go — the forty holiday photos can, the inspection report cannot.',
        'empty' => 'The account has no attachments.',

        // Container and item per row, in that order: the context is what makes
        // the choice possible. The separator lives in the sentence and not in
        // the template.
        'row' => [
            'location' => ':container — :item',
            // An attachment whose item or container is in the trash still counts
            // against the account and must be visible (decision 3).
            'trashed' => 'The container or the item is in the trash. The attachment still counts against the account.',
        ],

        // The selection preview (decision 4): computed on the client from
        // `byte_size` of the selected rows. It is a selection and not the
        // usage — the usage after a cleanup comes from the server's answer.
        'preview' => [
            'one' => 'One attachment selected: :freed will be freed and :remaining remains.',
            'many' => ':count attachments selected: :freed will be freed and :remaining remains.',
        ],

        // The ceiling of 100 ULIDs per request (decision 5). The sentence
        // states both the ceiling and what she has selected, so she knows how
        // much has to go.
        'limit_exceeded' => 'You can clear at most :max attachments at a time, and you have selected :count.',

        // The confirmation (decision 6): the number of files, the space
        // freed, the trash and the 30 days.
        'confirm' => [
            'one' => 'One attachment moves to the trash and can be restored there within 30 days. :freed is freed now. Do you want to continue?',
            'many' => ':count attachments move to the trash and can be restored there within 30 days. :freed is freed now. Do you want to continue?',
        ],

        'submit' => 'Move to the trash',

        // The answer after a cleanup (decision 8). `usage` carries the
        // server's usage AFTER the cleanup, formatted with
        // Number::fileSize() — never the client's subtraction.
        'result' => [
            'one' => 'One attachment is in the trash and can be restored there within 30 days.',
            'many' => ':count attachments are in the trash and can be restored there within 30 days.',
            'none' => 'No attachments were removed — they were already gone.',
            'usage' => 'Usage is now :used.',
        ],
    ],

    'container' => [
        // Ingen `kind`-etikett sedan issue 84 · [[ADR-0036 Containerns art]]:
        // arten är ett fritt textfält, och vyn skriver ut strängen användaren
        // matat in. `t()` returnerar nyckeln själv när uppslaget misslyckas, så
        // en etikett per värde hade gett `container.kind.Segelbåt` på skärmen
        // första gången någon skrev en egen art.

        'nav' => [
            // `items` comes first, like the row in containerSections.js: the
            // items are the container, the categories and tags are how it is
            // organised.
            'items' => 'Items',
            'categories' => 'Categories',
            'tags' => 'Tags',
            'sharing' => 'Sharing',
            'settings' => 'Settings',
            // Issue 65b decision 1: the calendar link is a WAY OUT of the
            // product — the container's tasks subscribed to from someone else's
            // calendar — and sits after the settings, before the trash. The row
            // is in the same place in containerSections.js.
            'calendar' => 'Calendar',
            // Issue 67c decision 1: the export is a WAY OUT of the product,
            // like the calendar link — but where the link feeds the container's
            // tasks into someone else's calendar, the export takes the whole
            // container out in a file. The row is in the same place in
            // containerSections.js, and it sits in the NAVIGATION and not
            // behind a setting: the export is free on every plan on purpose.
            'export' => 'Export',
            // Last, like the row in containerSections.js — the trash is where
            // you go when something went wrong (issue 62a decision 1).
            'trash' => 'Trash',
            // After the trash in both the list and the navigation: an
            // ownership transfer is the most consequential action in the
            // product and not something you do often (issue 67b decision 1).
            'transfer' => 'Ownership transfer',
        ],

        // Issue 89 · [[ADR-0039 Containerns översikt]]: the container's own URL
        // answers with an overview and the item list moved to `…/items`. The
        // head carries the name, the kind and the description; the two tiles
        // count what the user HERSELF reaches — never a total, never "of N",
        // never a word about rows kept back ([[ADR-0028 Åtkomst på itemnivå]]
        // § Konsekvenser, issue 73 decision 6).
        //
        // `kind` is the LABEL only. Its value is written out verbatim from
        // `container.kind`: the field is free ([[ADR-0036 Containerns art]]),
        // so no key can be built from it. The label is *Kind* and not the
        // mockup's *Category*, which is taken by the category tree on items —
        // one word, one meaning ([[ADR-0032 Produktens ord]]).
        //
        // `todos` is ONE tile, not two: `schedule` has no field separating a
        // task from maintenance and will not get one ([[ADR-0033 Produktens
        // omfång]]).
        'overview' => [
            'kind' => 'Kind',
            'description' => 'Description',
            'items' => 'Items',
            'todos' => 'Open tasks',
        ],

        'index' => [
            'title' => 'Containers',
            'heading' => 'Containers',
            'create' => 'New container',
            'empty' => 'You have no containers yet.',
            'shared' => 'Shared with you',
            'active' => 'Active',
            'make_active' => 'Make active',
            'edit' => 'Edit',
        ],

        'create' => [
            'title' => 'New container',
            'heading' => 'New container',

            'name' => 'Name',
            'kind' => 'Type',
            // The container's one free-text field beyond the name, see issue 88
            // · [[ADR-0039 Containerns översikt]]. Voluntary, and shown as
            // written: no label asks for a model or a year, because those fields
            // do not exist and must not ([[ADR-0033 Produktens omfång]]).
            'description' => 'Description',
            'account' => 'Account',
            'account_choose' => 'Choose an account',

            'submit' => 'Create',
        ],

        'edit' => [
            'title' => 'Settings',
            'heading' => 'Settings',

            'name' => 'Name',
            'kind' => 'Type',
            'description' => 'Description',

            // The container's own currency, see issue 85 · [[ADR-0037 Valutans
            // arv]]. The field is prefilled with the container's own value and
            // is EMPTY when the container inherits — an empty box is the answer
            // "follow the account", not a missing value, and `currency_hint`
            // names the account's currency instead of saying "the account's":
            // what the container falls back on has to be readable on the page.
            'currency' => 'Currency',
            'currency_hint' => 'Three letters, for example SEK. Leave the field empty and the container uses the account\'s currency (:currency).',

            'submit' => 'Save',
        ],

        // The deletion, see issue 62b decisions 4 and 5. `confirm` carries the
        // container's name: a confirmation that does not say what disappears is a
        // confirmation people click away. It says that everything comes along,
        // that the container stays in the trash for 30 days and that it can be
        // restored from there — and NEVER "deleted permanently", because the
        // deletion is soft (issue 8) and that word would be untrue.
        'destroy' => [
            'action' => 'Delete the container',
            'confirm' => ':name and everything in it moves to the trash. It stays there for 30 days and can be restored from there. Do you want to continue?',
        ],

        // The category tree, see issue 56a decisions 1, 2, 3 and 4.
        'categories' => [
            'title' => 'Categories',
            'heading' => 'Categories',
            'description' => 'Where things belong. An item sits in at most one category, and the categories form a tree of at most five levels.',

            'name' => 'Name',
            'parent' => 'Parent category',
            'parent_root' => '— none, put it at the root —',
            'position' => 'Position',

            'create_heading' => 'New category',
            'create' => 'Create',
            'save' => 'Save',
            'destroy' => 'Delete',
            'empty' => 'No categories yet.',

            // The suggestion on an empty container, see issue 56b decision 4. The
            // words in the set itself are NOT here and never will be: they live
            // in resources/js/data/categoryPresets.js, per language. Since issue
            // 84 the sets hang off no container field at all — the user picks
            // one, and `preset_choose`/`preset_pick` are the picker's own words.
            // `preset_not_empty` is the route's answer on a container that already
            // has categories and lands on the `categories` form key — a
            // sentence, not an API error code, since the route is web-only.
            'preset_heading' => 'Ready-made set',
            'preset_description' => 'We can fill the container with a ready-made suggestion of categories. You can rename, move and delete them just like any other category afterwards.',
            'preset_choose' => 'Choose a set',
            'preset_pick' => '— pick one —',
            'preset_apply' => 'Add the set',
            'preset_dismiss' => 'No thanks',
            'preset_not_empty' => 'The container already has categories. A set can only be added to an empty container.',
        ],

        // The tag list, see issue 56a decisions 6 and 8.
        'tags' => [
            'title' => 'Tags',
            'heading' => 'Tags',
            'description' => 'Everything else you want to filter on. A tag is flat, sits alongside the category, and an item can carry any number of them.',

            'name' => 'Name',
            'color' => 'Colour',
            'color_placeholder' => '#rrggbb',
            // The colour is optional and `null` is an answer — no default
            // colour is chosen for the user (decision 8).
            'no_color' => 'No colour',
            'item_count' => 'On :count items',

            'create_heading' => 'New tag',
            'create' => 'Create',
            'save' => 'Save',
            'destroy' => 'Delete',
            'empty' => 'No tags yet.',
        ],
    ],

    // The item pages, see issue 57a decision 10. Same split as `container`:
    // `index` is the list, `show` is the detail view.
    'item' => [
        // The filter bar lives in resources/js/components/ItemFilterBar.vue
        // and the empty-result sentence in pages/Containers/Items/Index.vue,
        // see issue 59a decisions 4, 5, 6 and 8. `filter_empty` lists what the
        // user set THEMSELVES and nothing else: no number of rows the scope
        // kept back, no hint that the answer would be incomplete. A
        // scope-limited recipient therefore gets the same sentence as the
        // owner.
        //
        // The `filter_label_*` labels are built in
        // resources/js/components/itemFilter.js and used in two places: the
        // chips above the list and the sentence above. Tags have their own
        // form for the listing (`filter_label_tags`), so "the tags Motor,
        // Impeller" rather than "the tag Motor, the tag Impeller".
        'index' => [
            'title' => 'Items',
            'heading' => 'Items',
            'create' => 'New item',
            'empty' => 'The container is empty.',

            // The status of a row, see issue 92 and
            // App\Support\Item\ItemStatus. `status_ok` says that nothing on
            // the item or below it has fallen due; `status_overdue` says that
            // something has — never WHAT or WHERE, because which descendant
            // carries it is the detail view's answer. The words are the
            // mockup's own, and they live here rather than in the .vue file.
            'status_ok' => 'OK',
            'status_overdue' => 'Overdue',

            'filter_heading' => 'Filter',
            'filter_q' => 'Search term',
            'filter_tags' => 'Tags',
            'filter_category' => 'Category',
            'filter_category_all' => '— all categories —',
            'filter_submit' => 'Filter',
            'filter_active' => 'Active filters',
            'filter_clear' => 'Clear all',
            'filter_remove' => 'Remove :filter',

            // A value in the link that is no longer in the recipient's scope —
            // a deleted tag, a category moved to another container. The row is
            // the whole answer: no 422, no redirect back to the same query
            // string (decision 3).
            'filter_dropped' => 'A filter in the link no longer exists and has been removed.',

            // The "filter, no rows" state. Without a filter `empty` says the
            // container is empty instead.
            'filter_empty' => 'No hits with these filters: :filters.',

            'filter_label_q' => 'the search term “:value”',
            'filter_label_tag' => 'the tag :name',
            'filter_label_tags' => 'the tags :names',
            'filter_label_category' => 'the category :name',
        ],

        'show' => [
            'description' => 'Description',
            // Issue 96. `description` says what the item IS, `notes` what the
            // user KNOWS about it — two rows, never one merged text.
            'notes' => 'Notes',
            'manufacturer' => 'Manufacturer',
            'model' => 'Model',
            'serial_number' => 'Serial number',
            'purchased_at' => 'Purchase date',
            'warranty_until' => 'Warranty until',
            'position_note' => 'Location',
            'category' => 'Category',
            'tags' => 'Tags',

            // The paths from a root down to the item — the mockup's
            // *Förekomster i struktur*, see issue 95 and [[ADR-0041 Itemets
            // vy]]. The word is *placement*, and never *occurrence*:
            // `occurrence` is the system's word for `schedule_occurrence`
            // ([[ADR-0005 Schema och förekomst]]) and runs through a score of
            // keys about tasks above. One English word cannot mean both a
            // task's occurrence and a spot in the structure — that is what
            // [[ADR-0032 Produktens ord]] exists to prevent. *Location* is
            // taken as well: `position_note` above reads 'Location', and it
            // answers a different question.
            //
            // `placements` heads the list, and the list points at that heading
            // with `aria-labelledby` — so the list needs no `aria-label` of its
            // own. `breadcrumb` is the `aria-label` of the breadcrumb's <nav>.
            // `placement_current` is the VISIBLE marker on the current row: a
            // word and never a colour alone, with `aria-current` on the row
            // beside it. The label is not a counter — it says which of the
            // paths the recipient SEES is the current one, and nothing about
            // the ones she does not (issue 73 decision 6).
            'placements' => 'Placements in the structure',
            'placement_current' => 'Current',
            'breadcrumb' => 'Where this item sits',
        ],

        // The form's field labels, see issue 57b decision 9. The product's
        // words and not the column names: *Purchased* and *Warranty until* is
        // what the field asks, unlike the detail view's summarising *Purchase
        // date*. Hence its own keys rather than reusing `show.*`.
        'form' => [
            'name' => 'Name',
            'description' => 'Description',
            // Issue 96. The same distinction as `item.show.notes` above.
            'notes' => 'Notes',
            'manufacturer' => 'Manufacturer',
            'model' => 'Model',
            'serial_number' => 'Serial number',
            'purchased_at' => 'Purchased',
            'warranty_until' => 'Warranty until',
            'position_note' => 'Where it is',

            // An item sits in AT MOST one category ([[ADR-0004 Fria taggar
            // och kategorier]]). The top row of the selector is a choice, not
            // an empty field.
            'category' => 'Category',
            'category_none' => '— no category —',
            'categories_empty' => 'The container has no categories yet.',
            'categories_empty_link' => 'Create categories',

            // The tags are checkboxes, one per tag in the container. A new tag is
            // created on the tags page and not here: one way to the same write
            // in two places is two rules to keep in step (decision 5).
            'tags' => 'Tags',
            'tags_empty' => 'The container has no tags yet.',
            'tags_empty_link' => 'Create tags',

            // The account the row is attributed to — the yard, not the
            // employee. Only on creation: who created the row is history
            // (decision 4).
            'account' => 'Account',

            // The parent, when the child item is created from the detail
            // view's link (issue 58 decision 7). A line of text and not a
            // selector: the link has already answered the question, and
            // `parent` is only sent on creation.
            'parent' => 'Created under: :name',

            // The cover image (issue 93, [[ADR-0041 Itemets vy]]). One
            // selector over the item's own images, and only in the edit form:
            // an item that does not exist yet has no attachments to choose
            // from. `cover_none` is the top row and it CLEARS the choice —
            // with no choice made, App\Actions\Item\ResolveItemCover falls
            // back to the item's oldest image, so the row says that no cover
            // has been CHOSEN and not that the item is left without one. The
            // name mirrors `category_none` above, the house's word for an
            // unmade choice.
            'cover' => 'Cover image',
            'cover_none' => '— no cover image —',
        ],

        'create' => [
            'title' => 'New item',
            'heading' => 'New item',
            'submit' => 'Create',
        ],

        // `action` is the link on the detail view; `title`/`heading`/`submit`
        // are the page and the form.
        'edit' => [
            'action' => 'Edit',
            'title' => 'Edit item',
            'heading' => 'Edit item',
            'submit' => 'Save',
        ],

        // The deletion is SOFT ([[ADR-0008 Soft delete och papperskorg]]), and
        // `confirm` says so: the trash and the 30 days, never "deleted
        // permanently", which would be untrue (decision 8).
        'destroy' => [
            'action' => 'Delete',
            'confirm' => 'The item goes to the trash and can be restored within 30 days. Continue?',
        ],

        // The relation section, see issue 58 decisions 3, 4, 8, 9 and 10. It
        // lives in resources/js/components/ItemLinkSection.vue.
        //
        // `group` is the three headings and `relation` the labels in the
        // direction selector — both seen from the COUNTERPART's side
        // (decision 4), the same way the list from the server reads. A
        // counterpart outside the scope has no key at all: it is not drawn
        // (decision 3), and a line describing something hidden would be the
        // leak itself.
        'links' => [
            'heading' => 'Relations',
            'description' => 'What this item belongs to, and what belongs to it.',

            'group' => [
                'parent' => 'Parent items',
                'child' => 'Child items',
                'related' => 'Related items',
            ],

            'empty' => 'The item is not linked to anything.',
            'remove' => 'Unlink',
            // Deleting a link is hard (issue 14 decision 10) and has no
            // trash — what disappears is the connection, never the items.
            'remove_confirm' => 'Only the link is removed. Both items remain. Continue?',

            // The way to the child item (decision 7).
            'create_child' => [
                'action' => 'New item under this one',
            ],

            'form_heading' => 'Link to another item',
            'counterpart' => 'Item',
            'counterpart_none' => '— choose an item —',
            'no_counterparts' => 'There are no other items to link to.',

            'relation' => [
                'label' => 'The counterpart is',
                'none' => '— choose a direction —',
                'parent' => 'A parent item',
                'child' => 'A child item',
                'related' => 'A related item',
            ],

            // Decision 8: what a direction does to the sharing, in one
            // sentence. No computation — no question about which grants
            // exist and no counter. That number belongs to the sharing view
            // (55a), and a second truth about the scope is one that can
            // drift apart.
            'relation_note' => 'Sharing a parent item also reaches its child items — related items share nothing.',

            'submit' => 'Link',
        ],

        // The attachment section on the detail view, see issue 60. It lives in
        // resources/js/components/ItemAttachmentSection.vue: it carries its own
        // form and its own errors, just as ItemLinkSection does for the
        // relations, so a quota error on a file does not colour the rest of
        // the page.
        //
        // The file's `kind` (`image` | `document` | `other`) is a domain value
        // and not text — the words below are its three values, the same keys
        // AttachmentResource carries for /api.
        //
        // `billing_note` says WHICH account pays before the file is chosen:
        // the quota is counted on the uploading account and not on the container
        // owner ([[Filer och lagring]] § attachment, AGENTS.md § Sådant som är
        // lätt att göra fel), and whoever uploads should know what it costs.
        //
        // `destroy_confirm` says the trash and the 30 days. The deletion is
        // soft (decision 7), and "deleted permanently" would be untrue.
        //
        // The queue's own keys came with issue 60b: `upload_heading` and `file`
        // became plural when one file became several (decision 1),
        // `dropzone` is the drop target's text, `status` holds the queue's four
        // states, `summary` counts what arrived, `throttled` is the rate
        // limit's own sentence (decision 7 — the server answers a throttled
        // upload with the login sentence on `email`, which would be a lie
        // here), and `dismiss` closes a failed row (decision 4).
        'attachment' => [
            'heading' => 'Attachments',
            'empty' => 'The item has no attachments.',

            'kind' => [
                'image' => 'Image',
                'document' => 'Document',
                'other' => 'Other',
            ],

            'download' => 'Download',
            'destroy' => 'Remove',
            'destroy_confirm' => 'The attachment moves to the trash and can be restored within 30 days. Continue?',

            // Issue 61b decision 7: the four strings of the inline view. `alt`
            // is not among them — it is the filename and comes from the data.
            //
            // `file_icon` labels the neutral file icon drawn in place of a
            // thumbnail (decision 1): an attachment without derivatives, such
            // as a freshly uploaded image or a PDF, must never become a broken
            // image. It says what the reader sees, not what the file is — the
            // filename sits next to it.
            //
            // `pdf_fallback` stands under the PDF frame (decision 4). The view
            // cannot know whether the browser has a reader of its own, so the
            // sentence is there the whole time and points at the download link
            // every row has anyway (decision 5).
            'viewer_heading' => 'Image viewer',
            'viewer_close' => 'Close',
            'file_icon' => 'The file is shown as an icon',
            'pdf_fallback' => 'Cannot display the PDF? Download it instead.',

            'upload_heading' => 'Upload files',
            'billing_note' => 'Storage is charged to the account below, not to the container owner.',
            'account' => 'The account that pays',
            'file' => 'Files',
            'dropzone' => 'Drop the files here',

            'status' => [
                'waiting' => 'Waiting',
                'uploading' => 'Uploading',
                'done' => 'Done',
                'failed' => 'Failed',
            ],

            // "Files uploaded: 3 of 4" rather than "3 of 4 files were
            // uploaded" — the noun and the verb inflect in the singular, and
            // translate.js has no pluralisation by design (issue 52 § Beslut
            // 4). A single-file queue is the most common flow there is.
            'summary' => 'Files uploaded: :uploaded of :total.',
            'throttled' => 'Too many uploads. Wait a moment and continue.',
            'interrupted' => 'The connection dropped. Check the list and try again.',
            'dismiss' => 'Dismiss',

            'submit' => 'Upload',
        ],

        // The loan section on the detail view, see issue 67a decisions 2–8. The
        // section lives in resources/js/components/ItemLoanSection.vue: it
        // carries its own form and its own errors, just as ItemLinkSection does
        // for the relations and ItemAttachmentSection for the attachments, so a
        // field error on a date does not colour the rest of the page.
        //
        // `borrowed_by`, `lent_at`, `due_at` and `returned_at` are whole
        // sentences built from a date and a name — the template joins them, and
        // the date is already formatted by formatDateOnly() (decisions 3, 5).
        'loan' => [
            'heading' => 'Loans',
            'description' => 'Who has the thing, and when it is due back.',

            // The open loan sits on top and the history below (decision 2).
            // `returned_at IS NULL` is the open one, and which row that is
            // arrives pre-computed from the server.
            'not_lent' => 'The item is not lent out.',

            'borrowed_by' => 'Lent to :name',
            'lent_at' => 'Lent out :date',
            'due_at' => 'Due back :date',
            'no_due_at' => 'No return date set',

            // Overdue is derived on the server's date (decision 5). The view
            // never compares `due_at` against its own clock — it reads the
            // `openLoanOverdue` flag from the response.
            'overdue' => 'Overdue',

            // The address is a contact detail and never a recipient address
            // (decision 4, [[ADR-0017 Missbruksvektorer]] § 7). It is shown as text
            // with the option to copy, and `email_note` says why the field
            // exists: the system never emails the borrower, the reminder goes
            // to the lender. No `mailto:` link, no reminder button and no
            // sharing — the sentence is the whole answer to why the address is
            // there.
            'contact' => 'Contact details',
            'copy' => 'Copy the address',
            'copied' => 'The address is copied',
            'email_note' => 'The address is never used for mailings. The system does not email the borrower — the reminder goes to you.',

            // A return is a button and not a date field you have to
            // understand (decision 3). The button sets today's date — the
            // server's, from the `today` prop — and the custom date lives in
            // the form next to it. `after_or_equal:lent_at` applies to both
            // paths, so a date before the loan becomes a field error.
            'return_today' => 'Back today',
            'return_date' => 'Return date',
            'return_submit' => 'Register',

            'history_heading' => 'Earlier loans',
            'returned_at' => 'Back :date',

            // Removing the row is NOT returning (decision 7). One erases a
            // mistaken registration, the other records that the thing came
            // back — and `destroy_confirm` says both, so the two buttons cannot
            // be confused. The deletion is soft and the row does not enter the
            // trash (issue 76 decision 3), so the sentence promises no restore.
            'destroy' => 'Remove the row',
            'destroy_confirm' => 'The row is removed. This is not a return — the thing is still lent out, and the return is registered with the other button. Continue?',

            // The form. `lent_at` defaults to today's date (decision 3), and
            // the four dates are `<input type="date">`: the browser sends
            // `Y-m-d`, exactly what the `date` rule in the shared FormRequest
            // accepts — no date parsing of our own in JavaScript.
            'form_heading' => 'Lend out',
            'form_name' => 'Lent to',
            'form_email' => 'Email address',
            'form_lent_at' => 'Lent out',
            'form_due_at' => 'Due back',
            'form_returned_at' => 'Returned',
            'form_note' => 'Note',
            'form_submit' => 'Lend out',
        ],

        // The schedule as a rule — the section on the detail view and the two
        // form pages, see issue 63a decisions 2–8. The section lives in
        // resources/js/components/ScheduleListSection.vue and the form in
        // ScheduleForm.vue; the sentences come from this file and never from a
        // string in JavaScript.
        //
        // The recurrence is one sentence built from three columns (decision
        // 2). `t()` has no pluralisation (issue 52 decision 4), so every unit
        // has TWO keys — one for `1` and one for `:count` — and
        // resources/js/components/schedulePresentation.js picks by the number,
        // the same rule as the days in 62a.
        'schedule' => [
            'heading' => 'Schedules',
            'empty' => 'The item has no schedules.',
            'add' => 'New schedule',
            'back' => 'Back to the item',

            // The next due date is the date of the OPEN occurrence (decision
            // 1). A schedule without an open occurrence — a paused one, or a
            // one-off already done — says so instead of showing an empty
            // field.
            'next_due' => 'Next due: :date',
            'no_next_due' => 'No open occurrence.',

            'edit' => 'Edit',
            'pause' => 'Pause',
            'resume' => 'Resume',

            // The pause is reversible and visible (decision 6): the row stays
            // in the list, greyed out, with this sentence.
            'paused' => 'Paused',
            'paused_note' => 'The schedule opens no new occurrences while it is paused.',

            // The deletion is soft, but the trash lists four types and
            // `schedule` is not one of them (issue 20a decision 3). The text
            // therefore says what goes away and mentions neither 30 days nor
            // the trash — promising a way back that does not exist is worse
            // than promising none (decision 8).
            'destroy' => 'Delete',
            'destroy_confirm' => 'The schedule and its upcoming occurrences are removed. Continue?',

            'recurrence' => [
                'none' => 'Once',

                'fixed' => [
                    'day' => 'Every day according to the calendar',
                    'day_count' => 'Every :count days according to the calendar',
                    'week' => 'Every week according to the calendar',
                    'week_count' => 'Every :count weeks according to the calendar',
                    'month' => 'Every month according to the calendar',
                    'month_count' => 'Every :count months according to the calendar',
                    'year' => 'Every year according to the calendar',
                    'year_count' => 'Every :count years according to the calendar',
                ],

                'interval' => [
                    'day' => 'Every day, counted from last done',
                    'day_count' => 'Every :count days, counted from last done',
                    'week' => 'Every week, counted from last done',
                    'week_count' => 'Every :count weeks, counted from last done',
                    'month' => 'Every month, counted from last done',
                    'month_count' => 'Every :count months, counted from last done',
                    'year' => 'Every year, counted from last done',
                    'year_count' => 'Every :count years, counted from last done',
                ],
            ],

            'form' => [
                'title' => 'Title',
                'notes' => 'Notes',
                'recurrence_type' => 'Repeats',

                'type' => [
                    'none' => 'Once',
                    'fixed' => 'Fixed date on the calendar',
                    'interval' => 'Interval from last done',
                ],

                'recurrence_none' => 'Once. The task disappears when it is done.',
                'recurrence_fixed' => 'Repeats on the calendar. The insurance renews on 1 January even if you paid late.',
                'recurrence_interval' => 'Counted from last done. A service twelve months after the previous one.',

                // The units are singular: they combine with a count, and the
                // sentence above inflects the word by the number (decision 2).
                'unit' => 'Unit',
                'units' => [
                    'day' => 'day',
                    'week' => 'week',
                    'month' => 'month',
                    'year' => 'year',
                ],
                'unit_none' => '— pick a unit —',
                'interval_count' => 'Count',

                // `anchor_date` is asked for ALL three types (decision 4):
                // StoreScheduleRequest requires it for `interval` and `none`
                // too, where it is the start of the series and the first due
                // date. Only the label changes — a required field that looks
                // optional is a 422 the user does not understand.
                'anchor_date' => 'First due date',
                'anchor_date_fixed' => 'Start of the series',

                // `lead_days` is explained by what it DOES (decision 5): it is
                // `visible_from`, and without the sentence the field is
                // incomprehensible.
                'lead_days' => 'Days before due',
                'lead_days_hint' => 'The task shows up in the to-do list this many days before it is due.',
            ],

            'create' => [
                'title' => 'New schedule',
                'heading' => 'New schedule',
                'submit' => 'Create',
            ],

            'update' => [
                'title' => 'Edit schedule',
                'heading' => 'Edit schedule',
                'submit' => 'Save',
            ],

            // The occurrence — the single time, see issue 63b decisions 2–10.
            // The sentences are used on BOTH surfaces: the section on the item
            // (resources/js/components/ScheduleListSection.vue) and the
            // schedule's own page
            // (resources/js/pages/Containers/Items/Schedules/Show.vue), which
            // share the form resources/js/components/OpenOccurrence.vue.
            //
            // Three dates in the right role (decision 2): `due` is the due
            // date, `visible_from` is when the task appeared, and `window` is
            // the time you have — the difference between them. All are DATE
            // columns and are formatted by formatDateOnly(), never converted
            // to another time zone.
            //
            // Overdue is a derived state (decision 3): the word below is drawn
            // only when the server's `overdue` is true.
            'occurrence' => [
                'heading' => 'Open occurrence',
                'none' => 'No open occurrence.',
                'done' => 'The task is done.',

                'view' => 'Occurrences',

                'due' => 'Due :date',
                'visible_from' => 'Visible since :date',
                'window' => ':days days to spare',
                'window_one' => '1 day to spare',

                'overdue' => 'Overdue',

                'account' => 'Account',
                'account_hint' => 'The account that goes in the log. The organisation, not the person.',

                'note' => 'Note',
                'note_hint' => 'Optional. Saved in the history.',

                'complete' => 'Check off',
                'skip' => 'Skip',

                'skip_confirm' => 'The task is closed as skipped, not as done, and the next occurrence opens just as it would after a check-off. Continue?',

                'history' => 'History',
                'history_empty' => 'No finished occurrences yet.',

                'status' => [
                    'open' => 'Open',
                    'completed' => 'Done',
                    'skipped' => 'Skipped',
                ],

                'completed_at' => 'Closed :date',
                'completed_by' => 'by :name',
            ],

            // The dependencies, see issue 63c decisions 2, 3, 4, 5, 7 and 9.
            // The section lives in
            // resources/js/components/ScheduleDependencySection.vue and is
            // drawn TWICE on the schedule's page, once per level.
            //
            // Two levels, two headings (decision 2): `heading_schedule` is the
            // RULE inherited by every new occurrence, `heading_occurrence` is
            // the EXCEPTION that applies to this occurrence only ([[ADR-0005
            // Schema och förekomst]]). The difference is in the words, not in a
            // type column.
            //
            // `note_schedule` is the inheritance sentence: without it a rule
            // looks like a one-off choice.
            'dependency' => [
                'heading_schedule' => 'Always waits for',
                'heading_occurrence' => 'Waiting on this time',

                'note_schedule' => 'A rule for this schedule. Every new occurrence is linked automatically to the counterpart’s then-open occurrence.',
                'note_occurrence' => 'An exception that applies to this occurrence only.',

                'empty_schedule' => 'The schedule is not waiting for anything.',
                'empty_occurrence' => 'The occurrence is not waiting for anything.',
                'occurrence_none' => 'No open occurrence, so there are no exceptions to show.',

                'counterpart' => 'Counterpart',
                'counterpart_none' => '— choose a schedule —',
                'no_counterparts' => 'There are no other schedules to wait for.',

                'submit' => 'Add',
                'remove' => 'Remove',
                'remove_confirm_schedule' => 'The dependency is removed. Both schedules remain. Continue?',
                'remove_confirm_occurrence' => 'The exception is removed. Both schedules and both occurrences remain. Continue?',

                'satisfied' => 'Done',
                'blocking' => 'Blocking',
            ],
        ],
    ],

    // The global search, see issue 59b decisions 4, 6, 7 and 8. The page lives
    // in resources/js/pages/Search.vue, the field in
    // resources/js/components/SearchField.vue — the field sits in the SHARED
    // layout and therefore shows on every signed-in page (decision 5).
    //
    // `empty` names the search term and stops there (decision 6): no number of
    // rows that existed, no hint that something was held back, no listing of
    // which containers were searched — which containers at all is information in
    // itself. A user with access to nothing gets word for word the same
    // sentence as a user whose term matches nothing, because the sentence
    // knows nothing about scope.
    //
    // `intro` and `match_rule` are the starting state, i.e. the state where
    // no query ran at all (decisions 4 and 7). The last line says what the
    // query actually does: the driver formulates `LIKE '%term%'`, so a
    // substring in the middle of a word hits (issue 78 decision 5). It
    // therefore does NOT describe the FULLTEXT branch of [[ADR-0012 Sök]],
    // which is switched off until CI runs against MariaDB. No compensation is
    // built in the view in either direction — no stemming in JavaScript, no
    // second search on a truncated word. One line is the whole answer. The key
    // was named `whole_words` until issue 78: the name carried the claim, and
    // the claim was false.
    //
    // `in_container` is the prefix before the container name, and only the
    // prefix: the name is its own link to the container's front page (decision
    // 3), so the words cannot live in one string.
    'search' => [
        'title' => 'Search',
        'heading' => 'Search',

        'intro' => 'Searches name, description, manufacturer, model and serial number — in every container you can reach.',
        'match_rule' => 'The search matches part of a word: “battery” also finds “battery charger”.',
        'empty' => 'No hits for “:q”.',
        'in_container' => 'in',

        'field' => [
            'label' => 'Search all containers',
            'placeholder' => 'Search term',
            'submit' => 'Search',
        ],
    ],

    // The to-do view, see issue 64. The landing page after sign-in: the open
    // occurrences across every container the user can reach.
    //
    // The two empty sentences differ on purpose (decision 6): one says the
    // user has no container at all and carries a link to create one, the other
    // that there is nothing to do. Neither mentions a number or hints that
    // anything was hidden — a scope-limited recipient with an empty list gets
    // the exact same sentence as an owner whose tasks are done.
    'todo' => [
        'title' => 'To do',
        'heading' => 'To do',

        'due' => 'Due :date',
        'complete' => 'Check off',

        // The section headings. The key is the group's name, the same three
        // words the controller sorts the rows into — no separate list in
        // JavaScript that could drift from the server's.
        'group' => [
            'overdue' => 'Overdue',
            'today' => 'Today',
            'upcoming' => 'Upcoming',
        ],

        'empty' => [
            'no_containers' => 'You have no containers yet.',
            'create' => 'Create a container',
            'nothing' => 'Nothing to do right now.',
        ],
    ],

    // The sharing page, see issue 55a. Two sections with different audiences
    // (decision 3), and the texts follow that split.
    // The container's export page, see issue 67c decisions 1, 5, 6, 7 and 8.
    //
    // **`status` holds the column values from App\Models\Export::STATUSES**,
    // never invented names of our own — same rule as attachment.kind. `pending`
    // and `running` deliberately share a sentence: to someone waiting they are
    // the same thing — the bag is being packed — and the job passes through
    // both on its way to `ready`. `expired` is also carried by a `ready` row
    // whose `expires_at` has passed before the purge has set the column, see
    // exportStatus() in resources/js/components/exportPresentation.js.
    'export' => [
        'title' => 'Export',
        'heading' => 'Export',

        // What the bag contains, BEFORE it is ordered (decision 7). The
        // sentence says what the export covers and what it is NOT — a bag you
        // fetch, not an archive you keep (config/files.php) — so that whoever
        // hoped for something else does not wait in vain. That it is free on
        // every plan is stated here and not only in a plan overview: it is the
        // product promise, and whoever looks for a gate should see that there
        // is none.
        'intro' => 'The export gathers the whole container in a ZIP file: items with categories and tags, the attachments and the history. It is free on every plan, and the file is a bag you fetch — not an archive you keep.',
        'create' => 'Order an export',

        // Why the button is closed, in words (decision 4). A disabled button
        // without an explanation is a dead end, and this sentence says the
        // same thing as the error below: an export is being packed, the row is
        // in the list.
        'running_notice' => 'An export is already being packed. It is in the list below, and the button opens when it is done.',

        'list_heading' => 'Ordered exports',
        'empty' => 'No export has been ordered yet.',

        // `:date` is formatted on the client (formatDate), the words around it
        // here.
        'created_at' => 'Ordered :date',

        // `:size` is formatted on the client (formatByteSize, which mirrors the
        // server's Number::fileSize()) and the row is not drawn at all when
        // `byte_size` is null — never as "0 B" (decision 6).
        'size' => 'Size: :size',

        'download' => 'Download',

        'status' => [
            'pending' => 'The bag is being packed',
            'running' => 'The bag is being packed',
            'ready' => 'Ready to fetch',
            'failed' => 'Failed',
            'expired' => 'Expired',
        ],

        // The same two plural keys as the trash (issue 62a decision 4), with
        // the export's own words (decision 8: the keys live under `export.*`).
        // The thresholds are the same: less than a day is "today" and never
        // "0 days", exactly one day is singular.
        'expires' => [
            'today' => 'Purged today',
            'day' => '1 day left',
            'days' => ':days days left',
        ],
    ],

    'sharing' => [
        'title' => 'Sharing',
        'heading' => 'Sharing',

        'participants' => [
            'heading' => 'Participants',
            'description' => 'Everyone with access to the container right now. An account counts as one participant, never as its members.',
        ],

        'role' => [
            'owner' => 'Owner',
            'member' => 'Member',
            'managed' => 'Organisation',
            'guest' => 'Guest',
        ],

        'accesses' => [
            'heading' => 'Accesses',
            'description' => 'Everything shared from the container, and the history of what has been revoked or expired.',

            // No level may delete the container, manage accesses or start a
            // transfer of ownership. Stated once on the page, not per row.
            'limits' => 'No access grants the right to delete the container, manage accesses or start a transfer of ownership. That is always the owner account.',

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
            'item_container' => 'The whole container',

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
            'container' => 'The whole container',
            'item' => ':item reaches :reach items',
        ],

        'kind' => [
            'member' => 'A person — a partner or a co-owner.',
            'managed' => 'An organisation with a service relationship — a workshop, a contractor or an agent. It does not own the container, and what it creates is attributed to the organisation.',
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
                'description' => 'Also changes what is already in the container.',
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

        // The container's name and the inviter's name are shown to a guest too.
        // That is not new information: InvitationNotification prints the
        // container's name in both the subject line and the body, and whoever has
        // the link has received the email. The address the invitation is for
        // is never shown.
        'intro' => ':inviter has invited you to the container :container.',
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

    // The ownership transfer, see issue 67b decisions 1–9 and [[Konton och
    // åtkomst]] § ownership_transfer. A branch of its own on the top level
    // rather than under `container`: the recipient's inbox sits OUTSIDE the
    // container — she does not have it yet — and the sender's page is the other
    // half of the same conversation. Same reason that makes `sharing` and
    // `trash` branches of their own.
    //
    // Two pages in one branch: `form`/`excluded`/`retain`/`row`/`status`
    // belong to the container's page, `inbox`/`card` to the recipient's.
    // `accept` and `reject` are shared and sit outermost.
    'transfer' => [
        'title' => 'Ownership transfer',
        'heading' => 'Ownership transfer',
        'intro' => 'The whole container changes accounts. It covers the same thing whether a workshop hands over to a customer, an agency to a new owner, or something you own is sold on.',

        // The link sits beside the plan box and not inside the sentence: the
        // string is also delivered by the /api error envelope, and markup in a
        // translation string becomes escaped text or `v-html` somewhere.
        'plan_link' => 'Read about the plans',

        'form' => [
            'heading' => 'Hand over the container',

            // What the transfer covers, before it is sent (decision 2). Four
            // things in one sentence, and they are the four that actually
            // happen.
            'notice' => 'All items follow along except the ones you exclude below. The space the container uses moves to the recipient\'s account, accesses and open invitations are revoked — the new owner invites whomever she wants — and the recipient gets twelve months of Pro on their first received ownership transfer.',

            // Two paths, exactly one to be filled in. The rule is tried by
            // `prohibits` in StoreOwnershipTransferRequest and is not
            // reworded here.
            'recipient_heading' => 'Recipient',
            'recipient_help' => 'Fill in one of the fields: the recipient\'s account ULID, or the address she has or creates an account with.',
            'account' => 'Account ULID',
            'email' => 'Email address',

            'retain' => 'Your access afterwards',
            'submit' => 'Send the transfer',
        ],

        // The exclusions are exceptions, not a selection of what is sent
        // (decision 2). `following` writes out what actually happens as the
        // user ticks — "everything else follows along" is a rule, ":following
        // of :total follow along" is the answer.
        'excluded' => [
            'heading' => 'What you keep',
            'help' => 'Tick what you want to keep — the purchase price, the insurance letter. Everything else follows along to the recipient.',
            'following' => ':following of :total follow along, :excluded excluded.',
        ],

        // The levels the request allows are `read` and `write`; the names come
        // from `sharing.level.*`, the same ladder and the same words. The key
        // here is the third choice: no access at all.
        'retain' => [
            'none' => 'No access',
        ],

        'open' => [
            'heading' => 'In progress',
            'empty' => 'No transfer is waiting for an answer.',
        ],

        'history' => [
            'heading' => 'Earlier',
        ],

        'row' => [
            'created' => 'Sent :date',
            'expires' => 'Expires :date',
            'accepted' => 'Accepted :date',

            'excluded' => ':count items excluded',
            'excluded_none' => 'No excluded items',

            'retain' => 'The sender keeps access: :level',
            'retain_none' => 'The sender keeps no access',

            'revoke' => 'Withdraw',
        ],

        // The status comes from App\Http\Resources\OwnershipTransferResource
        // and is never read from the column: a `pending` row that has passed
        // its lifetime is reported as `expired` without the row changing (39a
        // decision 10). The keys are the column values from
        // App\Models\OwnershipTransfer::STATUSES.
        'status' => [
            'pending' => 'Waiting for an answer',
            'expired' => 'Expired',
            'accepted' => 'Accepted',
            'rejected' => 'Declined',
            'revoked' => 'Withdrawn',
        ],

        // The recipient's inbox. The heading says whose they are — the page
        // lists only her own, by identity and never by a link (decision 5).
        'inbox' => [
            'title' => 'Ownership transfers to you',
            'heading' => 'Ownership transfers to you',
            'intro' => 'Containers someone wants to hand over to you. You find them here when signed in, whether or not the email is still around.',
            'empty' => 'No ownership transfers are waiting for you.',
        ],

        // The consequences stand before the button (decision 6): which container,
        // from which account, how many items follow along and are excluded,
        // which access the sender keeps, and the twelve months of Pro.
        'card' => [
            'container' => 'The container :container',
            'from' => 'From the account :account',
            'items' => ':following of :total follow along, :excluded excluded.',
            'retain' => 'The sender keeps access: :level.',
            'retain_none' => 'The sender keeps no access.',
            'pro' => 'Twelve months of Pro are included — once per account, on the first ownership transfer you receive.',

            // What happens to the space afterwards, and who owns it: whoever
            // receives the container takes over the usage too, and it is her
            // quota that applies from then on.
            'quota' => 'The space the container uses moves to your account, and it is your quota that applies afterwards.',

            'account' => 'Which account is taking over?',

            // Both decisions are final (decision 7), and it is said before the
            // buttons and once more in the confirmation dialog.
            'final' => 'The decision cannot be undone. If you change your mind the sender must send a new transfer.',
            'accept_confirm' => 'Take over the container? The decision cannot be undone.',
            'reject_confirm' => 'Decline? The decision cannot be undone.',
        ],

        'accept' => 'Accept',
        'reject' => 'Decline',
    ],

    // The container's trash, see issue 62a decisions 4, 5 and 9 and [[ADR-0008
    // Soft delete och papperskorg]] § Retentionstiden i MVP. A branch of its
    // own on the top level rather than under `container`: the trash is its own
    // surface with its own vocabulary, like `sharing`.
    //
    // **Three keys for the remaining time, not one.** `t()` has no
    // pluralisation (issue 52 decision 4), so the view picks on the number:
    // the last day says `expires.today` and never "0 days", one day left says
    // `expires.day` in the singular, and the rest `expires.days`.
    //
    // **`empty` says the trash is empty and nothing else** (decision 5, issue
    // 74 decision 1 and issue 73 decision 6): no row counts rows, and a
    // scope-restricted recipient gets exactly the same sentence as the owner.
    // Expired content does not exist either — the view never says something
    // disappeared.
    'trash' => [
        'title' => 'Trash',
        'heading' => 'Trash',
        'description' => 'What has been deleted in the container. After 30 days it is removed for good.',
        'empty' => 'The trash is empty.',

        // `:date` is formatted on the client (formatDate), the words around it
        // here.
        'deleted_at' => 'Deleted :date',

        // The keys are the `type` values from RestoreRequest::TYPES, never
        // invented names of our own — same rule as attachment.kind.
        'type' => [
            'item' => 'Item',
            'attachment' => 'Attachment',
            'category' => 'Category',
            'tag' => 'Tag',
            // Came with issue 62b: a deleted container carries the same key out
            // of TrashEntryResource, and the row is the same component in both
            // lists.
            'container' => 'Container',
        ],

        'expires' => [
            'today' => 'Disappears today',
            'day' => '1 day left',
            'days' => ':days days left',
        ],

        'restore' => 'Restore',

        // The trash for deleted CONTAINERS, see issue 62b decisions 7 and 8. It
        // sits at the TOP level — a deleted container is not resolved by the
        // route binding — and `link` is the row under the container list, always
        // visible. The text is constant and counts nothing: a number would be
        // a query per page load (decision 8).
        'containers' => [
            'title' => 'Trash',
            'heading' => 'Deleted containers',
            'description' => 'Containers you have deleted. After 30 days they are removed for good.',
            'empty' => 'No deleted containers.',
            'link' => 'Trash',
            'back' => 'Back to the containers',
        ],
    ],
];
