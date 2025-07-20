<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImpersonationLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('impersonation_logs', function (Blueprint $table) {
            $table->id(); // Auto-incrementing primary key
            // Use UUID or bigInteger based on config
            if (config('guardian.use_uuid', false)) {
                $table->uuid('impersonator_id');
                $table->uuid('impersonated_id');
            } else {
                $table->unsignedBigInteger('impersonator_id');
                $table->unsignedBigInteger('impersonated_id');
            }
            $table->timestamp('started_at'); // Start of impersonation session
            $table->timestamp('ended_at')->nullable(); // End of impersonation session
            $table->string('session_id')->unique(); // Unique session identifier
            $table->timestamps(); // Created and updated timestamps

            // Foreign key constraints
            $table->foreign('impersonator_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('impersonated_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('impersonation_logs');
    }
}