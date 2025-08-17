<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Segment ↔ Passenger ticket mapping (ticketNums[].passengerIndex & ticketNum)
        Schema::create('booking_segment_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_segment_id');
            $table->unsignedBigInteger('booking_passenger_id');

            $table->string('ticket_num', 64);

            $table->timestamps();

            // Shorter custom index name to avoid MySQL's 64-char limit
            // $table->unique(
            //     ['booking_segment_id','booking_passenger_id'],
            //     'segment_passenger_unique'
            // );
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('booking_segment_tickets');
    }
};
