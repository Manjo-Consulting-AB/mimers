<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 243 (M27) · Inboxen. Se [[ADR-0054 Inboxen]] § 1 och
 * [[Konton och åtkomst]] § container.
 *
 * `container.inbox_user_id` är det ENDA som gör en container till en inbox:
 * en nullbar främmande nyckel mot `user` med ett UNIKT index. `kind` används
 * inte till det, eftersom [[ADR-0036 Containerns art]] förbjuder att `kind`
 * styr beteende. Det unika indexet är skyddet mot dubbletter när två
 * förfrågningar skapar samma användares inbox samtidigt — den ena kolliderar
 * i stället för att skriva en andra rad
 * (App\Actions\Inbox\ResolveInbox).
 *
 * Nyckeln är ON DELETE RESTRICT som husets: en person med en inbox kvar får
 * inte raderas under den. Personraderingen tar inboxen först
 * (App\Actions\User\DeleteUser).
 *
 * **Additiv och utan att en enda befintlig rad skrivs om.** Kolumnen är
 * nullbar, och NULL är "ingen inbox" för varje container som finns i dag —
 * ingen användare behöver en migrering för att få sin (ADR-0054 § 1). Det
 * unika indexet tillåter flera NULL på både mysql och sqlite.
 *
 * Kolumnen läggs efter `account_id`: den hör till containerns identitet och
 * ägare, inte till dess innehåll.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('container', function (Blueprint $table) {
            $table->foreignId('inbox_user_id')
                ->nullable()
                ->after('account_id')
                ->unique()
                ->constrained('user')
                ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('container', function (Blueprint $table) {
            $table->dropForeign(['inbox_user_id']);
            $table->dropUnique(['inbox_user_id']);
            $table->dropColumn('inbox_user_id');
        });
    }
};
