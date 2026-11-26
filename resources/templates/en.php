<?php

/*
 * Mimers egna mallar — kategoriförslagen som erbjuds vid en ny container, se
 * [[ADR-0101 Mallarna]] § Beslut 1 och 2. Innehållet flyttades oförändrat
 * ur resources/js/data/categoryPresets.js; servern äger nu mallarna och
 * klienten väljer dem med `key`.
 *
 * Varje post har en stabil `key` (kebab-case, ändras aldrig), ett `name` som
 * väljaren visar, ett `content` i samma form som `container_template.content`
 * och ett valfritt `agent_rules` (sträng eller `null`; ingen mall bär några
 * ännu). Innehållet prövas mot App\Http\Requests\Template\TemplateContentRules
 * av tests/Feature/Mall/MimersMallarTest.php.
 *
 * En fil per språk. `en` är enda levererade språket ([[ADR-0034 Engelska vid
 * lansering]]) och App\Support\Template\MimersTemplates faller tillbaka på den
 * för varje annan locale. Orden är förslag på sitt språk, inte översättningar
 * av varandra.
 */

return [
    [
        'key' => 'sailboat',
        'name' => 'Boat',
        'agent_rules' => null,
        'content' => [
            'categories' => [
                [
                    'name' => 'Engine',
                    'children' => [
                        ['name' => 'Drive train'],
                        ['name' => 'Cooling'],
                    ],
                ],
                ['name' => 'Rig and sails'],
                ['name' => 'Electrical'],
                ['name' => 'Insurance and papers'],
                ['name' => 'Hull and deck'],
                ['name' => 'Safety on board'],
                ['name' => 'Galley and storage'],
                ['name' => 'Winter storage'],
            ],
            'fields' => [
                ['label' => 'Manufacturer', 'type' => 'text'],
                ['label' => 'Model', 'type' => 'text'],
                ['label' => 'Serial number', 'type' => 'text'],
                ['label' => 'Warranty until', 'type' => 'date'],
            ],
        ],
    ],
    [
        'key' => 'caravan',
        'name' => 'Caravan',
        'agent_rules' => null,
        'content' => [
            'categories' => [
                ['name' => 'Chassis and towing'],
                ['name' => 'Power and battery'],
                ['name' => 'Water and drainage'],
                ['name' => 'Gas'],
                ['name' => 'Interior'],
                ['name' => 'Awning and accessories'],
                ['name' => 'Insurance and papers'],
                ['name' => 'Winter storage'],
            ],
            'fields' => [
                ['label' => 'Manufacturer', 'type' => 'text'],
                ['label' => 'Model', 'type' => 'text'],
                ['label' => 'Serial number', 'type' => 'text'],
                ['label' => 'Warranty until', 'type' => 'date'],
            ],
        ],
    ],
    [
        'key' => 'house',
        'name' => 'House',
        'agent_rules' => null,
        'content' => [
            'categories' => [
                ['name' => 'Foundation and frame'],
                ['name' => 'Roof and gutters'],
                ['name' => 'Facade and windows'],
                ['name' => 'Heating and ventilation'],
                ['name' => 'Electrical and lighting'],
                ['name' => 'Water and drainage'],
                ['name' => 'Garden'],
                ['name' => 'Documents and insurance'],
            ],
            'fields' => [
                ['label' => 'Built year', 'type' => 'number'],
                ['label' => 'Warranty until', 'type' => 'date'],
            ],
        ],
    ],
    [
        'key' => 'car',
        'name' => 'Car',
        'agent_rules' => null,
        'content' => [
            'categories' => [
                [
                    'name' => 'Engine and drive train',
                    'children' => [
                        ['name' => 'Timing belt'],
                        ['name' => 'Oil and filters'],
                    ],
                ],
                ['name' => 'Brakes'],
                ['name' => 'Tyres and wheels'],
                ['name' => 'Electrical and lighting'],
                ['name' => 'Body and paint'],
                ['name' => 'Service and inspection'],
                ['name' => 'Papers and insurance'],
                ['name' => 'Accessories'],
            ],
            'fields' => [
                ['label' => 'Manufacturer', 'type' => 'text'],
                ['label' => 'Model', 'type' => 'text'],
                ['label' => 'Serial number', 'type' => 'text'],
                ['label' => 'Registration number', 'type' => 'text'],
                ['label' => 'Warranty until', 'type' => 'date'],
            ],
        ],
    ],
    [
        'key' => 'other',
        'name' => 'Other',
        'agent_rules' => null,
        'content' => [
            'categories' => [
                ['name' => 'Documents'],
                ['name' => 'Storage'],
                ['name' => 'Tools'],
                ['name' => 'Maintenance'],
                ['name' => 'Receipts and warranties'],
                ['name' => 'Sundries'],
            ],
            'fields' => [
            ],
        ],
    ],
];
