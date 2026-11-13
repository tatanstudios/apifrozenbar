<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CORRELATIVOS DE TICKET
     */
    public function up(): void
    {
        // Numeración consecutiva de documentos (tickets, órdenes de compra, etc.)
        Schema::create('correlativos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 50)->unique(); // venta, orden_compra, compra, produccion...
            $table->string('prefijo', 10)->default('');
            $table->unsignedBigInteger('siguiente')->default(1);
            $table->unsignedTinyInteger('relleno')->default(6); // ceros a la izquierda
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('correlativos');
    }
};
