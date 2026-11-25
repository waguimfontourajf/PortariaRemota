<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispositivos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->string('nome');
            $table->string('tipo', 20)->default('rele');
            $table->string('driver', 40);
            $table->string('host')->nullable();
            $table->unsignedInteger('porta')->nullable();
            $table->unsignedTinyInteger('canais')->default(1);
            $table->text('config')->nullable(); // criptografado (senhas, chaves)
            $table->boolean('ativo')->default(true);
            $table->boolean('online')->nullable();
            $table->timestamp('ultimo_contato_em')->nullable();
            $table->string('ultimo_erro', 500)->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['condominio_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos');
    }
};
