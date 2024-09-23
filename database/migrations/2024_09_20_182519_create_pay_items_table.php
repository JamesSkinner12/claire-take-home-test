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
        Schema::create('pay_items', function (Blueprint $table) {
            $table->id();
            $table->float('amount');
            $table->float('pay_rate');
            $table->float('hours');
            $table->string('external_id');
            $table->date('pay_date')->useCurrent();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('business_id')->constrained();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pay_items');
    }
};
