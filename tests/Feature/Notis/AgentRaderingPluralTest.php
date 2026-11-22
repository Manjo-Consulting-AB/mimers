<?php

use App\Mail\NotificationMail;
use App\Models\User;

/*
 * Issue 1512 · meningen för `agent.deleted` följer antalet: "1 thing",
 * "2 things". Klienten väljer en `_one`-nyckel (translate.js har ingen
 * pluralisering); mejlet använder Laravels pluralform via trans_choice.
 */
function agentRaderingMejl(int $total, array $extra = []): NotificationMail
{
    $person = User::factory()->create();

    return new NotificationMail($person, 'agent.deleted', ['agent' => 'Claude', 'total' => $total, ...$extra]);
}

it('skriver mejlets ämne och text i singular för en raderad sak', function () {
    $mejl = agentRaderingMejl(1);

    expect($mejl->envelope()->subject)->toBe('An agent deleted 1 thing');

    $html = $mejl->render();
    expect($html)->toContain('deleted 1 thing.');
    expect(str_contains($html, '1 things'))->toBeFalse();
});

it('skriver mejlets ämne och text i plural för fler saker', function () {
    $mejl = agentRaderingMejl(2);

    expect($mejl->envelope()->subject)->toBe('An agent deleted 2 things');
    expect($mejl->render())->toContain('deleted 2 things.');
});

it('böjer även varianten med containrar', function () {
    expect(agentRaderingMejl(1, ['container_items' => 4])->render())
        ->toContain('deleted 1 thing.')->toContain('held 4 items');
    expect(agentRaderingMejl(3, ['container_items' => 4])->render())
        ->toContain('deleted 3 things.');
});

it('har en singularmening åt klockan, listan och inkorgen', function () {
    expect(__('ui.inbox.agent.deleted_one', ['agent' => 'Claude']))->toBe('Claude deleted 1 thing')
        ->and(__('ui.notifications.list.type.agent.deleted_one', ['agent' => 'Claude']))
        ->toBe('Claude deleted 1 thing. Anything in the trash can be restored.');
});

it('väljer singularnyckeln i raden och ersätter agent och total i inkorgen', function () {
    expect(file_get_contents(resource_path('js/components/NotificationRow.vue')))
        ->toContain('notifications.list.type.agent.deleted_one');

    $skript = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/components/notificationPresentation.js'), JSON_UNESCAPED_SLASHES).').href);',
        'const mall = '.json_encode(['inbox.agent.deleted' => ':agent deleted :total things', 'inbox.agent.deleted_one' => ':agent deleted 1 thing']).';',
        'const t = (key, p = {}) => (mall[key] ?? key).replace(/:(\w+)/g, (h, n) => (n in p ? String(p[n]) : h));',
        'const rad = (total) => m.notificationMessage(t, { type: "agent.deleted", payload: { agent: "Claude", total } }, (d) => d);',
        'process.stdout.write(JSON.stringify([rad(1), rad(2)]));',
    ]);

    exec('node --input-type=module -e '.escapeshellarg($skript).' 2>&1', $rader, $kod);

    expect($kod)->toBe(0, implode("\n", $rader));
    expect(json_decode(implode("\n", $rader), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['Claude deleted 1 thing', 'Claude deleted 2 things']);
});
