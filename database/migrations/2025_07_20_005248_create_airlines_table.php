<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('airlines', function (Blueprint $table) {
            $table->id(); // Auto-incrementing ID
            $table->string('iata_code', 2)->unique(); // IATA code (e.g., "KQ", "LH") - unique identifier
            $table->string('name'); // Full name of the airline (e.g., "Kenya Airways")
            $table->string('logo_url')->nullable(); // URL to the airline's logo (optional)
            $table->timestamps(); // created_at and updated_at columns
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airlines');
    }
};
