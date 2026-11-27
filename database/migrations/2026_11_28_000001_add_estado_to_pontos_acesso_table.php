<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pontos_acesso', function (Blueprint $table) {
            // Último estado conhecido (pontos "liga / desliga").
            $table->boolean('ligado')->nullable()->after('ativo');
            $table->timestamp('estado_em')->nullable()->after('ligado');
        });
    }

    public function down(): void
    {
        Schema::table('pontos_acesso', function (Blueprint $table) {
            $table->dropColumn(['ligado', 'estado_em']);
        });
    }
};
