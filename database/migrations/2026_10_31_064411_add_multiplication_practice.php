<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->string('activity_type')->default('assignment');
            $table->unsignedTinyInteger('fact_min')->default(1);
            $table->unsignedTinyInteger('fact_max')->default(12);
        });
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('practice_ms')->nullable();
        });
        Schema::create('practice_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('assignment_id')->constrained();
            $table->foreignId('work_session_id')->constrained();
            $table->unsignedInteger('revision');
            $table->unsignedTinyInteger('left');
            $table->unsignedTinyInteger('right');
            $table->timestamp('issued_at');
            $table->timestamp('answered_at')->nullable();
            $table->unsignedSmallInteger('answer')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->unsignedInteger('credited_ms')->default(0);
            $table->unsignedInteger('feedback_ms')->default(0);
            $table->boolean('feedback_recorded')->default(false);
            $table->boolean('correct')->nullable();
            $table->boolean('interrupted')->default(false);
            $table->timestamps();
            $table->index(['student_id', 'answered_at']);
            $table->index(['assignment_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_attempts');
        Schema::table('work_sessions', fn (Blueprint $table) => $table->dropColumn('practice_ms'));
        Schema::table('assignments', fn (Blueprint $table) => $table->dropColumn(['activity_type', 'fact_min', 'fact_max']));
    }
};
