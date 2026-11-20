<?php

use App\Mcp\Tools\AttachmentsUpload;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\call;
use function Pest\Laravel\travel;

/*
 * Issue 405 · Uppladdning av filer via MCP, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 4 och 13.
 *
 * Två vägar in, samma väg genom appen: `attachments_upload` tar filen
 * base64-kodad i anropet (högst 20 MB efter avkodning), och
 * `attachments_upload_url` lämnar en signerad adress för större filer —
 * 15 minuter, en användning, bunden till agenten, itemet, filnamnet och den
 * angivna storleken. Båda går genom StoreAttachment med samma plangräns,
 * virusskanning, dedup och kvot, belastar agentens användares personkonto
 * och prövas mot kopieringsspärren med itemet som mål.
 *
 * Hjälparna mcpAgentMedToken(), mcpVerktyg(), mcpFel(), mcpResultat() och
 * mcpEgenContainer() bor i tests/Support/McpHjalpare.php; beviljaAccess() och
 * sättPlangräns() i tests/Support/Testhjalpare.php.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    Storage::fake('files');
});

/**
 * En agent med token, ett item i en egen container och agentens användare.
 *
 * @return array{0: Agent, 1: string, 2: User, 3: Container, 4: Item}
 */
function uppladdningsMiljo(): array
{
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    $container = mcpEgenContainer($agent);

    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Motorn']);

    return [$agent, $token, $anvandare, $container, $item];
}

/**
 * Sänker agentens nivå i gruppen `files` — både kontoraden och
 * containerraden, eftersom ResolveAgentAccess tar den lägsta av dem.
 */
function uppladdningsNiva(Agent $agent, Container $container, string $niva): void
{
    AgentAccountGrant::query()
        ->where('agent_id', $agent->id)
        ->where('group', 'files')
        ->update(['level' => $niva]);

    AgentContainerGrant::query()
        ->where('agent_id', $agent->id)
        ->where('container_id', $container->id)
        ->where('group', 'files')
        ->update(['level' => $niva]);
}

it('gör en base64-kodad fil till en bilaga med agentens användares personkonto som betalare', function () {
    [$agent, $token, $anvandare, $container, $item] = uppladdningsMiljo();

    $innehall = 'Hej Mimers';

    $svar = mcpResultat(mcpVerktyg($token, 'attachments_upload', [
        'item' => $item->ulid,
        'filename' => 'anteckning.txt',
        'content_base64' => base64_encode($innehall),
    ]));

    $bilaga = Attachment::query()->where('item_id', $item->id)->sole();

    expect($bilaga->ulid)->toBe($svar['ulid'])
        ->and($bilaga->filename)->toBe('anteckning.txt')
        ->and($bilaga->billed_account_id)->toBe($agent->account_id)
        ->and($bilaga->uploaded_by_user_id)->toBe($anvandare->id)
        ->and($bilaga->item_id)->toBe($item->id)
        ->and((int) $bilaga->storedFile->byte_size)->toBe(strlen($innehall))
        ->and($bilaga->storedFile->mime_type)->toBe('text/plain')
        ->and($svar['kind'])->toBe('document')
        ->and($svar['byte_size'])->toBe(strlen($innehall));

    // Skrivningen loggas som agentens (Riktning 3).
    expect(AuditLog::query()->where('action', AuditLog::ACTION_ATTACHMENT_CREATED)->sole()->agent_id)
        ->toBe($agent->id);
});

it('har 20 MB som tak för den base64-kodade filen', function () {
    expect(AttachmentsUpload::maxInlineBytes())->toBe(20 * 1024 * 1024);
});

it('avvisar en base64-fil över taket med validation.failed och hänvisar till adressen', function () {
    // Samma spärr som de 20 MB, men med ett litet tak: provet behöver inte
    // bygga en tjugo megabyte stor sträng för att bevisa gränsen.
    config(['mcp.max_inline_upload_bytes' => 16]);

    [$agent, $token, $anvandare, $container, $item] = uppladdningsMiljo();

    $fel = mcpFel(mcpVerktyg($token, 'attachments_upload', [
        'item' => $item->ulid,
        'filename' => 'stor.bin',
        'content_base64' => base64_encode(str_repeat('a', 17)),
    ]));

    expect($fel['code'])->toBe('validation.failed')
        ->and($fel['hint'])->toContain('attachments_upload_url')
        ->and($fel['data']['fields']['content_base64'][0]['code'])->toBe('validation.max')
        ->and($fel['data']['fields']['content_base64'][0]['data']['max'])->toBe(16);

    expect(Attachment::query()->count())->toBe(0);
});

