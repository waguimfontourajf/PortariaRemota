<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condominios', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('nome');
            $table->string('cnpj', 18)->nullable()->unique();
            $table->string('telefone', 20)->nullable();
            $table->string('email')->nullable();

            $table->string('cep', 9)->nullable();
            $table->string('logradouro')->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('complemento')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cidade')->nullable();
            $table->char('uf', 2)->nullable();

            $table->string('modo_operacao', 20)->default('direto');
            $table->string('fuso_horario', 64)->default('America/Sao_Paulo');
            $table->boolean('ativo')->default(true);
            $table->text('observacoes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('condominios');
    }
};
