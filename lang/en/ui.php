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

        // Issue 62a decision 7. ONE code for all four types: the restore takes
        // `type` in the body and shares one list, so the view has no reason to
        // know which of them just came back — but the sentence says content,
        // not item (same reason as issue 20a decision 1).
        'trash-restored' => 'The content has been restored.',

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
            'parent_not_in_container' => 'The chosen parent category is not in this binder.',
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
            'cross_container' => 'Relations only go between items in the same binder.',
            'pair_exists' => 'The two are already linked: the counterpart is :relation.',
            'relation_word' => [
                'parent' => 'a parent',
                'child' => 'a child',
                'sibling' => 'a sibling',
            ],
            'cycle' => 'That direction would make a circle: this item is already above the counterpart, directly or through other items.',
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
            // `items` comes first, like the row in containerSections.js: the
            // items are the binder, the categories and tags are how it is
            // organised.
            'items' => 'Items',
            'categories' => 'Categories',
            'tags' => 'Tags',
            'sharing' => 'Sharing',
            'settings' => 'Settings',
            // Last, like the row in containerSections.js — the trash is where
            // you go when something went wrong (issue 62a decision 1).
            'trash' => 'Trash',
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

            // The suggestion on an empty binder, see issue 56b decision 4. The
            // words in the set itself are NOT here and never will be: they live
            // in resources/js/data/categoryPresets.js, per language and kind.
            // `preset_not_empty` is the route's answer on a binder that already
            // has categories and lands on the `categories` form key — a
            // sentence, not an API error code, since the route is web-only.
            'preset_heading' => 'Ready-made set',
            'preset_description' => 'We can fill the binder with a ready-made suggestion of categories. You can rename, move and delete them just like any other category afterwards.',
            'preset_apply' => 'Add the set',
            'preset_dismiss' => 'No thanks',
            'preset_not_empty' => 'The binder already has categories. A set can only be added to an empty binder.',
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
            'empty' => 'The binder is empty.',

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
            // a deleted tag, a category moved to another binder. The row is
            // the whole answer: no 422, no redirect back to the same query
            // string (decision 3).
            'filter_dropped' => 'A filter in the link no longer exists and has been removed.',

            // The "filter, no rows" state. Without a filter `empty` says the
            // binder is empty instead.
            'filter_empty' => 'No hits with these filters: :filters.',

            'filter_label_q' => 'the search term “:value”',
            'filter_label_tag' => 'the tag :name',
            'filter_label_tags' => 'the tags :names',
            'filter_label_category' => 'the category :name',
        ],

        'show' => [
            'description' => 'Description',
            'manufacturer' => 'Manufacturer',
            'model' => 'Model',
            'serial_number' => 'Serial number',
            'purchased_at' => 'Purchase date',
            'warranty_until' => 'Warranty until',
            'position_note' => 'Location',
            'category' => 'Category',
            'tags' => 'Tags',
        ],

        // The form's field labels, see issue 57b decision 9. The product's
        // words and not the column names: *Purchased* and *Warranty until* is
        // what the field asks, unlike the detail view's summarising *Purchase
        // date*. Hence its own keys rather than reusing `show.*`.
        'form' => [
            'name' => 'Name',
            'description' => 'Description',
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
            'categories_empty' => 'The binder has no categories yet.',
            'categories_empty_link' => 'Create categories',

            // The tags are checkboxes, one per tag in the binder. A new tag is
            // created on the tags page and not here: one way to the same write
            // in two places is two rules to keep in step (decision 5).
            'tags' => 'Tags',
            'tags_empty' => 'The binder has no tags yet.',
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
                'sibling' => 'Siblings',
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
                'sibling' => 'A sibling',
            ],

            // Decision 8: what a direction does to the sharing, in one
            // sentence. No computation — no question about which grants
            // exist and no counter. That number belongs to the sharing view
            // (55a), and a second truth about the scope is one that can
            // drift apart.
            'relation_note' => 'Sharing a parent item also reaches its child items — siblings share nothing.',

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
        // the quota is counted on the uploading account and not on the binder
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
            'billing_note' => 'Storage is charged to the account below, not to the binder owner.',
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
    ],

    // The global search, see issue 59b decisions 4, 6, 7 and 8. The page lives
    // in resources/js/pages/Search.vue, the field in
    // resources/js/components/SearchField.vue — the field sits in the SHARED
    // layout and therefore shows on every signed-in page (decision 5).
    //
    // `empty` names the search term and stops there (decision 6): no number of
    // rows that existed, no hint that something was held back, no listing of
    // which binders were searched — which binders at all is information in
    // itself. A user with access to nothing gets word for word the same
    // sentence as a user whose term matches nothing, because the sentence
    // knows nothing about scope.
    //
    // `intro` and `whole_words` are the starting state, i.e. the state where
    // no query ran at all (decisions 4 and 7). The last line says the search
    // matches whole words: Swedish stemming does not exist in the MVP
    // ([[ADR-0012 Sök]]), and no compensation is built in the view — no
    // stemming in JavaScript, no second search on a truncated word. One line
    // is the whole answer.
    //
    // `in_container` is the prefix before the binder name, and only the
    // prefix: the name is its own link to the binder's front page (decision
    // 3), so the words cannot live in one string.
    'search' => [
        'title' => 'Search',
        'heading' => 'Search',

        'intro' => 'Searches name, description, manufacturer, model and serial number — in every binder you can reach.',
        'whole_words' => 'The search matches whole words: “battery” does not find “batteries”.',
        'empty' => 'No hits for “:q”.',
        'in_container' => 'in',

        'field' => [
            'label' => 'Search all binders',
            'placeholder' => 'Search term',
            'submit' => 'Search',
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

    // The binder's trash, see issue 62a decisions 4, 5 and 9 and [[ADR-0008
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
        'description' => 'What has been deleted in the binder. After 30 days it is removed for good.',
        'empty' => 'The trash is empty.',

        // `:date` is formatted on the client (formatDate), the words around it
        // here.
        'deleted_at' => 'Deleted :date',

        // The keys are the `type` values from RestoreRequest::TYPES, never
        // invented names of our own — same rule as container.kind.
        'type' => [
            'item' => 'Item',
            'attachment' => 'Attachment',
            'category' => 'Category',
            'tag' => 'Tag',
        ],

        'expires' => [
            'today' => 'Disappears today',
            'day' => '1 day left',
            'days' => ':days days left',
        ],

        'restore' => 'Restore',
    ],
];
