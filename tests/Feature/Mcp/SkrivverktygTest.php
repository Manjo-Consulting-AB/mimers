<?php

use App\Actions\Inbox\ResolveInbox;
use App\Actions\Item\LinkItems;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemTransaction;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\Tag;
use App\Models\User;

/*
 * Issue 404 · Skrivverktygen, se [[ADR-0087 MCP-server för agenter]] Riktning
 * 1, 3 och 7. Varje verktyg anropar samma action som REST-API:et, avvisas med
 * `agent.forbidden` när agentens nivå i gruppen inte räcker, och lämnar en rad
 * i `audit_log` med `agent_id` när det lyckas.
 *
 * Hjälparna mcpAgentMedToken(), mcpVerktyg(), mcpFel(), mcpResultat(),
 * mcpEgenContainer() och skrivNiva() bor i tests/Support/McpHjalpare.php.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
});

/**
 * En agent med token, en egen container (där alla grupper är påslagna på
 * `delete`) och ett item i den. Containen ägs av agentens eget konto, så
 * ägarens tak är `delete` och bara den nivå testet sätter kapar.
 *
 * @return array{0: Agent, 1: string, 2: User, 3: Container, 4: Item}
 */
function skrivMiljo(): array
{
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    $container = mcpEgenContainer($agent);

    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Motorn']);

    return [$agent, $token, $anvandare, $container, $item];
}

/**
 * Verktygen i gruppen `items`, med sina argument och sina förberedelser.
 *
 * @return array<string, array{grupp: string, niva: string, args: Closure, forbered?: Closure}>
 */
function skrivItemVerktyg(): array
{
    return [
        'items_create' => [
            'grupp' => 'items', 'niva' => 'create',
            'args' => fn (Container $c): array => ['container' => $c->ulid, 'name' => 'Nytt item'],
        ],
        'items_update' => [
            'grupp' => 'items', 'niva' => 'write',
            'args' => fn (Container $c, Item $i): array => ['item' => $i->ulid, 'name' => 'Motorn II'],
        ],
        'items_notes_update' => [
            'grupp' => 'items', 'niva' => 'write',
            'args' => fn (Container $c, Item $i): array => ['item' => $i->ulid, 'notes' => 'Servad i höstas'],
        ],
        'items_move' => [
            'grupp' => 'items', 'niva' => 'write',
            'forbered' => function (Container $c, Item $i, Agent $a, User $u): array {
                $parent = Item::factory()->create(['container_id' => $c->id, 'name' => 'Båten']);
                app(LinkItems::class)->handle($parent, $i, 'parent', $u);

                return ['parent' => $parent];
            },
            'args' => fn (Container $c, Item $i): array => ['item' => $i->ulid, 'to_parent' => null],
        ],
        'relations_create' => [
            'grupp' => 'items', 'niva' => 'write',
            'forbered' => fn (Container $c): array => [
                'other' => Item::factory()->create(['container_id' => $c->id, 'name' => 'Masten']),
            ],
            'args' => fn (Container $c, Item $i, array $f): array => [
                'item' => $i->ulid, 'other' => $f['other']->ulid, 'relation' => 'related',
            ],
        ],
        'relations_remove' => [
            'grupp' => 'items', 'niva' => 'write',
            'forbered' => function (Container $c, Item $i, Agent $a, User $u): array {
                $other = Item::factory()->create(['container_id' => $c->id, 'name' => 'Masten']);
                app(LinkItems::class)->handle($i, $other, 'related', $u);

                return ['other' => $other];
            },
            'args' => fn (Container $c, Item $i, array $f): array => [
                'item' => $i->ulid, 'other' => $f['other']->ulid,
            ],
        ],
        'categories_create' => [
            'grupp' => 'items', 'niva' => 'write',
            'args' => fn (Container $c): array => ['container' => $c->ulid, 'name' => 'Förvaring'],
        ],
        'categories_update' => [
            'grupp' => 'items', 'niva' => 'write',
            'forbered' => fn (Container $c): array => [
                'category' => Category::factory()->create(['container_id' => $c->id, 'name' => 'Förvaring']),
            ],
            'args' => fn (Container $c, Item $i, array $f): array => [
                'container' => $c->ulid, 'category' => $f['category']->ulid, 'name' => 'Förrådet',
            ],
        ],
        'tags_create' => [
            'grupp' => 'items', 'niva' => 'write',
            'args' => fn (Container $c): array => ['container' => $c->ulid, 'name' => 'Sommar'],
        ],
        'tags_set' => [
            'grupp' => 'items', 'niva' => 'write',
            'forbered' => fn (Container $c): array => [
                'tag' => Tag::factory()->create(['container_id' => $c->id, 'name' => 'Vinter']),
            ],
            'args' => fn (Container $c, Item $i, array $f): array => [
                'item' => $i->ulid, 'tags' => [$f['tag']->ulid],
            ],
        ],
    ];
}

