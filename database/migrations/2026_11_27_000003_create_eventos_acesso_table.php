<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Histórico de acionamentos. Nunca é editado nem apagado pela aplicação.
        Schema::create('eventos_acesso', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('ponto_acesso_id')->nullable()->constrained('pontos_acesso')->nullOnDelete();
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pessoa_id')->nullable()->constrained('pessoas')->nullOnDelete();
            $table->string('ponto_nome')->nullable(); // cópia, para o histórico sobreviver a exclusões
            $table->string('usuario_nome')->nullable();
            $table->string('origem', 20);
            $table->string('acao', 20);
            $table->string('resultado', 20);
            $table->string('detalhe', 1000)->nullable();
            $table->unsignedInteger('duracao_ms')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['condominio_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_acesso');
    }
};
