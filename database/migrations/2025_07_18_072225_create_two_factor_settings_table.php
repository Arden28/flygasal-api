<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTwoFactorSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('two_factor_settings', function (Blueprint $table) {
            $table->id(); // Auto-incrementing primary key
            // Use UUID or bigInteger based on config
            if (config('guardian.use_uuid', false)) {
                $table->uuid('user_id');
            } else {
                $table->unsignedBigInteger('user_id');
            }
            $table->string('method')->nullable(); // 2FA method (email, sms, totp)
            $table->string('secret')->nullable(); // Encrypted secret for TOTP
            $table->string('phone_number')->nullable(); // Phone number for SMS 2FA
            $table->boolean('is_enabled')->default(false); // 2FA enabled status
            $table->timestamp('last_verified_at')->nullable(); // Last 2FA verification
            $table->timestamps(); // Created and updated timestamps

            // Foreign key constraint
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('two_factor_settings');
    }
}