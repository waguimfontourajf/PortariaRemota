<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Horários de acesso do condomínio (ex.: "Diaristas: seg a sex, 7h às 18h").
        // "numero" é a posição do horário nas leitoras (1, 2, 3...), estável.
        Schema::create('horarios_acesso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('nome', 60);
            $table->json('dias'); // 0 = domingo ... 6 = sábado
            $table->time('inicio');
            $table->time('fim');
            $table->timestamps();

            $table->unique(['condominio_id', 'numero']);
        });

        Schema::table('pessoas', function (Blueprint $table) {
            $table->foreignId('horario_acesso_id')->nullable()->after('biometria_autorizada_por')->constrained('horarios_acesso')->nullOnDelete();
            $table->date('acesso_de')->nullable()->after('horario_acesso_id');
            $table->date('acesso_ate')->nullable()->after('acesso_de');
        });
    }

    public function down(): void
    {
        Schema::table('pessoas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('horario_acesso_id');
            $table->dropColumn(['acesso_de', 'acesso_ate']);
        });

        Schema::dropIfExists('horarios_acesso');
    }
};
