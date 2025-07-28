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
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name')->default('FlyGasal');
            $table->string('default_currency')->default('usd');
            $table->string('timezone')->nullable();
            $table->string('language')->default('en');
            $table->integer('login_attemps')->default(5);
            $table->boolean('email_notification')->default(true);
            $table->boolean('sms_notification')->default(false);
            $table->boolean('booking_confirmation_email')->default(true);
            $table->boolean('booking_confirmation_sms')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
