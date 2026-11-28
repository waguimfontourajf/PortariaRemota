<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Câmeras que mostram cada portão (aparecem junto do botão ABRIR e, no futuro, quando o interfone tocar).
        Schema::create('camera_ponto_acesso', function (Blueprint $table) {
            $table->foreignId('ponto_acesso_id')->constrained('pontos_acesso')->cascadeOnDelete();
            $table->foreignId('dispositivo_id')->constrained('dispositivos')->cascadeOnDelete();
            $table->unsignedTinyInteger('ordem')->default(0);
            $table->primary(['ponto_acesso_id', 'dispositivo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camera_ponto_acesso');
    }
};