it('avvisar items-verktygen med agent.forbidden utan nivån, och lyckas med den', function () {
    [$agent, $token, $anvandare, $container, $item] = skrivMiljo();

    foreach (skrivItemVerktyg() as $namn => $fall) {
        $forberedda = isset($fall['forbered']) ? ($fall['forbered'])($container, $item, $agent, $anvandare) : [];
        $args = ($fall['args'])($container, $item, $forberedda);

        skrivNiva($agent, $container, $fall['grupp'], 'read');

        expect(mcpFel(mcpVerktyg($token, $namn, $args))['code'])
            ->toBe('agent.forbidden', "$namn skulle avvisats utan nivån");

        skrivNiva($agent, $container, $fall['grupp'], $fall['niva']);

        $saldo = (int) AuditLog::query()->max('id');
        mcpResultat(mcpVerktyg($token, $namn, $args));

        expect(AuditLog::query()->where('id', '>', $saldo)->where('agent_id', $agent->id)->exists())
            ->toBeTrue("$namn skrev ingen historikrad med agenten");
    }
});

it('avvisar schemaverktygen med agent.forbidden utan nivån, och lyckas med den', function () {
    [$agent, $token, $anvandare, $container, $item] = skrivMiljo();

    $fall = [
        'schedules_create' => [
            'grupp' => 'schedules', 'niva' => 'create',
            'args' => fn (): array => [
                'item' => $item->ulid, 'title' => 'Oljebyte', 'recurrence_type' => 'none',
            ],
        ],
        'schedules_update' => [
            'grupp' => 'schedules', 'niva' => 'write',
            'forbered' => fn (): array => [
                'schedule' => Schedule::factory()->create(['item_id' => $item->id, 'title' => 'Oljebyte']),
            ],
            'args' => fn (array $f): array => ['schedule' => $f['schedule']->ulid, 'title' => 'Oljebyte II'],
        ],
        'occurrences_complete' => [
            'grupp' => 'schedules', 'niva' => 'write',
            'forbered' => fn (): array => [
                'occurrence' => ScheduleOccurrence::factory()->create([
                    'schedule_id' => Schedule::factory()->create(['item_id' => $item->id])->id,
                ]),
            ],
            'args' => fn (array $f): array => ['occurrence' => $f['occurrence']->ulid],
        ],
    ];

    foreach ($fall as $namn => $verktyg) {
        $forberedda = isset($verktyg['forbered']) ? ($verktyg['forbered'])() : [];
        $args = ($verktyg['args'])($forberedda);

        skrivNiva($agent, $container, $verktyg['grupp'], 'read');

        expect(mcpFel(mcpVerktyg($token, $namn, $args))['code'])
            ->toBe('agent.forbidden', "$namn skulle avvisats utan nivån");

        skrivNiva($agent, $container, $verktyg['grupp'], $verktyg['niva']);

        $saldo = (int) AuditLog::query()->max('id');
        mcpResultat(mcpVerktyg($token, $namn, $args));

        expect(AuditLog::query()->where('id', '>', $saldo)->where('agent_id', $agent->id)->exists())
            ->toBeTrue("$namn skrev ingen historikrad med agenten");
    }
});

