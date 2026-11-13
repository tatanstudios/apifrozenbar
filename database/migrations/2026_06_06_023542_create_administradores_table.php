<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TABLA ADMINISTRADOR
     * 1- ADMIN TOTAL
     * 2- MESERO
     */
    public function up(): void
    {
        Schema::create('administradores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('email', 100)->unique();
            $table->string('password');
            $table->boolean('estado')->default(true);
            $table->timestamp('ultimo_acceso')->nullable(); // se actualiza en cada login
            // PIN para autorizar en el POS sin dar la contraseña (guardado con hash)
            $table->string('pin')->nullable();
            $table->timestamp('pin_actualizado_at')->nullable();
            $table->rememberToken();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('administradores');
    }
};
