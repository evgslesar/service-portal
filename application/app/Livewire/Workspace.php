<?php

namespace App\Livewire;

use App\Models\ServiceRequest;
use App\Models\Site;
use App\Models\Visit;
use App\Models\WorkPackage;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Workspace extends TaskPage
{
    use WithPagination;

    #[Url]
    public string $section = 'active';

    #[Url]
    public string $search = '';

    #[Url]
    public string $workspace = '';

    #[Url]
    public ?int $site = null;

    #[Url]
    public string $status = '';

    #[Url]
    public string $date = '';

    public function mount(): void
    {
        $user = $this->actor();
        if ($user->canManageUsers() && ! $user->hasRole('dispatcher') && ! $user->hasRole('technician')) {
            $this->redirectRoute('accounts');
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'section') {
            $this->reset('status', 'date');
        }
        if (in_array($property, ['search', 'section', 'workspace', 'site', 'status', 'date'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'site', 'status', 'date');
        $this->resetPage();
    }

    private function normalizeFilters(): void
    {
        if (! in_array($this->section, ['active', 'history', 'sites'], true)) {
            $this->section = 'active';
        }
        $validStatuses = ['new', 'processing', 'assigned', 'in_progress', 'rework_pending', 'awaiting_acceptance', 'awaiting_settlement', 'on_hold', 'closed', 'cancelled'];
        if ($this->status !== '' && ! in_array($this->status, $validStatuses, true)) {
            $this->status = '';
        }
        if ($this->date !== '') {
            if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $this->date, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $this->date = '';
            }
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function searchScope(string $term): \Closure
    {
        $escaped = $this->escapeLike($term);

        return function ($query) use ($escaped) {
            $query->whereRaw('title LIKE ? ESCAPE \'\\\'', ['%'.$escaped.'%'])
                ->orWhereRaw('number LIKE ? ESCAPE \'\\\'', ['%'.$escaped.'%'])
                ->orWhereHas('site', function ($site) use ($escaped) {
                    $site->whereRaw('name LIKE ? ESCAPE \'\\\'', ['%'.$escaped.'%'])
                        ->orWhereRaw('address LIKE ? ESCAPE \'\\\'', ['%'.$escaped.'%']);
                });
        };
    }

    public function render()
    {
        $user = $this->actor();
        $technician = $user->hasRole('technician') && $this->workspace !== 'dispatch';
        abort_if($this->workspace === 'dispatch' && ! $user->hasRole('dispatcher'), 403);
        $this->normalizeFilters();
        $history = $this->section === 'history';
        $siteQuery = $user->kind === 'client' || $user->hasRole('dispatcher') ? Site::visibleTo($user) : Site::whereIn('id', ServiceRequest::visibleTo($user)->select('site_id'));
        $filterSites = $siteQuery->orderBy('name')->get();
        abort_if($this->site && ! $filterSites->contains('id', $this->site), 404);
        $requests = ServiceRequest::visibleTo($user)->with('site')->whereIn('status', $history ? ['closed', 'cancelled'] : ['new', 'processing', 'assigned', 'in_progress', 'rework_pending', 'awaiting_acceptance', 'awaiting_settlement', 'on_hold']);
        if ($this->site) {
            $requests->where('site_id', $this->site);
        }
        if ($this->status !== '') {
            $requests->where('status', $this->status);
        }
        if ($this->search !== '') {
            $requests->where($this->searchScope($this->search));
        }
        $visits = null;
        if ($technician) {
            $query = Visit::where('technician_id', $user->id)->whereIn('status', $history ? ['completed', 'interrupted'] : ['scheduled', 'in_progress'])->whereHas('package', fn ($p) => $p->visibleTo($user))
                ->when($this->site, fn ($q) => $q->whereHas('package.request', fn ($r) => $r->where('site_id', $this->site)))
                ->when($this->search !== '', fn ($q) => $q->whereHas('package.request', $this->searchScope($this->search)));
            if ($this->date !== '') {
                $query->where(function ($q) use ($filterSites) {
                    foreach ($filterSites->pluck('timezone')->unique() as $timezone) {
                        $start = Carbon::createFromFormat('!Y-m-d', $this->date, $timezone)->utc();
                        $end = $start->copy()->timezone($timezone)->addDay()->utc();
                        $q->orWhere(fn ($v) => $v->where('planned_start', '>=', $start)->where('planned_start', '<', $end)->whereHas('package.request.site', fn ($s) => $s->where('timezone', $timezone)));
                    }
                });
            }
            $visits = $query->with('package.request.site')->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")->orderBy('planned_start', $history ? 'desc' : 'asc')->paginate(15);
        }
        $leads = $technician && ! $history ? WorkPackage::visibleTo($user)->where('lead_id', $user->id)->where('status', 'in_progress')->whereDoesntHave('visits', fn ($v) => $v->where('required', true)->where('status', '!=', 'completed')->whereDoesntHave('continuation', fn ($c) => $c->where('required', true)->where('status', '!=', 'cancelled')))->with('request.site')->limit(20)->get() : collect();
        if ($this->section === 'sites') {
            $escaped = $this->escapeLike($this->search);
            $sites = (clone $siteQuery)->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('name LIKE ? ESCAPE \'\\\'', ['%'.$escaped.'%'])->orWhereRaw('address LIKE ? ESCAPE \'\\\'', ['%'.$escaped.'%'])))->paginate(15);
        } else {
            $sites = null;
        }

        return view('livewire.workspace', ['user' => $user, 'technician' => $technician, 'visits' => $visits, 'leads' => $leads, 'requests' => $technician || $this->section === 'sites' ? null : $requests->latest()->paginate(15), 'sites' => $sites, 'filterSites' => $filterSites]);
    }
}
