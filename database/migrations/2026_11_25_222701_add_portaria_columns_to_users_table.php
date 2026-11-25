<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->boolean('super_admin')->default(false)->after('password');
            $table->boolean('ativo')->default(true)->after('super_admin');
            $table->timestamp('ultimo_login_em')->nullable()->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'super_admin', 'ativo', 'ultimo_login_em']);
        });
    }
};