it('avvisar kostnads- och utlåningsverktygen med agent.forbidden utan nivån, och lyckas med den', function () {
    [$agent, $token, $anvandare, $container, $item] = skrivMiljo();

    $fall = [
        'costs_create' => [
            'grupp' => 'costs', 'niva' => 'create',
            'args' => fn (): array => [
                'item' => $item->ulid, 'incurred_on' => '2026-03-01',
                'amount' => '1250', 'currency' => 'SEK', 'description' => 'Impeller',
            ],
        ],
        'costs_update' => [
            'grupp' => 'costs', 'niva' => 'write',
            'forbered' => fn (): array => [
                'cost' => ItemTransaction::factory()->create([
                    'item_id' => $item->id, 'container_id' => $container->id, 'currency' => 'SEK',
                ]),
            ],
            'args' => fn (array $f): array => ['cost' => $f['cost']->ulid, 'description' => 'Impeller och packning'],
        ],
        'loans_create' => [
            'grupp' => 'loans', 'niva' => 'create',
            'args' => fn (): array => [
                'item' => $item->ulid, 'borrower_name' => 'Sambon', 'lent_at' => '2026-03-01',
            ],
        ],
        'loans_return' => [
            'grupp' => 'loans', 'niva' => 'write',
            'forbered' => fn (): array => [
                'loan' => Loan::factory()->create(['item_id' => $item->id]),
            ],
            'args' => fn (array $f): array => ['loan' => $f['loan']->ulid, 'returned_at' => '2026-03-05'],
        ],
    ];

    foreach ($fall as $namn => $verktyg) {
        $forberedda = isset($verktyg['forbered']) ? ($verktyg['forbered'])() : [];
        $args = ($verktyg['args'])($forberedda);

        skrivNiva($agent, $container, $verktyg['grupp'], 'read');

        expect(mcpFel(mcpVerktyg($token, $namn, $args))['code'])
            ->toBe('agent.forbidden', "$namn skulle avvisats utan nivån");

        skrivNiva($agent, $container, $verktyg['grupp'], $verktyg['niva']);

        $saldo = (int) AuditLog::query()->max('id');
        mcpResultat(mcpVerktyg($token, $namn, $args));

        expect(AuditLog::query()->where('id', '>', $saldo)->where('agent_id', $agent->id)->exists())
            ->toBeTrue("$namn skrev ingen historikrad med agenten");
    }
});

// ─── kostnadsbeloppet: huvudenhet, inte minsta enhet (issue 1478) ────────────
//
// Skrivvägen var redan rätt på basen: CreateItemTransaction och UpdateItemTransaction
// tolkar amount med MinorUnits::parse(), alltså huvudenhet. Det som var fel
// var verktygsbeskrivningarna, och de prövas i LasverktygTest. Proven här är
// därför regressionsvakter för kontraktet — de ska vara gröna även utan
// PR:ens kod, och är vaktmarkerade för röd-på-basen-grinden.

// vakt: skrivvägen tolkade redan "100" som 10000 på basen — beskrivningen var felet, inte koden.
it('costs_create sparar "100" SEK som 10000', function () {
    [, $token, , , $item] = skrivMiljo();

    mcpResultat(mcpVerktyg($token, 'costs_create', [
        'item' => $item->ulid, 'incurred_on' => '2026-03-01',
        'amount' => '100', 'currency' => 'SEK', 'description' => 'Varmkorv',
    ]));

    expect((int) ItemTransaction::query()->sole()->amount)->toBe(10000);
});

