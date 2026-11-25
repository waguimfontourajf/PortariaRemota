<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pessoas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nome');
            $table->string('tipo', 20)->default('morador');
            $table->string('cpf', 14)->nullable();
            $table->string('rg', 20)->nullable();
            $table->date('data_nascimento')->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('foto_path')->nullable();
            $table->boolean('ativo')->default(true);
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['condominio_id', 'cpf']);
            $table->unique(['condominio_id', 'user_id']);
            $table->index(['condominio_id', 'nome']);
        });

        Schema::create('pessoa_unidade', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pessoa_id')->constrained('pessoas')->cascadeOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->cascadeOnDelete();
            $table->string('vinculo', 20)->default('proprietario');
            $table->boolean('responsavel')->default(false);
            $table->timestamps();

            $table->unique(['pessoa_id', 'unidade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pessoa_unidade');
        Schema::dropIfExists('pessoas');
    }
};
