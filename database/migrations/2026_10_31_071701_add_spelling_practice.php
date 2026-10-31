<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->json('spelling_words')->nullable();
        });
        Schema::table('practice_attempts', function (Blueprint $table) {
            $table->unsignedTinyInteger('left')->nullable()->change();
            $table->unsignedTinyInteger('right')->nullable()->change();
            $table->string('word', 40)->nullable();
            $table->string('answer_text', 80)->nullable();
            $table->string('mode')->nullable();
            $table->json('choices')->nullable();
            $table->timestamp('covered_at')->nullable();
            $table->unsignedInteger('audio_plays')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('practice_attempts', function (Blueprint $table) {
            $table->dropColumn(['word', 'answer_text', 'mode', 'choices', 'covered_at', 'audio_plays']);
        });
        Schema::table('assignments', fn (Blueprint $table) => $table->dropColumn('spelling_words'));
    }
};
