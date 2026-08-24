<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 5 · Magic link. Se [[ADR-0011 Autentisering]] § Konsekvenser:
 * "Magic link-tokens lagras som hash, är engångs, kortlivade och bundna
 * till e-postadressen."
 *
 * `email` (inte `user_id`) är det som binder token — se issue #18 § Beslut
 * som redan är fattade punkt 2: "Bunden till e-postadressen ... Adressen
 * ingår i det som verifieras, inte bara i uppslaget." En rad skapas bara
 * för en e-postadress som redan har en användare (se
 * App\Support\Auth\MagicLinkBroker::issue(), beslut 6 om att inte röja om
 * adressen finns), men kolumnen lagrar adressen direkt i stället för en
 * främmande nyckel till `user` — annars vore verifieringen ett uppslag via
 * en relation, inte den fristående jämförelse beslutet kräver, och en
 * användares e-postbyte mellan utfärdande och inlösen skulle tyst ändra
 * vilken adress ett redan utfärdat token gäller för. Samma resonemang som
 * `account_user`-migrationen: ingen `ulid` här heller, tabellen exponeras
 * aldrig som en egen resurs i API:et.
 *
 * `token_hash` är en SHA-256-hex av slumpen som skickas i länken — själva
 * slumpen lagras aldrig, se beslut 1. `used_at` gör engångsanvändning
 * möjlig utan en separat borttagning; `expires_at` gör livslängden
 * kontrollerbar utan ett schemalagt jobb. Inget soft delete — det här är
 * inte användarskapat innehåll (AGENTS.md § Databaskonventioner), utan en
 * kortlivad säkerhetsartefakt, samma resonemang som Sanctums egen
 * `personal_access_tokens`-tabell.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('magic_link_token', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('magic_link_token');
    }
};
