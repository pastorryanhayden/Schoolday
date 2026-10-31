<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('routine_version')->default(0);
        });
        Schema::create('school_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users');
            $table->date('date');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'date']);
        });
        Schema::create('work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_day_id')->constrained();
            $table->foreignId('assignment_id')->nullable()->constrained();
            $table->string('kind');
            $table->string('label');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['school_day_id', 'started_at']);
        });
        Schema::create('timeline_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_day_id')->constrained();
            $table->foreignId('work_session_id')->nullable()->constrained();
            $table->foreignId('editor_id')->constrained('users');
            $table->json('before');
            $table->json('after');
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_corrections');
        Schema::dropIfExists('work_sessions');
        Schema::dropIfExists('school_days');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('routine_version'));
    }
};
