<?php

namespace App\Livewire;

use App\Actions\Finance\Finance;
use App\Actions\Inventory\Inventory;
use App\Actions\ServiceRequests\SendMessage;
use App\Actions\ServiceRequests\Workflow;
use App\Models\Contract;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WorkPackage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class RequestDetail extends TaskPage
{
    use WithFileUploads;
    use WithPagination;

    public array $messageFiles = [];

    #[Locked]
    public array $visitVersions = [];

    #[Locked]
    public ?int $editingVisit = null;

    public string $bookingReason = '';

    public array $replacementLeads = [];

    public array $leadReasons = [];

    public array $classifications = [];

    public array $assessmentEvidence = [];

    public array $assessmentReasons = [];

    public array $removalReasons = [];

    public array $partialScopes = [];

    public array $summaries = [];

    #[Locked]
    public ?int $continuingVisit = null;

    public string $continuationReason = '';

    #[Locked]
    public array $displayedEstimates = [];

    #[Locked]
    public int $id;

    #[Locked]
    public int $requestVersion;

    #[Locked]
    public array $packageVersions = [];

    #[Locked]
    public array $displayedRounds = [];

    public string $body = '';

    public string $visibility = 'public';

    #[Locked]
    public ?int $changeVisit = null;

    public string $reason = '';

    public string $cancelReason = '';

    public string $summary = '';

    public string $title = '';

    public string $scope = '';

    public string $category = 'plumbing';

    public string $kind = 'diagnostic';

    public ?int $contract_id = null;

    public ?int $lead_id = null;

    public string $checklistText = "Проверена безопасность рабочего места\nПроверена работоспособность";

    public bool $photo_required = true;

    public ?int $demandPackage = null;

    public ?int $demandMaterial = null;

    public string $demandQuantity = '';

    public string $demandReason = '';

    public ?int $schedulePackage = null;

    public ?int $technician_id = null;

    public string $start = '';

    public string $end = '';

    public function mount(int $id): void
    {
        $this->id = $id;
        if (! ServiceRequest::visibleTo($this->actor())->whereKey($id)->exists()) {
            session()->flash('status', 'Заявка недоступна или удалена.');
            $this->redirectRoute('home');

            return;
        }
        $this->context();
    }

    private function requestRecord(): ServiceRequest
    {
        return ServiceRequest::visibleTo($this->actor())->findOrFail($this->id);
    }

    private function context(): void
    {
        $r = $this->requestRecord();
        $this->requestVersion = $r->version;
        $this->prepareKeys(['message', 'decision', 'package', 'schedule', 'submit', 'cancel', 'demand']);
        $packages = WorkPackage::visibleTo($this->actor())->where('service_request_id', $r->id)->with(['rounds', 'visits'])->get();
        $this->packageVersions = $packages->pluck('version', 'id')->all();
        $this->visitVersions = $packages->flatMap(fn ($p) => $p->visits)->pluck('version', 'id')->all();
        foreach ($packages as $p) {
            $this->prepareKeys(['lead-'.$p->id, 'assess-'.$p->id]);
            if ($p->lead_id === $this->actor()->id && $p->allowsExecution() && ! isset($this->summaries[$p->id]) && $p->visits->contains('status', 'completed')) {
                $this->assembleSummary($p->id);
            }
        }
        foreach (array_keys($this->visitVersions) as $id) {
            $this->prepareKeys(['booking-'.$id, 'remove-'.$id, 'partial-'.$id]);
        }
        $this->displayedEstimates = $this->pendingEstimates()->pluck('id')->all();
        $this->displayedRounds = $packages->flatMap(fn ($p) => $p->rounds->where('status', 'pending')->pluck('id'))->all();
        foreach ($this->displayedRounds as $roundId) {
            foreach ($this->displayedEstimates as $estimateId) {
                $this->prepareKeys(['combined-'.$roundId.'-'.$estimateId]);
            }
        }
    }

    public function refreshContext(): void
    {
        $this->operationKeys = [];
        $this->context();
        $this->resetValidation();
    }

    public function removeMessageFile(int $index): void
    {
        unset($this->messageFiles[$index]);
        $this->resetValidation(['messageFiles', 'messageFiles.'.$index]);
    }

    public function requestTimeChange(int $id): void
    {
        abort_unless(isset($this->visitVersions[$id]), 403);
        $this->changeVisit = $id;
    }

    public function clearTimeChange(): void
    {
        $this->changeVisit = null;
    }

    public function sendMessage(SendMessage $messages): void
    {
        $timeChange = $this->changeVisit !== null;
        abort_unless(! $timeChange || isset($this->visitVersions[$this->changeVisit]), 403);
        $messages->execute($this->actor(), $this->id, ['body' => $this->body, 'visibility' => $timeChange ? 'public' : $this->visibility, 'type' => $timeChange ? 'time_change' : 'reply', 'visit_id' => $this->changeVisit], $this->messageFiles, $this->key('message'));
        $this->dispatch('draft-saved', values: ['body' => $this->body, 'visibility' => $this->visibility, 'messageFiles' => array_map(fn ($file) => $file->getClientOriginalName(), $this->messageFiles)]);
        $this->messageFiles = [];
        $this->body = '';
        $this->changeVisit = null;
        $this->done('message', $timeChange ? 'Передали диспетчеру. Текущее время пока не изменилось.' : 'Сообщение отправлено.');
    }

    public function decide(Workflow $w, string $decision, ?int $roundId = null): void
    {
        $ids = $roundId ? [$roundId] : $this->displayedRounds;
        abort_unless(! array_diff($ids, $this->displayedRounds), 403);
        $w->decide($this->actor(), $this->id, $ids, $decision, $this->reason, $this->key('decision'));
        $this->dispatch('draft-saved', values: ['reason' => $this->reason]);
        $this->reason = '';
        $this->done('decision', $decision === 'accepted' ? 'Работы приняты.' : 'Замечание отправлено. Диспетчер свяжется по следующему шагу.');
        $this->context();
    }

    private function pendingEstimates(): \Illuminate\Support\Collection
    {
        $user = $this->actor();
        if ($user->kind !== 'client' || ! $user->accept_work) {
            return collect();
        }

        return DB::table('estimates')->where('service_request_id', $this->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('estimate_decisions')->whereColumn('estimate_decisions.estimate_id', 'estimates.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('estimates as newer')->whereColumn('newer.group_key', 'estimates.group_key')->whereColumn('newer.revision', '>', 'estimates.revision'))
            ->orderByDesc('id')->get();
    }

    public function acceptDiagnosticAndEstimate(int $roundId, int $estimateId, Workflow $w): void
    {
        abort_unless(in_array($roundId, $this->displayedRounds) && in_array($estimateId, $this->displayedEstimates), 403);
        $w->acceptDiagnosticAndEstimate($this->actor(), $this->id, $roundId, $estimateId, $this->key('combined-'.$roundId.'-'.$estimateId));
        $this->done('combined-'.$roundId.'-'.$estimateId, 'Диагностика принята, смета утверждена. Проверьте счёт на аванс; деньги не списывались.');
        $this->context();
    }

    public function addPackage(Workflow $w): void
    {
        $w->addPackage($this->actor(), $this->id, $this->requestVersion, ['title' => $this->title, 'scope' => $this->scope, 'category' => $this->category, 'kind' => $this->kind, 'contract_id' => $this->contract_id, 'lead_id' => $this->lead_id, 'photo_required' => $this->photo_required, 'checklist' => array_values(array_filter(array_map('trim', explode("\n", $this->checklistText))))], $this->key('package'));
        $this->title = '';
        $this->scope = '';
        $this->done('package', $this->kind === 'paid' ? 'Объём определён. Финансовый специалист выпустит смету; выезд доступен после утверждения и аванса.' : 'Объём работ определён. Запланируйте выезд.');
        $this->context();
    }

    public function schedule(Workflow $w): void
    {
        abort_unless(isset($this->packageVersions[$this->schedulePackage]), 404);
        $continuation = $this->continuingVisit ? ['continues_visit_id' => $this->continuingVisit, 'continues_visit_version' => $this->visitVersions[$this->continuingVisit] ?? 0, 'continuation_reason' => $this->continuationReason] : [];
        $w->schedule($this->actor(), $this->schedulePackage, $this->packageVersions[$this->schedulePackage], ['technician_id' => $this->technician_id, 'start' => $this->start, 'end' => $this->end, ...$continuation], $this->key('schedule'));
        $this->continuingVisit = null;
        $this->continuationReason = '';
        $this->done('schedule', 'Выезд запланирован.');
        $this->context();
    }

    public function continueVisit(int $id): void
    {
        abort_unless(isset($this->visitVersions[$id]) && $this->actor()->hasRole('dispatcher'), 403);
        $visit = $this->requestRecord()->packages()->with('visits')->get()->flatMap(fn ($p) => $p->visits)->firstWhere('id', $id);
        abort_unless($visit && $visit->status === 'interrupted', 404);
        $this->editingVisit = null;
        $this->continuingVisit = $id;
        $this->schedulePackage = $visit->work_package_id;
        $this->technician_id = $visit->technician_id;
        $this->continuationReason = '';
        $this->dispatch('open-booking');
    }

    public function stopContinuingVisit(): void
    {
        $this->continuingVisit = null;
        $this->continuationReason = '';
    }

    public function allowPartialWork(int $id, Workflow $w): void
    {
        abort_unless(isset($this->visitVersions[$id]), 404);
        $w->allowPartialWork($this->actor(), $id, $this->visitVersions[$id], $this->partialScopes[$id] ?? '', $this->key('partial-'.$id));
        unset($this->partialScopes[$id]);
        $this->done('partial-'.$id, 'Часть работы разрешена. Мастер увидит границы; полный результат не передаётся до завершения остатка.');
        $this->context();
    }

    public function editBooking(int $id): void
    {
        abort_unless(isset($this->visitVersions[$id]) && $this->actor()->hasRole('dispatcher'), 404);
        $v = $this->requestRecord()->packages()->with('visits')->get()->flatMap(fn ($p) => $p->visits)->firstWhere('id', $id);
        abort_unless($v && $v->status === 'scheduled', 404);
        $this->editingVisit = $id;
        $this->technician_id = $v->technician_id;
        $tz = $this->requestRecord()->site->timezone;
        $this->start = $v->planned_start->timezone($tz)->format('Y-m-d\TH:i');
        $this->end = $v->planned_end->timezone($tz)->format('Y-m-d\TH:i');
        $this->bookingReason = '';
    }

    public function stopEditingBooking(): void
    {
        $this->editingVisit = null;
        $this->bookingReason = '';
    }

    public function changeBooking(Workflow $w): void
    {
        abort_unless(isset($this->visitVersions[$this->editingVisit]), 404);
        $w->changeBooking($this->actor(), $this->editingVisit, $this->visitVersions[$this->editingVisit], ['technician_id' => $this->technician_id, 'start' => $this->start, 'end' => $this->end, 'reason' => $this->bookingReason], $this->key('booking-'.$this->editingVisit));
        $this->done('booking-'.$this->editingVisit, 'Изменения выезда сохранены.');
        $this->stopEditingBooking();
        $this->context();
    }

    public function replaceLead(int $id, Workflow $w): void
    {
        abort_unless(isset($this->packageVersions[$id]), 404);
        $this->validate(['replacementLeads.'.$id => 'required|integer|min:1', 'leadReasons.'.$id => 'required|string|max:5000']);
        $w->replaceLead($this->actor(), $id, $this->packageVersions[$id], (int) $this->replacementLeads[$id], $this->leadReasons[$id], $this->key('lead-'.$id));
        $this->dispatch('draft-saved', values: ['replacementLeads' => [$id => $this->replacementLeads[$id]], 'leadReasons' => [$id => $this->leadReasons[$id]]]);
        unset($this->replacementLeads[$id], $this->leadReasons[$id]);
        $this->done('lead-'.$id, 'Ведущий мастер изменён. Начатые выезды остаются за прежними исполнителями.');
        $this->context();
    }

    public function removeVisitScope(int $id, Workflow $w): void
    {
        abort_unless(isset($this->visitVersions[$id]), 404);
        $w->removeVisitScope($this->actor(), $id, $this->visitVersions[$id], $this->removalReasons[$id] ?? '', $this->key('remove-'.$id));
        $this->dispatch('draft-saved', values: ['removalReasons' => [$id => $this->removalReasons[$id]]]);
        unset($this->removalReasons[$id]);
        $this->done('remove-'.$id, 'Незавершённый выезд больше не требуется. История сохранена.');
        $this->context();
    }

    public function assessRework(int $id, int $roundId, Workflow $w): void
    {
        abort_unless(isset($this->packageVersions[$id]), 404);
        $w->assessRework($this->actor(), $id, $this->packageVersions[$id], $roundId, ['classification' => $this->classifications[$id] ?? '', 'evidence' => $this->assessmentEvidence[$id] ?? '', 'reason' => $this->assessmentReasons[$id] ?? ''], $this->key('assess-'.$id));
        $this->dispatch('draft-saved', values: ['classifications' => [$id => $this->classifications[$id]], 'assessmentEvidence' => [$id => $this->assessmentEvidence[$id]], 'assessmentReasons' => [$id => $this->assessmentReasons[$id]]]);
        unset($this->classifications[$id], $this->assessmentEvidence[$id], $this->assessmentReasons[$id]);
        $this->done('assess-'.$id, 'Решение по замечанию записано. Приёмка остаётся за клиентом.');
        $this->context();
    }

    public function submit(int $packageId, Workflow $w): void
    {
        abort_unless(isset($this->packageVersions[$packageId]), 404);
        $text = $this->summary !== '' ? $this->summary : ($this->summaries[$packageId] ?? '');
        $w->submit($this->actor(), $packageId, $this->packageVersions[$packageId], $text, $this->key('submit'));
        $this->dispatch('draft-saved', values: ['summary' => $this->summary, 'summaries' => [$packageId => $text]]);
        unset($this->summaries[$packageId]);
        $this->summary = '';
        $this->done('submit', 'Результат передан клиенту.');
        $this->context();
    }

    public function assembleSummary(int $packageId): void
    {
        $package = WorkPackage::visibleTo($this->actor())->where('service_request_id', $this->id)->findOrFail($packageId);
        abort_unless($package->lead_id === $this->actor()->id, 403);
        $text = $package->visits()->with('technician')->where('required', true)->where('status', 'completed')->orderBy('id')->get()->map(fn ($v) => $v->technician->name.': '.trim($v->report ?? ''))->implode("\n\n");
        $limit = 20000;
        if (mb_strlen($text) <= $limit) {
            $this->summaries[$packageId] = $text;

            return;
        }
        $lines = explode("\n", $text);
        $buffer = '';
        foreach ($lines as $line) {
            $candidate = $buffer === '' ? $line : $buffer."\n".$line;
            if (mb_strlen($candidate) > $limit) {
                break;
            }
            $buffer = $candidate;
        }
        if ($buffer !== '' && mb_strlen($buffer) < $limit) {
            $buffer .= "\n";
        }
        $this->summaries[$packageId] = $buffer;
    }

    public function approveDemand(Inventory $inventory): void
    {
        abort_unless(isset($this->packageVersions[$this->demandPackage]), 404);
        $inventory->demand($this->actor(), (int) $this->demandPackage, (int) $this->demandMaterial, $this->demandQuantity, $this->demandReason, $this->key('demand'));
        $this->done('demand', 'Потребность разрешена. Склад может зарезервировать и выдать материал.');
        $this->demandQuantity = '';
        $this->demandReason = '';
        $this->context();
    }

    public function cancel(Workflow $w): void
    {
        $w->cancel($this->actor(), $this->id, $this->requestVersion, $this->cancelReason, $this->key('cancel'));
        $this->cancelReason = '';
        $this->done('cancel', 'Заявка отменена. История сохранена.');
        $this->context();
    }

    public function render()
    {
        $r = $this->requestRecord();
        $user = $this->actor();
        /** @var Collection<int, WorkPackage> $packages */
        $packages = WorkPackage::visibleTo($user)->where('service_request_id', $r->id)->with(['visits.technician', 'visits.attachments', 'rounds.result', 'lead', 'assessments.actor'])->orderBy('ordinal')->get();
        $leadReviews = [];
        $stockReadiness = [];
        foreach ($packages as $package) {
            if ($user->hasRole('dispatcher') || $package->lead_id === $user->id) {
                $stockReadiness[$package->id] = app(Inventory::class)->readiness($package);
            }
            if ($package->lead_id === $user->id && $package->allowsExecution()) {
                $outstanding = $package->outstandingVisits();
                $blockers = [];
                if ($outstanding->where('execution_round', $package->execution_round)->isEmpty()) {
                    $blockers[] = 'Нужен выезд по текущему объёму.';
                }
                foreach ($outstanding as $visit) {
                    if ($visit->status !== 'completed') {
                        $blockers[] = $visit->technician->name.': '.($visit->status === 'interrupted' ? 'диспетчер должен запланировать продолжение.' : 'отчёт ещё не передан.');
                    } elseif (trim($visit->report ?? '') === '' || collect($visit->checklist)->contains(fn ($i) => $i['required'] && ! $i['done']) || ($package->photo_required && $visit->attachments->isEmpty())) {
                        $blockers[] = $visit->technician->name.': не хватает отчёта, проверки или обязательного фото.';
                    }
                }
                $leadReviews[$package->id] = ['blockers' => $blockers, 'materials' => $package->visits->mapWithKeys(fn ($v) => [$v->id => app(Inventory::class)->usageEvidence($v)])->all()];
            }
        }
        $previews = [];
        if ($user->hasRole('finance') || ($user->kind === 'client' && $user->accept_work)) {
            foreach ($packages->where('kind', 'paid')->where('status', 'awaiting_acceptance') as $package) {
                $previews[$package->id] = app(Finance::class)->acceptancePreview($package);
            }
        }
        $messages = $r->messages()->when($user->kind === 'client', fn ($q) => $q->where('visibility', 'public'))->with(['author', 'attachments'])->latest()->paginate(20);
        /** @var Collection<int, User> $technicians */
        $technicians = $user->hasRole('dispatcher') ? User::where('kind', 'staff')->where('active', true)->get() : new Collection;

        return view('livewire.request-detail', ['requestRecord' => $r, 'packages' => $packages, 'messages' => $messages, 'user' => $user, 'previews' => $previews, 'leadReviews' => $leadReviews, 'stockReadiness' => $stockReadiness, 'pendingEstimates' => $this->pendingEstimates()->whereIn('id', $this->displayedEstimates),
            'contracts' => $user->hasRole('dispatcher') ? Contract::eligible($r->site)->get() : collect(),
            'technicians' => $technicians->filter(fn (User $u) => $u->hasRole('technician')),
            'photos' => $r->attachments()->whereNull('visit_id')->whereNull('request_message_id')->get(),
            'materials' => $user->hasRole('dispatcher') ? DB::table('materials')->where('active', true)->orderBy('name')->get() : collect(),
        ]);
    }
}