// vakt: parse() tog både punkt och komma redan på basen — vaktar att kontraktet består.
it('costs_create tolkar både punkt och komma — "125.50" och "125,50" blir 12550', function () {
    [, $token, , , $item] = skrivMiljo();

    foreach (['125.50', '125,50'] as $belopp) {
        mcpResultat(mcpVerktyg($token, 'costs_create', [
            'item' => $item->ulid, 'incurred_on' => '2026-03-01',
            'amount' => $belopp, 'currency' => 'SEK', 'description' => 'Packning',
        ]));
    }

    expect(ItemTransaction::query()->orderBy('id')->pluck('amount')->map(fn ($a): int => (int) $a)->all())
        ->toBe([12550, 12550]);
});

// vakt: UpdateItemTransaction tolkade redan "100" som 10000 på basen — beskrivningen var felet, inte koden.
it('costs_update sparar amount "100" SEK som 10000', function () {
    [, $token, , $container, $item] = skrivMiljo();

    $kostnad = ItemTransaction::factory()->create([
        'item_id' => $item->id, 'container_id' => $container->id,
        'amount' => 99900, 'currency' => 'SEK',
    ]);

    mcpResultat(mcpVerktyg($token, 'costs_update', [
        'cost' => $kostnad->ulid, 'amount' => '100', 'currency' => 'SEK',
    ]));

    expect((int) $kostnad->refresh()->amount)->toBe(10000);
});

it('avvisar containers_update med agent.forbidden utan nivån, och lyckas med den', function () {
    [$agent, $token, , $container] = skrivMiljo();

    $args = ['container' => $container->ulid, 'name' => 'Verkstaden'];

    skrivNiva($agent, $container, 'containers', 'read');
    expect(mcpFel(mcpVerktyg($token, 'containers_update', $args))['code'])->toBe('agent.forbidden');

    skrivNiva($agent, $container, 'containers', 'write');
    mcpResultat(mcpVerktyg($token, 'containers_update', $args));

    expect($container->refresh()->name)->toBe('Verkstaden')
        ->and(AuditLog::query()->where('agent_id', $agent->id)->where('action', AuditLog::ACTION_CONTAINER_UPDATED)->exists())
        ->toBeTrue();
});

it('skapar en container utan create_containers avvisas, och med flaggan får den kontots nivåer', function () {
    [$agent, $token] = mcpAgentMedToken();

    $fel = mcpFel(mcpVerktyg($token, 'containers_create', ['name' => 'Verkstaden']));

    expect($fel['code'])->toBe('agent.create_containers_not_allowed')
        ->and(Container::query()->where('name', 'Verkstaden')->exists())->toBeFalse();

    $agent->create_containers = true;
    $agent->save();

    $svar = mcpResultat(mcpVerktyg($token, 'containers_create', ['name' => 'Verkstaden']));

    $ny = Container::query()->where('ulid', $svar['ulid'])->sole();

    expect((int) $ny->account_id)->toBe((int) $agent->account_id)
        ->and(AgentContainerGrant::query()->where('agent_id', $agent->id)->where('container_id', $ny->id)->pluck('level', 'group')->all())
        ->toBe(AgentAccountGrant::query()->where('agent_id', $agent->id)->pluck('level', 'group')->all())
        ->and(AuditLog::query()->where('agent_id', $agent->id)->where('action', AuditLog::ACTION_CONTAINER_CREATED)->exists())
        ->toBeTrue();
});