it('avvisar en uppladdning som går över kvoten med quota.storage_exceeded', function () {
    sättPlangräns('pro', 'storage_bytes', 3);

    [$agent, $token, $anvandare, $container, $item] = uppladdningsMiljo();

    $fel = mcpFel(mcpVerktyg($token, 'attachments_upload', [
        'item' => $item->ulid,
        'filename' => 'kvitton.txt',
        'content_base64' => base64_encode('Hej Mimers'),
    ]));

    expect($fel['code'])->toBe('quota.storage_exceeded')
        ->and($fel['data']['limit_bytes'])->toBe(3);

    expect(Attachment::query()->count())->toBe(0);
});

it('avvisar en angiven storlek över planens max_file_bytes', function () {
    sättPlangräns('pro', 'max_file_bytes', 10);

    [$agent, $token, $anvandare, $container, $item] = uppladdningsMiljo();

    $fel = mcpFel(mcpVerktyg($token, 'attachments_upload_url', [
        'item' => $item->ulid,
        'filename' => 'stor.pdf',
        'byte_size' => 11,
    ]));

    expect($fel['code'])->toBe('quota.max_file_size_exceeded')
        ->and($fel['data']['limit_bytes'])->toBe(10)
        ->and($fel['data']['file_bytes'])->toBe(11);
});

