<?php

return [
    'task_due' => [
        'subject' => ':title förfaller :date',
        'greeting' => 'Hej!',
        'line' => 'Uppgiften ":title" på ":item" i pärmen ":container" förfaller :date.',
        'action' => 'Öppna uppgiften',
    ],
    'task_overdue' => [
        'subject' => ':title förföll :date',
        'greeting' => 'Hej!',
        'line' => 'Uppgiften ":title" på ":item" i pärmen ":container" förföll :date och är nu försenad.',
        'action' => 'Öppna uppgiften',
    ],
    'quota_warning' => [
        'subject' => 'Lagringsutrymmet är :percent % fullt',
        'greeting' => 'Hej!',
        'line' => 'Du har använt :percent % av lagringsutrymmet. När kvoten är full går det inte att ladda upp fler filer.',
        'action' => 'Visa lagringsanvändning',
    ],
    'account_inactive' => [
        'subject' => 'Kontot stängs om :months månader',
        'greeting' => 'Hej!',
        'line' => 'Kontot har varit inaktivt i :months månader. Loggar du inte in före :close_at stängs kontot och uppgifterna raderas.',
        'action' => 'Logga in',
    ],
    'digest' => [
        'subject' => 'Din vecka i Mimers: :count påminnelser',
        'greeting' => 'Hej!',
        'intro' => 'Det här är på gång den närmaste tiden.',
        'more' => 'Och :count till.',
    ],
    'unsubscribe' => [
        'footer' => 'Vill du inte ha den här sortens notiser? :link',
        'link' => 'Avregistrera',
        'confirm_heading' => 'Sluta ta emot :type?',
        'confirm_button' => 'Ja, stäng av',
        'done' => 'Du får inga fler notiser av den här sorten. Du kan slå på dem igen under Notisinställningar.',
        'types' => [
            'task_due' => 'påminnelser om uppgifter',
            'task_overdue' => 'varningar om försenade uppgifter',
            'loan_due' => 'påminnelser om utlåning',
            'quota_warning' => 'varningar om lagringsutrymmet',
            'invitation_received' => 'inbjudningar till pärmar',
            'transfer_requested' => 'förfrågningar om ägarbyte',
            'account_inactive' => 'varningar om inaktiva konton',
        ],
    ],
];
