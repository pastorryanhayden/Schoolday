<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private array $passwordFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->passwordFiles as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    private function passwordFile(string $password, int $mode = 0600): string
    {
        $path = tempnam(sys_get_temp_dir(), 'schoolday-password-');
        file_put_contents($path, $password);
        chmod($path, $mode);
        $this->passwordFiles[] = $path;

        return $path;
    }

    public function test_accounts_can_be_provisioned_without_passwords_in_command_arguments_or_output(): void
    {
        $password = 'Unique-test-password-123!';
        $path = $this->passwordFile($password."\n");

        $this->artisan('schoolday:user', ['email' => 'parent@example.com', '--role' => 'parent', '--name' => 'Ryan', '--password-file' => $path, '--no-interaction' => true])
            ->expectsOutput('Account created.')->doesntExpectOutput($password)->assertExitCode(0);

        $user = User::where('email', 'parent@example.com')->firstOrFail();
        $this->assertSame('parent', $user->role);
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_unsafe_or_invalid_password_inputs_leave_no_account(): void
    {
        $this->artisan('schoolday:user', ['email' => 'parent@example.com', '--role' => 'parent', '--password-file' => $this->passwordFile('Valid-but-public-password', 0644), '--no-interaction' => true])->assertExitCode(1);
        $this->artisan('schoolday:user', ['email' => 'parent@example.com', '--role' => 'parent', '--password-file' => $this->passwordFile('short'), '--no-interaction' => true])->assertExitCode(1);
        $this->artisan('schoolday:user', ['email' => 'parent@example.com', '--role' => 'parent', '--password-file' => '/does/not/exist', '--no-interaction' => true])->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_file_reset_revokes_sessions_and_remembered_access(): void
    {
        $user = User::factory()->create(['role' => 'parent', 'remember_token' => 'old-token']);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $path = $this->passwordFile('New-reset-password-123!');

        $this->artisan('schoolday:password', ['email' => $user->email, '--password-file' => $path, '--no-interaction' => true])->expectsOutput('Password changed; previous sessions revoked.')->assertExitCode(0);

        $this->assertTrue(Hash::check('New-reset-password-123!', $user->fresh()->password));
        $this->assertNull($user->fresh()->remember_token);
        $this->assertDatabaseCount('sessions', 0);
    }
}
