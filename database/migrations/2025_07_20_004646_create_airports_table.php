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
        Schema::create('airports', function (Blueprint $table) {
            $table->id(); // Auto-incrementing ID
            $table->string('iata_code', 3)->unique(); // IATA code (e.g., "JFK", "NBO") - unique identifier
            $table->string('name'); // Full name of the airport (e.g., "Jomo Kenyatta International Airport")
            $table->string('city'); // City where the airport is located
            $table->string('country'); // Country where the airport is located
            $table->string('country_code', 2); // ISO 2-letter country code
            $table->decimal('latitude', 10, 7)->nullable(); // Latitude coordinate
            $table->decimal('longitude', 10, 7)->nullable(); // Longitude coordinate
            $table->string('timezone')->nullable(); // Airport timezone (e.g., "Africa/Nairobi")
            $table->timestamps(); // created_at and updated_at columns
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};