it('lämnar en signerad adress som tar emot filen en gång', function () {
    [$agent, $token, $anvandare, $container, $item] = uppladdningsMiljo();

    $adress = mcpResultat(mcpVerktyg($token, 'attachments_upload_url', [
        'item' => $item->ulid,
        'filename' => 'manual.pdf',
        'byte_size' => 100,
    ]));

    expect($adress['method'])->toBe('PUT')
        ->and($adress['single_use'])->toBeTrue()
        ->and($adress['expires_in_minutes'])->toBe(15)
        ->and($adress['filename'])->toBe('manual.pdf')
        ->and($adress['byte_size'])->toBe(100);

    $svar = call('PUT', $adress['upload_url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'filens byten');

    $svar->assertCreated();

    $bilaga = Attachment::query()->where('ulid', $svar->json('ulid'))->sole();

    expect($bilaga->item_id)->toBe($item->id)
        ->and($bilaga->filename)->toBe('manual.pdf')
        ->and($bilaga->billed_account_id)->toBe($agent->account_id)
        ->and(AuditLog::query()->where('action', AuditLog::ACTION_ATTACHMENT_CREATED)->sole()->agent_id)
        ->toBe($agent->id);

    // Andra PUT:en är död.
    call('PUT', $adress['upload_url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'filens byten')->assertStatus(410);

    expect(Attachment::query()->count())->toBe(1);
});

it('avvisar en fil som är större än den angivna storleken och lämnar adressen användbar', function () {
    [$agent, $token, $anvandare, $container, $item] = uppladdningsMiljo();

    $adress = mcpResultat(mcpVerktyg($token, 'attachments_upload_url', [
        'item' => $item->ulid,
        'filename' => 'liten.txt',
        'byte_size' => 5,
    ]));

    call('PUT', $adress['upload_url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], '1234567890')->assertStatus(413);

    expect(Attachment::query()->count())->toBe(0);

    // Ingenting skapades, så agenten får rätta sig och försöka igen.
    call('PUT', $adress['upload_url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], '12345')->assertCreated();

    expect(Attachment::query()->count())->toBe(1);
});

it('stänger den signerade adressen efter 15 minuter', function () {
    [$agent, $token, $anvandare, $container, $item] = uppladdningsMiljo();

    $adress = mcpResultat(mcpVerktyg($token, 'attachments_upload_url', [
        'item' => $item->ulid,
        'filename' => 'sen.pdf',
        'byte_size' => 100,
    ]));

    travel(16)->minutes();

    call('PUT', $adress['upload_url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'filens byten')->assertForbidden();

    expect(Attachment::query()->count())->toBe(0);
});

it('avvisar den signerade adressen när rättigheten har tagits bort efter att den lämnades', function () {
    [$agent, $token, $anvandare, $container, $item] = uppladdningsMiljo();

    $adress = mcpResultat(mcpVerktyg($token, 'attachments_upload_url', [
        'item' => $item->ulid,
        'filename' => 'sen.pdf',
        'byte_size' => 100,
    ]));

    uppladdningsNiva($agent, $container, 'read');

    call('PUT', $adress['upload_url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'filens byten')->assertForbidden();

    expect(Attachment::query()->count())->toBe(0);
});

it('avvisar båda verktygen med agent.forbidden utan create i files', function () {
    [$agent, $token, $anvandare, $container, $item] = uppladdningsMiljo();

    uppladdningsNiva($agent, $container, 'read');

    $fel = mcpFel(mcpVerktyg($token, 'attachments_upload', [
        'item' => $item->ulid,
        'filename' => 'anteckning.txt',
        'content_base64' => base64_encode('Hej'),
    ]));

    expect($fel['code'])->toBe('agent.forbidden');

    expect(mcpFel(mcpVerktyg($token, 'attachments_upload_url', [
        'item' => $item->ulid,
        'filename' => 'anteckning.txt',
        'byte_size' => 3,
    ]))['code'])->toBe('agent.forbidden');

    expect(Attachment::query()->count())->toBe(0);
});

it('avvisar båda verktygen med agent.copy_widens_access efter en läsning i den privata containern', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    $privat = mcpEgenContainer($agent, 'Privat');
    $delad = mcpEgenContainer($agent, 'Delad');

    $medlem = User::factory()->create();
    beviljaAccess($delad, $medlem, 'read', 'guest');

    $privatItem = Item::factory()->create(['container_id' => $privat->id, 'name' => 'Privat batteri']);
    $delatItem = Item::factory()->create(['container_id' => $delad->id, 'name' => 'Delat batteri']);

    // Läsningen i den privata containern: snittet blir {användaren}.
    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $privatItem->ulid]));

    $fel = mcpFel(mcpVerktyg($token, 'attachments_upload', [
        'item' => $delatItem->ulid,
        'filename' => 'kopierat.txt',
        'content_base64' => base64_encode('Hemligt'),
    ]));

    expect($fel['code'])->toBe('agent.copy_widens_access')
        ->and($fel['hint'])->toContain('search with the container filter');

    expect(mcpFel(mcpVerktyg($token, 'attachments_upload_url', [
        'item' => $delatItem->ulid,
        'filename' => 'kopierat.txt',
        'byte_size' => 7,
    ]))['code'])->toBe('agent.copy_widens_access');

    expect(Attachment::query()->count())->toBe(0);
});

it('ger agent.rate_limited på den elfte uppladdningen inom en minut', function () {
    [$agent, $token, $anvandare, $container, $item] = uppladdningsMiljo();

    for ($i = 1; $i <= 10; $i++) {
        mcpResultat(mcpVerktyg($token, 'attachments_upload', [
            'item' => $item->ulid,
            'filename' => 'fil-'.$i.'.txt',
            'content_base64' => base64_encode('Uppladdning '.$i),
        ]));
    }

    $fel = mcpFel(mcpVerktyg($token, 'attachments_upload', [
        'item' => $item->ulid,
        'filename' => 'fil-11.txt',
        'content_base64' => base64_encode('Uppladdning 11'),
    ]));

    expect($fel['code'])->toBe('agent.rate_limited')
        ->and($fel['data']['retry_after_seconds'])->toBeInt()->toBeGreaterThan(0);

    expect(Attachment::query()->count())->toBe(10);
});

it('räknar uppladdningsadressen i samma gräns som den base64-kodade vägen', function () {
    [$agent, $token, $anvandare, $container, $item] = uppladdningsMiljo();

    for ($i = 1; $i <= 10; $i++) {
        mcpResultat(mcpVerktyg($token, 'attachments_upload_url', [
            'item' => $item->ulid,
            'filename' => 'fil-'.$i.'.txt',
            'byte_size' => 10,
        ]));
    }

    expect(mcpFel(mcpVerktyg($token, 'attachments_upload_url', [
        'item' => $item->ulid,
        'filename' => 'fil-11.txt',
        'byte_size' => 10,
    ]))['code'])->toBe('agent.rate_limited');
});
