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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id(); // Auto-incrementing ID
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade'); // Foreign key to the users table
            $table->foreignId('booking_id')->nullable()->constrained()->onDelete('cascade'); // Foreign key to the bookings table
            $table->decimal('amount', 10, 2); // Amount of the transaction
            $table->string('currency', 3); // Currency of the transaction (e.g., "USD", "KES")
            $table->string('type'); // Type of transaction: e.g., 'payment', 'refund'
            $table->string('status')->default('pending'); // Transaction status: e.g., 'pending', 'completed', 'failed'
            $table->string('payment_gateway_reference')->nullable(); // Reference ID from the payment gateway
            $table->timestamp('transaction_date')->useCurrent(); // Date and time the transaction occurred
            $table->timestamps(); // created_at and updated_at columns
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
