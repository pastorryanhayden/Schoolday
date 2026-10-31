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
        Schema::create('cli_operations', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action');
            $table->string('payload_hash', 64);
            $table->longText('result')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cli_operations');
    }
};
