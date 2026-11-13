<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;
use App\Models\WorkPackage;
use Illuminate\Support\Facades\Gate;

class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        if (! Gate::forUser($user)->allows('view', $attachment->request)) {
            return false;
        }
        if ($attachment->request_message_id) {
            $message = $attachment->message;
            if (! $message || $message->service_request_id !== $attachment->service_request_id || ($message->visibility === 'internal' && $user->kind !== 'staff')) {
                return false;
            }
        }
        if ($user->kind === 'staff' && ! $user->hasRole('dispatcher') && $attachment->visit_id) {
            return WorkPackage::visibleTo($user)->whereKey($attachment->visit->work_package_id)->exists();
        }

        return true;
    }
}
