<?php

namespace App\Livewire;

use App\Models\PortalNotification;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Inbox extends TaskPage
{
    use WithPagination;

    #[Url]
    public string $filter = 'unread';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function open(int $id): void
    {
        $notice = PortalNotification::visibleTo($this->actor())->findOrFail($id);
        if (! $notice->read_at) {
            $notice->update(['read_at' => now()]);
        }
        $this->redirectRoute('requests.show', ['id' => $notice->service_request_id]);
    }

    public function markRead(int $id): void
    {
        PortalNotification::visibleTo($this->actor())->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function render()
    {
        $notices = PortalNotification::visibleTo($this->actor())
            ->when($this->filter !== 'all', fn ($q) => $q->whereNull('read_at'))
            ->with('request.site')->latest()->paginate(20);

        return view('livewire.inbox', ['notices' => $notices]);
    }
}
