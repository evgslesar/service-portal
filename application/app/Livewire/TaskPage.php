<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Ramsey\Uuid\Uuid;

#[Layout('components.layout')]
abstract class TaskPage extends Component
{
    #[Locked]
    public array $operationKeys = [];

    protected function actor(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(401);
        }
        abort_unless($user->active, 403);

        return $user;
    }

    protected function key(string $action): string
    {
        return $this->operationKeys[$action] ??= (string) Str::uuid();
    }

    protected function prepareKeys(array $actions): void
    {
        foreach ($actions as $action) {
            $this->key($action);
        }
    }

    protected function rotateKey(string $action): void
    {
        $this->operationKeys[$action] = (string) Str::uuid();
    }

    protected function photoKey(UploadedFile $file): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'portal-photo:'.Auth::id().':'.$file->getFilename());
    }

    protected function done(string $action, string $message): void
    {
        $this->rotateKey($action);
        $this->resetValidation();
        session()->flash('status', $message);
    }
}