it('sparar anteckningen genom UpdateItemNotes och lämnar inte tillbaka texten', function () {
    [$agent, $token, , $container, $item] = skrivMiljo();

    $svar = mcpResultat(mcpVerktyg($token, 'items_notes_update', [
        'item' => $item->ulid,
        'notes' => 'Servad i höstas',
    ]));

    expect($item->refresh()->notes)->toBe('Servad i höstas')
        ->and(json_encode($svar))->not->toContain('Servad i höstas')
        ->and(AuditLog::query()->where('agent_id', $agent->id)->where('item_id', $item->id)->where('action', AuditLog::ACTION_ITEM_UPDATED)->exists())
        ->toBeTrue();

    // UpdateItemNotes loggar per ARBETSPASS, inte per sparning (ADR-0066 § 10):
    // en andra sparning inom tio minuter skriver ingen ny rad. Det är
    // beteendet som skiljer actionen från UpdateItem, och beviset för att
    // verktyget går genom rätt en.
    mcpResultat(mcpVerktyg($token, 'items_notes_update', [
        'item' => $item->ulid,
        'notes' => 'Servad i höstas, igen',
    ]));

    expect(AuditLog::query()->where('item_id', $item->id)->where('action', AuditLog::ACTION_ITEM_UPDATED)->count())->toBe(1)
        ->and($item->refresh()->notes)->toBe('Servad i höstas, igen');
});

it('nekar både rotitem och barn i en inkorg', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    $inboxItem = app(ResolveInbox::class)->handle($anvandare);
    $inbox = $inboxItem->container;

    mcpSlaPaI($agent, $inbox);

    // Inkorgen bär inga items (ADR-0054 § 2, issue 1689): varken ett rotitem
    // eller ett barn under inkorgens item.
    expect(mcpFel(mcpVerktyg($token, 'items_create', [
        'container' => $inbox->ulid,
        'name' => 'Rotitem i inkorgen',
    ]))['code'])->toBe('agent.forbidden');

    expect(mcpFel(mcpVerktyg($token, 'items_create', [
        'container' => $inbox->ulid,
        'name' => 'Barn till inkorgens item',
        'parent' => $inboxItem->ulid,
    ]))['code'])->toBe('agent.forbidden');

    expect(Item::query()->where('container_id', $inbox->id)->whereIn('name', ['Rotitem i inkorgen', 'Barn till inkorgens item'])->exists())->toBeFalse();
});

// ─── costs_* skriver och ser bara utgifter (issue 1778 · ADR-0102) ───────────

it('costs_create skriver supplier till counterparty och raden som expense', function () {
    [, $token, , , $item] = skrivMiljo();

    $svar = mcpResultat(mcpVerktyg($token, 'costs_create', [
        'item' => $item->ulid, 'incurred_on' => '2026-04-12', 'amount' => '100', 'currency' => 'SEK',
        'description' => 'Olja', 'supplier' => 'Biltema',
    ]));

    $rad = ItemTransaction::query()->where('ulid', $svar['ulid'])->firstOrFail();
    expect($rad->kind)->toBe('expense')->and($rad->counterparty)->toBe('Biltema');
});

it('costs_update och costs_delete ger resource.not_found på en intäktsrad', function () {
    [, $token, , $container, $item] = skrivMiljo();

    $intakt = ItemTransaction::factory()->create(['item_id' => $item->id, 'container_id' => $container->id, 'kind' => 'income']);

    expect(mcpFel(mcpVerktyg($token, 'costs_update', ['cost' => $intakt->ulid, 'description' => 'x']))['code'])->toBe('resource.not_found')
        ->and(mcpFel(mcpVerktyg($token, 'costs_delete', ['cost' => $intakt->ulid]))['code'])->toBe('resource.not_found');

    expect(ItemTransaction::query()->find($intakt->id))->not->toBeNull();
});

it('costs_update skriver supplier till counterparty', function () {
    [, $token, , $container, $item] = skrivMiljo();

    $kostnad = ItemTransaction::factory()->create(['item_id' => $item->id, 'container_id' => $container->id]);

    mcpResultat(mcpVerktyg($token, 'costs_update', ['cost' => $kostnad->ulid, 'supplier' => 'Svedea']));

    expect($kostnad->refresh()->counterparty)->toBe('Svedea');
});

// ─── transactions_* (issue 1780 · ADR-0102) ──────────────────────────────────

