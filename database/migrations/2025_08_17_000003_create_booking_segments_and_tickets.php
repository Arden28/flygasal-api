<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Booking segments table
        Schema::create('booking_segments', function (Blueprint $table) {
            $table->id();

            // Relation to the parent booking, delete all segments if booking is deleted
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();

            // Segment order within a booking (1, 2, 3...). Ensures uniqueness per booking.
            $table->unsignedInteger('segment_no')->index();

            // Identifiers
            $table->string('segmentId')->nullable();           // External system segment ID (e.g., from API/webhook)
            $table->string('airline', 8)->nullable();          // Airline IATA code (e.g., "JM")
            $table->string('equipment', 8)->nullable();        // Aircraft type code (e.g., "DH4")

            // Terminals
            $table->string('departure_terminal', 8)->nullable();  // Departure terminal (e.g., "1D")
            $table->string('arrival_terminal', 8)->nullable();    // Arrival terminal (e.g., "2")

            // Dates & times (timestamp = date + time in UTC)
            $table->timestamp('departure_date')->nullable();
            $table->timestamp('arrival_date')->nullable();

            // Airports
            $table->string('departure', 8);                  // Departure airport IATA code (e.g., "NBO")
            $table->string('arrival', 8);                    // Arrival airport IATA code (e.g., "MBA")

            // Flight information
            $table->string('flight_num', 16)->nullable();    // Flight number (e.g., "8608")
            $table->string('air_pnr', 64)->nullable();       // Airline-issued PNR for this segment
            $table->string('pnr', 64)->nullable();           // Global Distribution System (GDS) PNR
            $table->string('cabin_class', 24)->nullable();   // Travel class (ECONOMY, BUSINESS, etc.)
            $table->string('booking_code', 8)->nullable();   // Fare booking code (e.g., "V")

            // Laravel timestamps (created_at, updated_at)
            $table->timestamps();

            // Ensure uniqueness of segment_no per booking
            $table->unique(['booking_id','segment_no']);
        });


        // Segment ↔ Passenger ticket mapping (ticketNums[].passengerIndex & ticketNum)
        Schema::create('booking_segment_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_segment_id')->constrained('booking_segments')->cascadeOnDelete();
            $table->foreignId('booking_passenger_id')->constrained('booking_passengers')->cascadeOnDelete();

            $table->string('ticket_num', 64); // exact ticket number used on this segment

            $table->timestamps();

            $table->unique(['booking_segment_id','booking_passenger_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_segment_tickets');
        Schema::dropIfExists('booking_segments');
    }
};
