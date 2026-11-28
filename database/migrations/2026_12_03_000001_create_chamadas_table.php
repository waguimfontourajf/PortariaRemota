<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ramal SIP do interfone e token da "campainha" (botão que chama uma URL).
        Schema::table('dispositivos', function (Blueprint $table) {
            $table->string('ramal', 20)->nullable()->unique()->after('porta');
            $table->string('token_hash', 64)->nullable()->unique()->after('ramal');
        });

        // Interfone que toca para este portão.
        Schema::table('pontos_acesso', function (Blueprint $table) {
            $table->foreignId('interfone_id')->nullable()->after('dispositivo_id')->constrained('dispositivos')->nullOnDelete();
        });

        // Ramal do softphone (navegador) de cada operador.
        Schema::table('users', function (Blueprint $table) {
            $table->string('ramal_sip', 20)->nullable()->unique();
            $table->text('senha_sip')->nullable();
        });

        Schema::create('chamadas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('ponto_acesso_id')->nullable()->constrained('pontos_acesso')->nullOnDelete();
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $table->string('origem', 20);
            $table->string('ramal_origem', 40)->nullable();
            $table->string('id_externo', 100)->nullable()->index();
            $table->string('status', 20)->index();
            $table->foreignId('atendida_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('atendida_em')->nullable();
            $table->timestamp('encerrada_em')->nullable();
            $table->boolean('porta_aberta')->default(false);
            $table->text('observacao')->nullable();
            $table->timestamps();
            $table->index(['condominio_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chamadas');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['ramal_sip', 'senha_sip']));
        Schema::table('pontos_acesso', fn (Blueprint $t) => $t->dropConstrainedForeignId('interfone_id'));
        Schema::table('dispositivos', function (Blueprint $t) {
            $t->dropUnique(['ramal']);
            $t->dropUnique(['token_hash']);
            $t->dropColumn(['ramal', 'token_hash']);
        });
    }
};
