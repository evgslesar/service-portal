<?php

namespace App\Livewire;

use App\Actions\Identity\ManageAccounts;
use App\Models\AccountInvitation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Accounts extends TaskPage
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Locked]
    public ?int $editing = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public ?int $invitationId = null;

    public bool $showForm = false;

    public array $form = [];

    public string $reason = '';

    public string $evidence = '';

    public function mount(): void
    {
        $this->authorizeManagement();
        $this->prepareKeys(['create', 'update', 'invite', 'reset']);
    }

    private function authorizeManagement(): void
    {
        app(ManageAccounts::class)->authorize($this->actor());
    }

    private function sensitive(): void
    {
        $this->authorizeManagement();
        $key = 'account-management:'.Auth::id();
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        RateLimiter::hit($key, 60);
    }

    public function createForm(): void
    {
        $this->authorizeManagement();
        $this->editing = null;
        $this->version = 0;
        $this->invitationId = null;
        $this->form = ['name' => '', 'email' => '', 'kind' => 'client', 'organization_id' => null, 'roles' => [], 'permissions' => [], 'site_ids' => [], 'accept_work' => false, 'active' => true];
        $this->showForm = true;
        $this->reason = $this->evidence = '';
        $this->operationKeys = [];
        $this->prepareKeys(['create', 'update', 'invite', 'reset']);
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $this->authorizeManagement();
        $user = User::findOrFail($id);
        $this->editing = $id;
        $this->version = $user->session_version;
        $this->form = [...$user->only(['name', 'email', 'kind', 'organization_id', 'accept_work', 'active']), 'roles' => $user->roles ?? [], 'permissions' => $user->permissions ?? [], 'site_ids' => $user->sites()->pluck('sites.id')->all()];
        $this->showForm = true;
        $this->reason = $this->evidence = '';
        $this->invitationId = null;
        $this->operationKeys = [];
        $this->prepareKeys(['create', 'update', 'invite', 'reset']);
        $this->resetValidation();
    }

    public function updatedFormKind(): void
    {
        $this->authorizeManagement();
        if ($this->form['kind'] === 'staff') {
            $this->form['organization_id'] = null;
            $this->form['site_ids'] = [];
            $this->form['accept_work'] = false;
        } else {
            $this->form['roles'] = $this->form['permissions'] = [];
        }
    }

    public function updatedFormOrganizationId(): void
    {
        $this->authorizeManagement();
        $this->form['site_ids'] = [];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function save(ManageAccounts $accounts): void
    {
        $this->sensitive();
        $input = [...$this->form, 'reason' => $this->reason];
        $action = $this->editing ? 'update' : 'create';
        $result = $this->editing
            ? $accounts->update($this->actor(), $this->editing, $this->version, $input, $this->key($action))
            : $accounts->create($this->actor(), $input, $this->key($action));
        $this->done($action, 'Учётная запись сохранена. Изменение доступа записано в историю.');
        $this->dispatch('form-committed');
        if ($result['id'] === Auth::id()) {
            $this->redirectRoute('login');

            return;
        }
        $this->edit($result['id']);
        $this->invitationId = $result['invitation_id'] ?? null;
    }

    public function invite(ManageAccounts $accounts): void
    {
        $this->sensitive();
        abort_unless($this->editing, 422);
        $result = $accounts->invite($this->actor(), $this->editing, $this->version, $this->reason, $this->key('invite'));
        $this->done('invite', 'Новое приглашение создано. Предыдущее отменено; активные сеансы отозваны.');
        $this->version = User::findOrFail($this->editing)->session_version;
        $this->invitationId = $result['invitation_id'];
    }

    public function resetMfa(ManageAccounts $accounts): void
    {
        $this->sensitive();
        abort_unless($this->editing, 422);
        $accounts->resetMfa($this->actor(), $this->editing, $this->version, $this->evidence, $this->reason, $this->key('reset'));
        $this->done('reset', 'Код входа отключён. Все сеансы отозваны; пользователь сможет войти по паролю.');
        $this->version = User::findOrFail($this->editing)->session_version;
        $this->invitationId = null;
    }

    public function render()
    {
        $this->authorizeManagement();
        $invitation = $this->invitationId ? AccountInvitation::find($this->invitationId) : null;

        return view('livewire.accounts', [
            'accounts' => User::query()->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%')))->orderBy('name')->paginate(20),
            'organizations' => DB::table('organizations')->orderBy('name')->get(),
            'sites' => ($this->form['kind'] ?? '') === 'client' ? Site::where('organization_id', $this->form['organization_id'] ?? 0)->orderBy('name')->get() : collect(),
            'invitation' => $invitation?->usable() ? $invitation : null,
            'history' => $this->editing ? DB::table('audit_events')->where('action', 'like', 'identity.%')->where('data->user_id', $this->editing)->orderByDesc('id')->limit(20)->get() : collect(),
        ]);
    }
}
