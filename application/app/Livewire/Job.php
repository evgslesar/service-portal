<?php

namespace App\Livewire;

use App\Actions\Inventory\Inventory;
use App\Actions\Photos\SavePhoto;
use App\Actions\ServiceRequests\Workflow;
use App\Models\Visit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class Job extends TaskPage
{
    use WithFileUploads;

    #[Locked]
    public int $id;

    #[Locked]
    public int $version;

    public string $report = '';

    public array $checks = [];

    public ?int $labor_minutes = null;

    public bool $usage_confirmed = false;

    public array $usage = [];

    public string $reason = '';

    public string $helpReason = 'Нет доступа';

    public array $photos = [];

    #[Locked]
    public bool $saved = false;

    private function record(): Visit
    {
        abort_unless($this->actor()->hasRole('technician'), 403);

        return Visit::where('technician_id', Auth::id())->where('status', '!=', 'cancelled')->whereHas('package', fn ($p) => $p->visibleTo($this->actor()))->with(['package.request.site', 'attachments'])->findOrFail($this->id);
    }

    public function mount(int $id): void
    {
        $this->prepareKeys(['draft', 'start', 'finish', 'finish_submit', 'interrupt', 'help']);
        $this->id = $id;
        $v = $this->record();
        $this->version = $v->version;
        $this->report = $v->report ?? '';
        $this->checks = array_column($v->checklist, 'done');
        $this->labor_minutes = $v->labor_minutes;
        foreach ($this->allocations($v) as $allocation) {
            $this->usage[$allocation->id] = $v->usage_draft[$allocation->id] ?? '0.000';
        }
    }

    private function allocations(Visit $visit)
    {
        return DB::table('stock_allocations')->join('material_demands', 'material_demands.id', '=', 'stock_allocations.material_demand_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_allocations.stock_location_id')
            ->join('materials', 'materials.id', '=', 'material_demands.material_id')
            ->where('work_package_id', $visit->work_package_id)->where('custodian_id', Auth::id())->where('remaining_milli', '>', 0)
            ->orderBy('stock_allocations.id')->get(['stock_allocations.id', 'materials.name', 'materials.unit', 'remaining_milli']);
    }

    private function input(): array
    {
        return ['report' => $this->report, 'checks' => $this->checks, 'labor_minutes' => $this->labor_minutes, 'usage_confirmed' => $this->usage_confirmed, 'usage' => $this->usage, 'reason' => $this->helpReason.($this->reason ? ': '.$this->reason : '')];
    }

    public function updated(string $name): void
    {
        if ($name === 'report' || $name === 'labor_minutes' || str_starts_with($name, 'checks') || str_starts_with($name, 'usage.')) {
            $this->saved = false;
            $this->saveDraft(app(Workflow::class));
        }
    }

    public function saveDraft(Workflow $w): void
    {
        $result = $w->visit($this->actor(), $this->id, $this->version, 'draft', $this->input(), $this->key('draft'));
        $this->version = $result['version'];
        $this->rotateKey('draft');
        $this->saved = true;
        $this->dispatch('draft-saved', values: ['report' => $this->report, 'checks' => $this->checks, 'labor_minutes' => $this->labor_minutes, 'usage' => $this->usage]);
    }

    public function refreshVersion(): void
    {
        $this->version = $this->record()->version;
        $this->operationKeys = [];
        $this->prepareKeys(['draft', 'start', 'finish', 'finish_submit', 'interrupt', 'help']);
        $this->resetValidation();
        $this->saved = false;
    }

    public function commitVisit(string $command, Workflow $w): void
    {
        if (count($this->photos)) {
            $this->addError('photos', 'Сначала сохраните фото или уберите неудачную загрузку.');

            return;
        }
        if ($command === 'interrupt') {
            $this->validate([
                'helpReason' => 'required|in:Нет доступа,Не хватает материалов,Другая причина',
                'reason' => ($this->helpReason === 'Другая причина' ? 'required' : 'nullable').'|string|max:4000',
            ]);
        }
        $result = $w->visit($this->actor(), $this->id, $this->version, $command, $this->input(), $this->key($command));
        $this->version = $result['version'];
        $this->done($command, match ($command) {
            'start' => 'Работа начата.',
            'interrupt' => 'Выезд остановлен, диспетчер уведомлён. Сделанная работа и расход сохранены; следующий шаг организует диспетчер.',
            'finish_submit' => 'Результат передан клиенту. Можно перейти к следующему заданию.',
            default => 'Отчёт передан. Можно перейти к следующему заданию.',
        });
        if ($command === 'start') {
            $this->labor_minutes = null;
        } else {
            $this->dispatch('form-committed');
            $this->redirectRoute('home');
        }
    }

    public function savePhotos(SavePhoto $action): void
    {
        $this->validate(['photos' => 'array|max:5', 'photos.*' => 'file|max:20480']);
        $v = $this->record();
        $names = array_map(fn ($file) => $file->getClientOriginalName(), $this->photos);
        try {
            foreach ($this->photos as $i => $file) {
                $action->execute($this->actor(), $v->package->service_request_id, $v->id, $file, $this->photoKey($file));
                unset($this->photos[$i]);
            }
        } finally {
            $this->version = $v->fresh()->version;
        }
        $this->dispatch('draft-saved', values: ['photos' => $names]);
        $this->done('photos', 'Фото сохранены.');
    }

    public function removePhoto(int $i): void
    {
        unset($this->photos[$i]);
        $this->resetValidation(['photos', 'photos.'.$i]);
    }

    private function minuteSuggestion(Visit $v): int
    {
        return $v->actual_start ? max(0, (int) $v->actual_start->diffInMinutes(now())) : 0;
    }

    public function useSuggestion(): void
    {
        $this->labor_minutes = $this->minuteSuggestion($this->record());
    }

    public function help(Workflow $w): void
    {
        $v = $this->record();
        $this->validate(['helpReason' => 'required|in:Нет доступа,Не хватает материалов,Другая причина', 'reason' => ($this->helpReason === 'Другая причина' ? 'required' : 'nullable').'|string|max:4000']);
        $w->message($this->actor(), $v->package->service_request_id, ['body' => $this->input()['reason'], 'visibility' => 'internal', 'type' => 'help', 'visit_id' => $v->id], $this->key('help'));
        $this->dispatch('draft-saved', values: ['helpReason' => $this->helpReason, 'reason' => $this->reason]);
        $this->done('help', 'Помощь запрошена. Диспетчер свяжется с вами. Выезд продолжается.');
    }

    public function render()
    {
        $v = $this->record();
        $p = $v->package;
        $sole = $p->lead_id === Auth::id() && $p->visits()->where('required', true)->count() === 1;
        $readiness = app(Inventory::class)->readiness($p);
        $activeConflict = Visit::where('technician_id', Auth::id())->where('status', 'in_progress')->where('id', '!=', $v->id)->with('package.request.site')->first();
        $canStart = $p->allowsExecution() && ! $activeConflict && ($readiness['ready'] || ($v->partial_work_scope && $v->partial_work_fingerprint === $readiness['fingerprint']));
        $suggestion = $this->minuteSuggestion($v);

        return view('livewire.job', ['visit' => $v, 'site' => $p->request->site, 'sole' => $sole, 'readiness' => $readiness, 'canStart' => $canStart, 'activeConflict' => $activeConflict, 'suggestion' => $suggestion, 'allocations' => $this->allocations($v)]);
    }
}
