<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CliOperations
{
    /** @return array{data: mixed, replayed: bool} */
    public static function run(User $parent, string $action, array $payload, ?string $key, bool $dryRun, Closure $operation): array
    {
        ParentActions::authorize($parent);
        Validator::make(['key' => $key], ['key' => 'nullable|string|max:120|regex:/^[A-Za-z0-9._:-]+$/'])->validate();
        $hash = hash('sha256', json_encode(self::canonical([$action, $parent->id, $payload]), JSON_THROW_ON_ERROR));
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            if ($key !== null) {
                DB::table('cli_operations')->insertOrIgnore(['key' => $key, 'actor_id' => $parent->id, 'action' => $action, 'payload_hash' => $hash, 'result' => null, 'created_at' => now(), 'updated_at' => now()]);
                $previous = DB::table('cli_operations')->where('key', $key)->lockForUpdate()->first();
                abort_unless($previous && hash_equals($previous->payload_hash, $hash), 409, 'This retry key was already used with a different action, account, or payload.');
                if ($previous->result !== null) {
                    $result = ['data' => json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR), 'replayed' => true];
                }
            }
            if (! isset($result)) {
                $data = $operation();
                $result = ['data' => $data, 'replayed' => false];
                if ($key !== null) {
                    DB::table('cli_operations')->where('key', $key)->update(['result' => json_encode($data, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                }
            }
            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > $level) {
                DB::rollBack($level);
            }
            throw $exception;
        }
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
