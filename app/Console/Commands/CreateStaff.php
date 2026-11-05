<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateStaff extends Command
{
    protected $signature = 'tradexa:staff {email} {--role=reviewer}';

    protected $description = 'Create a staff account interactively; no default password is installed';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $role = $this->option('role');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($role, ['admin', 'reviewer', 'auditor'], true)) {
            $this->error('Supply a valid email and role (admin, reviewer, auditor).');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('An account already exists.');

            return self::FAILURE;
        }
        $name = $this->ask('Staff display name');
        $password = $this->secret('New password (at least 14 characters, mixed case, numbers and symbols)');
        $confirm = $this->secret('Confirm password');
        $validator = Validator::make(['name' => $name, 'password' => $password, 'password_confirmation' => $confirm], ['name' => 'required|string|max:100', 'password' => ['required', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()]]);
        if ($validator->fails()) {
            $this->error('Name or password does not satisfy the staff policy.');

            return self::FAILURE;
        }
        $user = new User;
        $user->forceFill(['public_id' => (string) Str::uuid(), 'name' => $name, 'email' => $email, 'password' => $password, 'role' => $role, 'is_active' => true])->save();
        $this->info('Staff account created. Sign in with the staff email and password.');

        return self::SUCCESS;
    }
}
