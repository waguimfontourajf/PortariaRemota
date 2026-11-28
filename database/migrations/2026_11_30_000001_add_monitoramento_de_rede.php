<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispositivos', function (Blueprint $table) {
            // Equipamentos de rede (roteador, switch...) não têm driver de relé.
            $table->string('driver', 40)->nullable()->change();

            $table->string('mac', 17)->nullable()->after('porta');
            $table->string('fabricante_modelo')->nullable()->after('mac');

            // Monitoramento
            $table->boolean('monitorar')->default(true)->after('ativo');
            $table->string('metodo_monitoramento', 10)->default('ping')->after('monitorar');
            $table->unsignedInteger('porta_monitoramento')->nullable()->after('metodo_monitoramento');
            $table->string('status', 15)->default('desconhecido')->after('online');
            $table->timestamp('status_desde')->nullable()->after('status');
            $table->unsignedInteger('latencia_ms')->nullable()->after('status_desde');
            $table->unsignedTinyInteger('falhas_seguidas')->default(0)->after('latencia_ms');
            $table->timestamp('verificado_em')->nullable()->after('falhas_seguidas');

            // Posição no mapa
            $table->integer('mapa_x')->nullable();
            $table->integer('mapa_y')->nullable();
        });

        Schema::create('ligacoes_rede', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('origem_id')->constrained('dispositivos')->cascadeOnDelete();
            $table->foreignId('destino_id')->constrained('dispositivos')->cascadeOnDelete();
            $table->string('tipo', 10)->default('cabo');
            $table->string('descricao', 100)->nullable();
            $table->timestamps();

            $table->unique(['origem_id', 'destino_id']);
        });

        Schema::create('quedas_dispositivo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condominios')->cascadeOnDelete();
            $table->foreignId('dispositivo_id')->constrained('dispositivos')->cascadeOnDelete();
            $table->timestamp('inicio');
            $table->timestamp('fim')->nullable();
            $table->string('motivo', 500)->nullable();
            $table->timestamps();

            $table->index(['dispositivo_id', 'inicio']);
            $table->index(['condominio_id', 'fim']);
        });

        Schema::table('condominios', function (Blueprint $table) {
            $table->string('telegram_chat_id', 40)->nullable()->after('observacoes');
            $table->integer('mapa_x')->nullable();
            $table->integer('mapa_y')->nullable();
        });

        // Configurações gerais (valores sensíveis guardados criptografados).
        Schema::create('configuracoes', function (Blueprint $table) {
            $table->string('chave', 100)->primary();
            $table->text('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes');
        Schema::table('condominios', fn (Blueprint $t) => $t->dropColumn(['telegram_chat_id', 'mapa_x', 'mapa_y']));
        Schema::dropIfExists('quedas_dispositivo');
        Schema::dropIfExists('ligacoes_rede');
        Schema::table('dispositivos', fn (Blueprint $t) => $t->dropColumn([
            'mac', 'fabricante_modelo', 'monitorar', 'metodo_monitoramento', 'porta_monitoramento',
            'status', 'status_desde', 'latencia_ms', 'falhas_seguidas', 'verificado_em', 'mapa_x', 'mapa_y',
        ]));
    }
};
