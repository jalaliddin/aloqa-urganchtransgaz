<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap-free yearly counters for registry / document numbers (row-locked on use)
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('value')->default(0);
            $table->timestamps();
            $table->unique(['name', 'year']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('sequences');
    }
};
