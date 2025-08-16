<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Core identifiers from webhook
            $table->string('air_pnr')->nullable()->after('pnr');
            $table->string('merchant_order')->nullable()->after('order_num');
            $table->string('buyer_order')->nullable();
            $table->string('serial_num')->nullable();

            // Issuance state
            $table->string('issue_status', 16)->default('PENDING')->index()->after('status');
            $table->string('inform_type', 24)->nullable();
            $table->text('reject_reason')->nullable();
            $table->text('issue_remark')->nullable();

            // Payment/void window
            $table->string('payment_gate', 32)->nullable();
            $table->unsignedTinyInteger('permit_void')->default(0);
            $table->timestamp('last_void_time')->nullable();
            $table->decimal('void_service_fee', 12, 2)->nullable();
            $table->string('void_currency', 8)->nullable();

            // Totals
            $table->decimal('total_tax', 12, 2)->nullable();
            $table->decimal('total_fare', 12, 2)->nullable();

            // Snapshot
            $table->json('ticket_issued_payload')->nullable();

            // Indexes
            $table->index('order_num');
            $table->index('pnr');
            $table->index('air_pnr');

            // Adjust existing columns to support pre/post issuance flows
            $table->decimal('adt_fare', 10, 2)->nullable()->change();
            $table->decimal('adt_tax', 10, 2)->nullable()->change();
            $table->decimal('chd_fare', 10, 2)->nullable()->change();
            $table->decimal('chd_tax', 10, 2)->nullable()->change();
            $table->decimal('total_amount', 10, 2)->nullable()->change();
            $table->string('pnr')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_order_num_index');
            $table->dropIndex('bookings_pnr_index');
            $table->dropIndex('bookings_air_pnr_index');

            $table->dropColumn([
                'air_pnr','merchant_order','buyer_order','serial_num',
                'issue_status','inform_type','reject_reason','issue_remark',
                'payment_gate','permit_void','last_void_time','void_service_fee','void_currency',
                'total_tax','total_fare','ticket_issued_payload',
            ]);

            // 👇 Optional: restore old NOT NULL constraints if you want strict reversibility
            // $table->decimal('adt_fare', 10, 2)->nullable(false)->change();
            // $table->decimal('adt_tax', 10, 2)->nullable(false)->change();
            // $table->decimal('chd_fare', 10, 2)->nullable(false)->change();
            // $table->decimal('chd_tax', 10, 2)->nullable(false)->change();
            // $table->decimal('total_amount', 10, 2)->nullable(false)->change();
            // $table->string('pnr')->nullable(false)->change();
        });
    }
};
