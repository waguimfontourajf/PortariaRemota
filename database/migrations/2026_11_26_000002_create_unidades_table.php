<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('bloco_id')->nullable()->constrained('blocos')->restrictOnDelete();
            $table->string('numero', 20);
            $table->string('andar', 10)->nullable();
            $table->string('tipo', 20)->default('apartamento');
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->unique(['condominio_id', 'bloco_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades');
    }
};
