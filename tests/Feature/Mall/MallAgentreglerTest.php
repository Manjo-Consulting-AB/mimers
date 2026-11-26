<?php

use App\Actions\Template\CreateContainerFromTemplate;
use App\Actions\Template\SaveContainerTemplate;
use App\Http\Requests\Template\TemplateContentRules;
use App\Models\AgentRulesRevision;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\User;
use App\Support\Template\MimersTemplate;
use Illuminate\Support\Facades\Validator;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
 * Issue 517 · [[ADR-0101 Mallarna]] § Beslut 5: en sparad mall bär containerns
 * agentregler, och en container skapad ur en mall får dem.
 */

function mallMedRegler(User $user, $account, ?string $regler = 'Svara kort.'): Container
{
    $container = Container::factory()->for($account, 'account')->create(['agent_rules' => $regler]);

    return $container;
}

it('sparar containerns regler i mallen när include_agent_rules är sant, och null när den är falsk', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $container = mallMedRegler($user, $account);

    $med = postJson('/api/templates', ['container' => $container->ulid, 'name' => 'Med'], $headers)->assertCreated();
    $utan = postJson('/api/templates', ['container' => $container->ulid, 'name' => 'Utan', 'include_agent_rules' => false], $headers)->assertCreated();

    expect($med->json('data.content.agent_rules'))->toBe('Svara kort.')
        ->and($utan->json('data.content.agent_rules'))->toBeNull();
});

it('sparar null när containern saknar regler', function () {
    [$account, $user] = kontoMedMedlem();
    $container = mallMedRegler($user, $account, null);

    $mall = app(SaveContainerTemplate::class)->handle($user, $container, 'Tom', true);

    expect($mall->content['agent_rules'])->toBeNull();
});

it('ger inte reglerna till någon som inte är medlem i ägarkontot', function () {
    [$account, $user] = kontoMedMedlem();
    $container = mallMedRegler($user, $account);
    $utomstaende = User::factory()->create();

    $mall = app(SaveContainerTemplate::class)->handle($utomstaende, $container, 'Läckt', true);

    expect($mall->content['agent_rules'])->toBeNull();
});

function mallRevisionKontroll(Container $ny, User $user): void
{
    $revision = AgentRulesRevision::query()->where('container_id', $ny->id)->sole();

    expect($ny->agent_rules)->toBe('Svara kort.')
        ->and($revision->revision)->toBe(1)
        ->and($revision->author_user_id)->toBe($user->id);
}

it('ger en ny container mallens regler med revision 1 och den skapande som författare i /api', function () {
    [$account, $user, $headers] = kontoMedMedlem();
    $mall = app(SaveContainerTemplate::class)->handle($user, mallMedRegler($user, $account), 'Mall', true);

    $api = postJson('/api/containers', ['account' => $account->ulid, 'name' => 'Api', 'template' => $mall->ulid], $headers)->assertCreated();

    mallRevisionKontroll(Container::query()->where('ulid', $api->json('data.ulid'))->firstOrFail(), $user);
});

it('ger en ny container mallens regler med revision 1 och den skapande som författare i webben', function () {
    [$account, $user] = kontoMedMedlem();
    $mall = app(SaveContainerTemplate::class)->handle($user, mallMedRegler($user, $account), 'Mall', true);

    actingAs($user)->post('/containers', ['account' => $account->ulid, 'name' => 'Webb', 'template' => $mall->ulid])->assertSessionHasNoErrors();

    mallRevisionKontroll(Container::query()->where('name', 'Webb')->firstOrFail(), $user);
});

it('ger en ny container en Mimers-mall med regler dess regler', function () {
    [$account, $user] = kontoMedMedlem();

    $container = app(CreateContainerFromTemplate::class)->handle(
        $user, $account, 'Ur Mimers', null, null, null, null,
        new MimersTemplate('testmall', 'Testmall', ['categories' => [], 'fields' => []], 'Mimers regel.'),
    );

    expect($container->fresh()->agent_rules)->toBe('Mimers regel.')
        ->and(AgentRulesRevision::query()->where('container_id', $container->id)->sole()->author_user_id)->toBe($user->id);
});

it('läser en gammal mall utan nyckeln som utan regler', function () {
    [$account, $user, $headers] = kontoMedMedlem();

    $mall = new ContainerTemplate(['name' => 'Gammal', 'content' => ['categories' => [], 'fields' => []]]);
    $mall->account_id = $account->id;
    $mall->billed_account_id = $account->id;
    $mall->save();

    $svar = postJson('/api/containers', ['account' => $account->ulid, 'name' => 'Ny', 'template' => $mall->ulid], $headers)->assertCreated();

    $ny = Container::query()->where('ulid', $svar->json('data.ulid'))->firstOrFail();

    expect($ny->agent_rules)->toBeNull()
        ->and(AgentRulesRevision::query()->where('container_id', $ny->id)->exists())->toBeFalse();
});

it('låter TemplateContentRules tillåta agent_rules upp till 2 000 tecken och avvisa mer', function () {
    $ok = Validator::make(['agent_rules' => str_repeat('a', 2000)], TemplateContentRules::rules());
    $null = Validator::make(['agent_rules' => null], TemplateContentRules::rules());
    $for = Validator::make(['agent_rules' => str_repeat('a', 2001)], TemplateContentRules::rules());

    expect($ok->fails())->toBeFalse()->and($null->fails())->toBeFalse()->and($for->fails())->toBeTrue();
});

it('visar kryssrutan bara när containern har regler och personen får skriva dem', function () {
    [$account, $user] = kontoMedMedlem();
    $med = mallMedRegler($user, $account);
    $utan = mallMedRegler($user, $account, null);

    $flagga = fn (Container $c) => actingAs($user)->get("/containers/{$c->ulid}/edit")->viewData('page')['props']['can']['includeAgentRules'];

    expect($flagga($med))->toBeTrue()->and($flagga($utan))->toBeFalse();
});
