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
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users');
            $table->string('subject', 60);
            $table->string('title', 160);
            $table->string('publisher', 160)->nullable();
            $table->string('level', 100)->nullable();
            $table->string('reference', 160)->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->boolean('requires_photo')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('book_id');
        });
        Schema::dropIfExists('books');
    }
};
