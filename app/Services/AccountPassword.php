<?php

namespace App\Services;

use Illuminate\Console\Command;

class AccountPassword
{
    public static function read(Command $command, string $prompt): ?string
    {
        $path = $command->option('password-file');
        if ($path === null) {
            $password = $command->secret($prompt);
        } elseif ($path === '-') {
            if (function_exists('stream_isatty') && stream_isatty(STDIN)) {
                $command->error('Pipe the password to stdin, or provide a protected password file.');

                return null;
            }
            $password = stream_get_contents(STDIN, 1025);
        } elseif (is_file($path) && is_readable($path)) {
            if (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0077) !== 0) {
                $command->error('Password files must be private: chmod 600 the file first.');

                return null;
            }
            $password = file_get_contents($path, length: 1025);
        } else {
            $command->error('Password file cannot be read.');

            return null;
        }
        $password = is_string($password) ? rtrim($password, "\r\n") : '';
        if (strlen($password) < 12 || strlen($password) > 1024 || str_contains($password, "\n") || str_contains($password, "\r")) {
            $command->error('Use a single password of 12–1024 bytes.');

            return null;
        }

        return $password;
    }
}
