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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id(); // Auto-incrementing ID
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Foreign key to the users table
            $table->string('pkfare_booking_reference')->unique()->nullable(); // Unique reference from PKfare API (PNR)
            $table->string('status')->default('pending'); // Booking status: e.g., 'pending', 'confirmed', 'ticketed', 'cancelled', 'failed'
            $table->decimal('total_price', 10, 2); // Total price of the booking
            $table->string('currency', 3); // Currency of the total price (e.g., "USD", "KES")
            $table->json('flight_details'); // JSON blob to store flight segments, times, airlines, etc. from PKfare response
            $table->json('passenger_details'); // JSON blob to store passenger names, types, contact info
            $table->string('contact_email')->nullable(); // Contact email for the booking
            $table->string('contact_phone')->nullable(); // Contact phone number for the booking
            $table->timestamp('booking_date')->useCurrent(); // Date and time the booking was initiated
            $table->timestamps(); // created_at and updated_at columns
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
