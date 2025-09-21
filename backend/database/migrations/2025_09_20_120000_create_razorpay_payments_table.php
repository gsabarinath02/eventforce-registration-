<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('razorpay_payments', static function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('razorpay_order_id');
            $table->string('razorpay_payment_id')->nullable();
            $table->string('razorpay_signature')->nullable();
            $table->bigInteger('amount_received')->nullable();
            $table->string('refund_id')->nullable();
            $table->json('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign key constraint
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');

            // Indexes for performance
            $table->index('order_id');
            $table->index('razorpay_order_id');
            $table->index('razorpay_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('razorpay_payments');
    }
};