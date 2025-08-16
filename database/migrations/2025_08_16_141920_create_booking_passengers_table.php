<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();

            $table->unsignedInteger('passenger_index')->index(); // aligns with webhook passengerIndex
            $table->string('psg_type', 8)->index();              // ADT/CHD/INF
            $table->string('sex', 4)->nullable();
            $table->date('birthday')->nullable();

            // Name & nationality
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('nationality', 4)->nullable();        // country code

            // Travel document
            $table->string('card_type', 8)->nullable();          // PP / N / O ...
            $table->string('card_num', 64)->nullable();
            $table->date('card_expired_date')->nullable();

            // Issued ticket (concatenated; per-segment mapping stored in pivot below)
            $table->string('ticket_num', 64)->nullable();

            // Infant linkage (associatedPassengerIndex for INF)
            $table->unsignedInteger('associated_passenger_index')->nullable();

            $table->timestamps();

            $table->unique(['booking_id','passenger_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_passengers');
    }
};
