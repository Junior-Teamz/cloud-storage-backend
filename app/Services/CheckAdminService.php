<?php

namespace App\Services;

use App\Models\User;
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

    public function checkSuperAdmin(): bool
    {
        $user = Auth::user();

        if ($user->hasRole('admin') && $user->is_superadmin == 1) {
            return true;
        }

        return false;
    }

    public function checkKemenkopUKMAdmin(): bool
    {
        $userLogin = Auth::user();

        $user = User::with('instances:id,name')->where('id', $userLogin->id)->first();

        $userInstance = $user->instance->pluck('name');

        if ((($user->hasRole('admin') && $user->is_superadmin == 0)) || ($user->hasRole('admin') && $user->is_superadmin == 1))
        {
            if ($userInstance === "KemenkopUKM") 
            {
                return true;
            }
            
            return false;
        }

        return false;
    }
}