it('transactions_create utan kind skriver en utgift, med kind=income en intäkt', function () {
    [, $token, , , $item] = skrivMiljo();

    $bas = ['item' => $item->ulid, 'incurred_on' => '2026-04-12', 'amount' => '100', 'currency' => 'SEK', 'description' => 'Försäljning', 'counterparty' => 'Kund AB', 'quantity' => '2.5'];

    $utgift = mcpResultat(mcpVerktyg($token, 'transactions_create', $bas));
    $intakt = mcpResultat(mcpVerktyg($token, 'transactions_create', $bas + ['kind' => 'income']));

    $rad = ItemTransaction::query()->where('ulid', $intakt['ulid'])->firstOrFail();

    expect(ItemTransaction::query()->where('ulid', $utgift['ulid'])->value('kind'))->toBe('expense')
        ->and($rad->kind)->toBe('income')
        ->and($rad->counterparty)->toBe('Kund AB')
        ->and((float) $rad->quantity)->toBe(2.5)
        ->and($intakt['kind'])->toBe('income');
});

it('transactions_update och transactions_delete fungerar på en intäktsrad', function () {
    [, $token, , $container, $item] = skrivMiljo();

    $intakt = ItemTransaction::factory()->create(['item_id' => $item->id, 'container_id' => $container->id, 'kind' => 'income']);

    mcpResultat(mcpVerktyg($token, 'transactions_update', ['transaction' => $intakt->ulid, 'counterparty' => 'Kund AB']));
    expect($intakt->refresh()->counterparty)->toBe('Kund AB');

    mcpResultat(mcpVerktyg($token, 'transactions_delete', ['transaction' => $intakt->ulid]));
    expect(ItemTransaction::query()->find($intakt->id))->toBeNull();
});

it('transactions_update byter riktning, rensar quantity och lämnar riktningen orörd vid kind=null', function () {
    [, $token, , $container, $item] = skrivMiljo();

    $rad = ItemTransaction::factory()->create([
        'item_id' => $item->id, 'container_id' => $container->id, 'kind' => 'expense', 'quantity' => '3',
    ]);

    mcpResultat(mcpVerktyg($token, 'transactions_update', ['transaction' => $rad->ulid, 'kind' => 'income']));
    expect($rad->refresh()->kind)->toBe('income');

    mcpResultat(mcpVerktyg($token, 'transactions_update', ['transaction' => $rad->ulid, 'kind' => 'expense']));
    expect($rad->refresh()->kind)->toBe('expense');

    mcpResultat(mcpVerktyg($token, 'transactions_update', ['transaction' => $rad->ulid, 'kind' => 'income']));
    mcpResultat(mcpVerktyg($token, 'transactions_update', ['transaction' => $rad->ulid, 'kind' => null, 'quantity' => null]));
    $rad->refresh();
    expect($rad->kind)->toBe('income')->and($rad->quantity)->toBeNull();
});

it('avvisar transactions_* med agent.forbidden när kostnadsgruppen bara är läsbar', function () {
    [$agent, $token, , $container, $item] = skrivMiljo();

    $rad = ItemTransaction::factory()->create(['item_id' => $item->id, 'container_id' => $container->id]);

    $fall = [
        'transactions_create' => ['item' => $item->ulid, 'incurred_on' => '2026-03-01', 'amount' => '10', 'currency' => 'SEK', 'description' => 'x'],
        'transactions_update' => ['transaction' => $rad->ulid, 'description' => 'y'],
        'transactions_delete' => ['transaction' => $rad->ulid],
    ];

    skrivNiva($agent, $container, 'costs', 'read');

    foreach ($fall as $namn => $args) {
        expect(mcpFel(mcpVerktyg($token, $namn, $args))['code'])->toBe('agent.forbidden', $namn);
    }

    expect(ItemTransaction::query()->find($rad->id))->not->toBeNull();
});
