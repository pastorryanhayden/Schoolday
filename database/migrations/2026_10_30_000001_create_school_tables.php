<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('student');
            $t->timestamp('last_seen_at')->nullable();
            $t->boolean('on_break')->default(false);
        });
        Schema::create('recurrences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('users');
            $t->json('weekdays');
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->json('assignment_data');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('users');
            $t->foreignId('recurrence_id')->nullable()->constrained();
            $t->date('date');
            $t->string('subject');
            $t->string('title');
            $t->text('instructions');
            $t->string('reference')->nullable();
            $t->unsignedSmallInteger('minutes')->nullable();
            $t->boolean('requires_photo')->default(false);
            $t->unsignedInteger('position')->default(0);
            $t->string('status')->default('not_started');
            $t->text('note')->nullable();
            $t->text('feedback')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->timestamps();
            $t->unique(['recurrence_id', 'date']);
        });
        Schema::create('photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('name');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sender_id')->constrained('users');
            $t->foreignId('student_id')->constrained('users');
            $t->foreignId('assignment_id')->nullable()->constrained()->nullOnDelete();
            $t->text('body');
            $t->boolean('help')->default(false);
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('assignment_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind');
            $t->text('description');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['activities', 'messages', 'photos', 'assignments', 'recurrences'] as $table) {
            Schema::dropIfExists($table);
        } Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'last_seen_at', 'on_break']));
    }
};
