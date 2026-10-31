<?php

use App\Models\User;
use App\Services\AccountPassword;
use App\Services\School;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('schoolday:generate {date?}', function () {
    $date = $this->argument('date') ?? today()->toDateString();
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! Carbon::canBeCreatedFromFormat($date, 'Y-m-d')) {
        $this->error('Use a valid YYYY-MM-DD date.');

        return 1;
    }
    School::generate($date);
    $this->info('Recurring assignments ready for '.$date);
})->purpose('Idempotently generate recurring schoolwork for a date');
Schedule::command('schoolday:generate')->dailyAt('00:05');
Artisan::command('schoolday:user {email} {--role=} {--name=} {--password-file= : Private password file, or - for stdin}', function () {
    $role = $this->option('role');
    if (! in_array($role, ['parent', 'student'])) {
        $this->error('--role must be parent or student');

        return 1;
    }
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Use a valid email.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Account already exists.');

        return 1;
    }
    if ($role === 'student' && User::where('role', 'student')->exists()) {
        $this->error('This MVP supports one student.');

        return 1;
    }
    $password = AccountPassword::read($this, 'Password (at least 12 characters)');
    if ($password === null) {

        return 1;
    }
    User::create(['email' => $email, 'name' => $this->option('name') ?? ucfirst($role), 'role' => $role, 'password' => $password]);
    $this->info('Account created.');
});
Artisan::command('schoolday:password {email} {--password-file= : Private password file, or - for stdin}', function () {
    $user = User::where('email', $this->argument('email'))->firstOrFail();
    $password = AccountPassword::read($this, 'New password (at least 12 characters)');
    if ($password === null) {

        return 1;
    }
    $user->update(['password' => $password, 'remember_token' => null]);
    DB::table('sessions')->where('user_id', $user->id)->delete();
    $this->info('Password changed; previous sessions revoked.');
});
