<?php

namespace App\Livewire;

use App\Actions\Photos\SavePhoto;
use App\Actions\ServiceRequests\Workflow;
use App\Models\ServiceRequest;
use App\Models\Site;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class CreateRequest extends TaskPage
{
    use WithFileUploads;

    public ?int $site_id = null;

    public string $description = '';

    public string $place = '';

    public array $photos = [];

    #[Locked]
    public ?int $createdId = null;

    #[Locked]
    public ?int $relatedRequestId = null;

    #[Locked]
    public ?string $relatedNumber = null;

    public function mount(): void
    {
        $this->prepareKeys(['create']);
        Gate::authorize('create', ServiceRequest::class);
        $sites = Site::visibleTo($this->actor())->where('active', true)->get();
        if ($sites->count() === 1) {
            $this->site_id = $sites->first()->id;
        }
        if (request()->integer('site') && $sites->contains('id', request()->integer('site'))) {
            $this->site_id = request()->integer('site');
        }
        if (request()->integer('related')) {
            $related = ServiceRequest::visibleTo($this->actor())->whereKey(request()->integer('related'))->first();
            if ($related && $sites->contains('id', $related->site_id)) {
                $this->relatedRequestId = $related->id;
                $this->relatedNumber = $related->number;
                $this->site_id = $related->site_id;
            }
        }
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->resetValidation(['photos', 'photos.'.$index]);
    }

    public function send(Workflow $workflow, SavePhoto $photo): void
    {
        $this->validate(['photos' => 'array|max:5', 'photos.*' => 'file|mimes:jpeg,png,webp,heic,heif|max:20480']);
        if (! $this->createdId) {
            $result = $workflow->create($this->actor(), ['site_id' => $this->site_id, 'description' => $this->description, 'place' => $this->place, ...($this->relatedRequestId ? ['related_request_id' => $this->relatedRequestId] : [])], $this->key('create'));
            $this->createdId = $result['id'];
            $this->dispatch('draft-saved', values: ['description' => $this->description, 'place' => $this->place, 'site_id' => $this->site_id]);
        }
        foreach ($this->photos as $index => $file) {
            $photo->execute($this->actor(), $this->createdId, null, $file, $this->photoKey($file));
            unset($this->photos[$index]);
        }
        $number = ServiceRequest::findOrFail($this->createdId)->number;
        session()->flash('status', "Заявка {$number} отправлена. Диспетчер планирует выезд.");
        $this->dispatch('form-committed');
        $this->redirectRoute('requests.show', ['id' => $this->createdId]);
    }

    public function render()
    {
        return view('livewire.create-request', ['sites' => Site::visibleTo($this->actor())->where('active', true)->get()]);
    }
}
