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
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade'); // Foreign key to the users table

            $table->string('order_num')->unique();     // orderNum
            $table->string('pnr');                     // pnr
            $table->text('solution_id')->nullable();                     // solutionId

            $table->string('fare_type')->nullable();               // solution.fareType
            $table->string('currency')->nullable();                // solution.currency

            $table->decimal('adt_fare', 10, 2);        // solution.adtFare
            $table->decimal('adt_tax', 10, 2);         // solution.adtTax
            $table->decimal('chd_fare', 10, 2);        // solution.chdFare
            $table->decimal('chd_tax', 10, 2);         // solution.chdTax

            $table->unsignedTinyInteger('infants')->default(0);    // solution.infants
            $table->unsignedTinyInteger('adults')->default(0);     // solution.adults
            $table->unsignedTinyInteger('children')->default(0);   // solution.children

            $table->string('plating_carrier')->nullable();         // solution.platingCarrier

            $table->json('baggage_info')->nullable();              // solution.baggageMap
            $table->json('flights')->nullable();                   // flights array
            $table->json('segments')->nullable();                  // segments array
            $table->json('passengers')->nullable();                  // passengers array

            $table->decimal('agent_fee', 10, 2)->default(0);    // agent fees
            $table->decimal('total_amount', 10, 2);    // adt + chd fare + taxes
            $table->timestamp('booking_date')->useCurrent(); // Date and time the booking was initiated
            $table->string('status')->default('pending');         // Booking Status (pending, confirmed)
            $table->string('payment_status')->default('unpaid');         // Booking Payment Status (unpaid, paid, refunded)
            $table->string('contact_name')->nullable(); // Contact name for the booking
            $table->string('contact_email')->nullable(); // Contact email for the booking
            $table->string('contact_phone')->nullable(); // Contact phone number for the booking
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
