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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone_number')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('phone_country_code')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(false);
            $table->decimal('wallet_balance', 10, 2)->default(0);
            $table->string('agency_name')->nullable();
            $table->string('agency_license')->nullable();
            $table->string('agency_country')->nullable();
            $table->string('agency_city')->nullable();
            $table->string('agency_address')->nullable();
            $table->string('agency_logo')->nullable();
            $table->string('agency_currency')->default('USD');
            $table->decimal('agency_markup', 5, 2)->default(5.00);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
