<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pessoas', function (Blueprint $table) {
            // LGPD: a foto só vai para leitoras faciais com consentimento registrado.
            $table->timestamp('biometria_autorizada_em')->nullable()->after('foto_path');
            $table->foreignId('biometria_autorizada_por')->nullable()->after('biometria_autorizada_em')->constrained('users')->nullOnDelete();
        });

        // O que já foi enviado para cada leitora (para atualizar só o necessário
        // e saber quem remover).
        Schema::create('dispositivo_pessoa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispositivo_id')->constrained('dispositivos')->cascadeOnDelete();
            $table->foreignId('pessoa_id')->constrained('pessoas')->cascadeOnDelete();
            $table->string('assinatura', 64)->nullable(); // hash de nome + foto enviados
            $table->timestamp('sincronizado_em')->nullable();
            $table->string('erro', 500)->nullable();
            $table->timestamps();

            $table->unique(['dispositivo_id', 'pessoa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivo_pessoa');

        Schema::table('pessoas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('biometria_autorizada_por');
            $table->dropColumn('biometria_autorizada_em');
        });
    }
};
