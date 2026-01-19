<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AnalyticsPolicy
{
    use HandlesAuthorization;

    // public function view(User $user)
    // {
    //     return $user->hasRole('admin') || $user->can('view_analytics');
    // }

    // public function export(User $user)
    // {
    //     return $user->hasRole('admin') || $user->can('export_analytics');
    // }
}
