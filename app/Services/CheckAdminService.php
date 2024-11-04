<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * This class is used to check if the authenticated user is an admin or a superadmin, 
 * or user is admin in KemenkopUKM instance.
 */
class CheckAdminService
{
    /**
     * Check if the authenticated user is an admin or admin with superadmin privileges.
     *
     * This method checks if the currently authenticated user has the 'admin' role,
     * regardless of whether they are a superadmin or a regular admin.
     *
     * @return bool True if the user is an admin (superadmin or regular), false otherwise.
     */
    public function checkAdmin(): bool
    {
        $user = Auth::user();

        if ((($user->hasRole('admin') && $user->is_superadmin == 0)) || ($user->hasRole('admin') && $user->is_superadmin == 1)) {
            return true;
        }

        return false;
    }

     /**
     * Check if the authenticated user is a admin with superadmin privileges.
     *
     * This method checks if the currently authenticated user has the 'admin' role and
     * the 'is_superadmin' flag set to 1, indicating superadmin privileges.
     *
     * @return bool True if the user is a superadmin, false otherwise.
     */
    public function checkSuperAdmin(): bool
    {
        $user = Auth::user();

        if ($user->hasRole('admin') && $user->is_superadmin == 1) {
            return true;
        }

        return false;
    }

    /**
     * Check if the authenticated user is an admin in the KemenkopUKM instance.
     *
     * This method checks if the currently authenticated user has the 'admin' role (regardless of superadmin status)
     * and belongs to the instance named "KemenkopUKM".
     *
     * @return bool True if the user is an admin in the KemenkopUKM instance, false otherwise.
     */
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
