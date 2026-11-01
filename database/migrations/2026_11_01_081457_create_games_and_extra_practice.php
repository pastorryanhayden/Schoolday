<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users');
            $table->string('title', 160);
            $table->json('configuration');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::table('assignments', function (Blueprint $table) {
            $table->boolean('is_extra_practice')->default(false);
            $table->foreignId('game_id')->nullable()->constrained();
        });
        foreach (DB::table('users')->where('role', 'student')->pluck('id') as $studentId) {
            $config = ['activity_type' => 'multiplication', 'subject' => 'Math', 'title' => 'Times tables', 'instructions' => 'Practice accurate recall, one fact at a time.', 'minutes' => 5, 'fact_min' => 1, 'fact_max' => 12, 'question_target' => null, 'requires_photo' => false];
            DB::table('games')->insert(['student_id' => $studentId, 'title' => $config['title'], 'configuration' => json_encode($config), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $lists = [];
            foreach (DB::table('assignments')->where('student_id', $studentId)->where('activity_type', 'spelling')->orderByDesc('id')->get() as $assignment) {
                $words = json_decode($assignment->spelling_words ?? '[]', true);
                if (! $words || in_array($words, $lists, true)) {
                    continue;
                }
                $lists[] = $words;
                $config = ['activity_type' => 'spelling', 'subject' => $assignment->subject, 'title' => $assignment->title, 'instructions' => $assignment->instructions, 'minutes' => $assignment->minutes, 'question_target' => $assignment->question_target, 'spelling_words' => $words, 'requires_photo' => false];
                DB::table('games')->insert(['student_id' => $studentId, 'title' => $config['title'], 'configuration' => json_encode($config), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('game_id');
            $table->dropColumn('is_extra_practice');
        });
        Schema::dropIfExists('games');
    }
};
