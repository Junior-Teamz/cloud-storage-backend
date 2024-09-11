<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

class CheckAdminService
{
    /**
     * Check if the authenticated user is an admin or a superadmin.
     *
     * @return bool
     */
    public function checkAdmin(): bool
    {
        $user = Auth::user();

        if ((($user->hasRole('admin') && $user->is_superadmin == 0)) || ($user->hasRole('admin') && $user->is_superadmin == 1)) {
            return true;
        }

        return false;
    }
}
