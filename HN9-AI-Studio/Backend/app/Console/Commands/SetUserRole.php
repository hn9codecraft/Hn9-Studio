<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\User;
use Illuminate\Console\Command;

class SetUserRole extends Command
{
    protected $signature = 'hn9:user-role {email} {role}';

    protected $description = 'Set a user role to admin or member';

    public function handle(ActivityLoggerInterface $activity): int
    {
        $role = strtolower((string) $this->argument('role'));
        $email = (string) $this->argument('email');

        if (! in_array($role, ['admin', 'member'], true)) {
            $this->error('Role must be admin or member.');

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        if (! $user instanceof User) {
            $this->error('User not found');

            return self::FAILURE;
        }

        if ($role === 'member' && $user->isAdmin() && $user->isActive()) {
            $activeAdmins = User::query()->where('role', 'admin')->where('status', 'active')->count();
            if ($activeAdmins <= 1) {
                $this->error('The last active admin cannot be demoted.');

                return self::FAILURE;
            }
        }

        $previous = (string) $user->role;
        if ($previous !== $role) {
            $user->forceFill(['role' => $role])->save();
            $activity->log('user.role_changed', $user, $user, 'Role changed', [
                'old' => $previous,
                'new' => $role,
            ]);
        }

        $this->info('Role for '.$user->email.' set to '.$role.'.');

        return self::SUCCESS;
    }
}
