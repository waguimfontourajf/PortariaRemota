<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pontos_acesso', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('nome');
            $table->string('tipo', 20)->default('portao_social');
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $table->unsignedTinyInteger('canal')->default(1);
            $table->string('modo_acionamento', 20)->default('pulso');
            $table->unsignedInteger('tempo_pulso_ms')->default(1000);
            $table->boolean('liberado_moradores')->default(false);
            $table->boolean('ativo')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pontos_acesso');
    }
};
