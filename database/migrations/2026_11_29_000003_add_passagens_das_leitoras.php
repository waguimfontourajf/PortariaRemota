<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Passagens importadas das leitoras: identificador do evento na leitora
        // (para nunca importar duas vezes).
        Schema::table('eventos_acesso', function (Blueprint $table) {
            $table->string('referencia_externa', 100)->nullable()->after('dispositivo_id');
            $table->unique(['dispositivo_id', 'referencia_externa']);
        });

        // Até onde as passagens de cada leitora já foram importadas.
        Schema::table('dispositivos', function (Blueprint $table) {
            $table->string('eventos_cursor', 100)->nullable()->after('ultimo_erro');
            $table->timestamp('eventos_importados_em')->nullable()->after('eventos_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_acesso', function (Blueprint $table) {
            $table->dropUnique(['dispositivo_id', 'referencia_externa']);
            $table->dropColumn('referencia_externa');
        });

        Schema::table('dispositivos', function (Blueprint $table) {
            $table->dropColumn(['eventos_cursor', 'eventos_importados_em']);
        });
    }
};
