<?php

namespace App\Policies;

use App\Models\ServiceRequest;
use App\Models\User;

class ServiceRequestPolicy
{
    public function view(User $user, ServiceRequest $request): bool
    {
        return ServiceRequest::visibleTo($user)->whereKey($request->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->active && ($user->kind === 'client' || $user->hasRole('dispatcher'));
    }

    public function manage(User $user, ServiceRequest $request): bool
    {
        return $user->hasRole('dispatcher') && $this->view($user, $request);
    }

    public function accept(User $user, ServiceRequest $request): bool
    {
        return $user->kind === 'client' && $user->accept_work && $this->view($user, $request);
    }
}
