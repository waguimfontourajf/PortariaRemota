<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Autorização de visitante: um link (com QR code) que o morador envia.
        Schema::create('visitas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // quem autorizou
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->string('nome');
            $table->string('documento', 30)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('observacao', 500)->nullable();
            // O link é procurado pelo hash; o token em si fica criptografado (para reenviar).
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->timestamp('valido_de');
            $table->timestamp('valido_ate');
            $table->unsignedSmallInteger('usos_maximos')->default(1);
            $table->unsignedSmallInteger('usos')->default(0);
            $table->timestamp('revogada_em')->nullable();
            $table->timestamps();

            $table->index(['condominio_id', 'valido_ate']);
        });

        Schema::create('ponto_acesso_visita', function (Blueprint $table) {
            $table->foreignId('visita_id')->constrained('visitas')->cascadeOnDelete();
            $table->foreignId('ponto_acesso_id')->constrained('pontos_acesso')->cascadeOnDelete();
            $table->primary(['visita_id', 'ponto_acesso_id']);
        });

        Schema::table('pontos_acesso', function (Blueprint $table) {
            $table->boolean('liberado_visitantes')->default(false)->after('liberado_moradores');
        });

        Schema::table('eventos_acesso', function (Blueprint $table) {
            $table->foreignId('visita_id')->nullable()->after('pessoa_id')->constrained('visitas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('eventos_acesso', fn (Blueprint $t) => $t->dropConstrainedForeignId('visita_id'));
        Schema::table('pontos_acesso', fn (Blueprint $t) => $t->dropColumn('liberado_visitantes'));
        Schema::dropIfExists('ponto_acesso_visita');
        Schema::dropIfExists('visitas');
    }
};
