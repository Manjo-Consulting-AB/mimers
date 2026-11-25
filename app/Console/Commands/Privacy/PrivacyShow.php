<?php

namespace App\Console\Commands\Privacy;

use App\Actions\Privacy\ShowPrivacyRequest;
use App\Models\LegalHold;
use App\Models\PrivacyRequest;
use Illuminate\Console\Command;

/**
 * `privacy:show` — visa ett ärende och alla händelser, och skriv att det
 * lästes. Se [[ADR-0095 Integritetsbegäranden]] § 3.
 *
 * Läsningen ska gå att följa: händelsen `viewed` bär handläggarens namn ur
 * `--by`, precis som `privacy:open` bär det på `created`. Kommandot
 * schemaläggs aldrig — åtkomsten till ärendena är åtkomsten till servern
 * (ADR-0095 § 1).
 *
 * Några rader i utskriften är varningar till handläggaren och syns aldrig för
 * den registrerade: *Restriction active* med sin omfattning högst upp när en
 * begränsning gäller (ADR-0095 § 8), *Legal hold active* när ärendets konto
 * omfattas av en gällande spärr ([[ADR-0043 Tre loggar]] § Den rättsliga
 * spärren), och *Self-service is not a complete response* under en
 * `self_service`-händelse (ADR-0095 § 9).
 *
 * Logiken bor i App\Actions\Privacy\ShowPrivacyRequest, testad direkt.
 */
class PrivacyShow extends Command
{
    protected $signature = 'privacy:show
                            {reference : Ärendets referens, PR-XXXXXXXX}
                            {--by= : Handläggarens namn}';

    protected $description = 'Visa ett ärende och dess händelser.';

    public function handle(ShowPrivacyRequest $show): int
    {
        $by = $this->text('by');

        if ($by === null) {
            $this->error('--by måste anges: handläggarens namn.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');

        $request = PrivacyRequest::query()
            ->where('reference', $reference)
            ->first();

        if ($request === null) {
            // Ingen händelse skrivs: ett okänt ärende finns inte att läsa,
            // och en `viewed`-rad hade påstått motsatsen.
            $this->error("Inget ärende med referensen {$reference}.");

            return self::FAILURE;
        }

        $resultat = $show->handle($request, $by);

        // Begränsningen är driftinstruktionen och står överst: den som läser
        // ärendet ska veta vilka uppgifter som inte får användas, och för
        // vilka ändamål, medan den gäller (ADR-0095 § 8). Den ändrar inget i
        // appen.
        if ($request->hasActiveRestriction()) {
            $this->line("Restriction active: {$request->restriction_scope}");
        }

        // Den rättsliga spärren syns för handläggaren och aldrig för den
        // registrerade (ADR-0043 § Den rättsliga spärren). Raden står före
        // ärendets uppgifter: den som läser ärendet ska veta att gallringen
        // står still innan hen tolkar resten.
        if ($request->account !== null && LegalHold::covers($request->account)) {
            $this->line('Legal hold active');
        }

        $this->line("Referens: {$request->reference}");
        $this->line("Typ: {$request->type}");
        $this->line("Status: {$request->status}");
        $this->line("Prioritet: {$request->priority}");
        $this->line("Roll: {$request->role}");
        $this->line('Mottaget: '.$request->received_at->format('Y-m-d'));
        $this->line('Frist: '.$request->deadline_at->format('Y-m-d'));
        $this->line("Namn: {$request->requester_name}");
        $this->line("E-post: {$request->requester_email}");

        if ($request->on_behalf_of !== null) {
            $this->line("Ombud för: {$request->on_behalf_of}");
        }

        if ($request->account_id !== null) {
            $this->line("Konto: {$request->account_id}");
        }

        if ($request->user_id !== null) {
            $this->line("Person: {$request->user_id}");
        }

        if ($request->assignee !== null) {
            $this->line("Handläggare: {$request->assignee}");
        }

        $this->line("Beskrivning: {$request->description}");

        $this->line('Händelser:');

        foreach ($resultat['events'] as $event) {
            $this->line(
                $event->created_at->format('Y-m-d H:i')
                ." · {$event->kind} · {$event->actor}"
            );

            if ($event->body !== null) {
                $this->line('    '.$event->body);
            }

            // Självbetjäningen är inte ett fullgörande (ADR-0095 § 9), och
            // påminnelsen står under själva händelsen så att den som läser
            // historiken inte tar en hänvisning för ett svar.
            if (
                $event->kind === 'action'
                && ($event->data['operation'] ?? null) === 'self_service'
            ) {
                $this->line('    Self-service is not a complete response');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Ett optionvärde som text, eller null när det inte angetts. Samma
     * hjälpare som VoucherCreate och PrivacyOpen använder.
     */
    private function text(string $name): ?string
    {
        $varde = $this->option($name);

        return is_string($varde) && $varde !== '' ? $varde : null;
    }
}
