<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * M82 · issue 471 · *Was this helpful?* på artikelsidan,
 * [[ADR-0094 Hjälpcentret]] § 7 och [[Konton och åtkomst]] § help_feedback.
 *
 * **Ja/Nej och orsaken sparas för alla; fritexten bara för en inloggad.**
 * `user_id` är därför nullbar med flit: en gästs rad har ingen ägare, och
 * strypningen mot missbruk sker i begränsaren per IP och inte i en kolumn här
 * — tabellen bär ingen IP-adress.
 *
 * **`reason` är en uppräkning som VARCHAR med CHECK-villkor**, aldrig MySQL
 * ENUM (AGENTS.md § Databaskonventioner). Villkoret läggs bara på mysql —
 * sqlite saknar ALTER ... ADD CONSTRAINT — men listan finns också i
 * App\Models\HelpFeedback::REASONS, som valideringen läser.
 *
 * **Ingen `deleted_at`.** Ingen kan radera en rad i gränssnittet, och
 * gallringen (App\Console\PrunesHelpFeedback) raderar på riktigt efter
 * 90 dagar. Raden raderas också med personen av App\Actions\User\DeleteUser,
 * och `ON DELETE RESTRICT` gör att den måste bort innan `user`-raden.
 *
 * Indexen är de datamodellen listar: `(article_id, created_at)` för rapporten
 * och `(user_id)` för personraderingen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_feedback', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('article_id', 80);
            $table->string('locale', 10);
            $table->boolean('helpful');
            $table->string('reason', 20)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('user')->onDelete('restrict');
            $table->timestamps();

            $table->index(['article_id', 'created_at']);
            $table->index(['user_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE help_feedback ADD CONSTRAINT help_feedback_reason_check CHECK (reason IS NULL OR reason IN ('unclear', 'incomplete', 'outdated', 'incorrect', 'missing'))");
        }
    }

    /**
     * Migrationer rullas aldrig tillbaka i produktion; down() finns för testmiljön.
     */
    public function down(): void
    {
        Schema::dropIfExists('help_feedback');
    }
};
