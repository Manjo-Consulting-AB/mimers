<?php

return [
    'task_due' => [
        'subject' => ':title is due on :date',
        'greeting' => 'Hello!',
        'line' => 'The task ":title" on ":item" in the binder ":container" is due on :date.',
        'action' => 'Open task',
    ],
    'task_overdue' => [
        'subject' => ':title was due on :date',
        'greeting' => 'Hello!',
        'line' => 'The task ":title" on ":item" in the binder ":container" was due on :date and is now overdue.',
        'action' => 'Open task',
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
    'unsubscribe' => [
        'footer' => 'Do not want this kind of notification? :link',
        'link' => 'Unsubscribe',
        'confirm_heading' => 'Stop receiving :type?',
        'confirm_button' => 'Yes, turn off',
        'done' => 'You will not receive this kind of notification anymore. You can turn it back on under notification settings.',
    ],
];
