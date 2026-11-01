<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GameLibrary
{
    public const FIELDS = ['activity_type', 'title', 'instructions', 'minutes', 'question_target', 'fact_min', 'fact_max', 'spelling_words', 'active'];

    public static function save(User $parent, array $data, ?int $id = null): Game
    {
        ParentActions::authorize($parent);
        Validator::make(['game' => $data], ['game' => 'required|array:'.implode(',', self::FIELDS), 'game.active' => 'sometimes|boolean'])->validate();

        return DB::transaction(function () use ($parent, $data, $id): Game {
            $student = ParentActions::student();
            $game = $id ? Game::where('student_id', $student->id)->lockForUpdate()->findOrFail($id) : null;
            $active = $data['active'] ?? $game?->active ?? true;
            unset($data['active']);
            $config = array_replace($game?->configuration ?? [], $data);
            Validator::make($config, ['activity_type' => 'required|in:multiplication,spelling'])->validate();
            $config['subject'] = $config['activity_type'] === 'spelling' ? 'Spelling' : 'Math';
            $config = ParentActions::validateAssignment(ParentActions::assignmentDefaults($config) + ['date' => today()->toDateString(), 'position' => 0]);
            unset($config['date'], $config['position'], $config['book_id']);
            $values = ['title' => $config['title'], 'configuration' => $config, 'active' => $active];
            if ($game) {
                $game->update($values);
            } else {
                $game = Game::create($values + ['student_id' => $student->id]);
            }
            School::record($parent, 'game_saved', ($active ? 'Saved game: ' : 'Archived game: ').$game->title);

            return $game->fresh();
        });
    }

    public static function play(User $student, Game $game, int $version): Assignment
    {
        abort_unless($student->role === 'student' && $game->student_id === $student->id, 403);

        return DB::transaction(function () use ($student, $game, $version): Assignment {
            $student = DailyRoutine::lock($student, $version);
            $game = Game::lockForUpdate()->findOrFail($game->id);
            abort_unless($game->active, 409, 'This game has been archived. Choose another game.');
            abort_unless(DailyRoutine::openDay($student), 409, 'Start your schoolday before playing a game.');
            $config = ParentActions::validateAssignment(ParentActions::assignmentDefaults($game->configuration) + ['date' => today()->toDateString(), 'position' => 999]);
            $assignment = Assignment::create($config + ['student_id' => $student->id, 'game_id' => $game->id, 'is_extra_practice' => true]);
            DailyRoutine::transition($student, 'activity-start', $student->routine_version, $assignment->id);
            School::record($student, 'extra_practice', 'Opened extra practice: '.$game->title, $assignment);

            return $assignment->fresh();
        }, 3);
    }
}
