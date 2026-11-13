<?php

namespace App\Actions\ServiceRequests;

use App\Actions\Finance\Finance;
use App\Actions\Inventory\Inventory;
use App\Actions\Operation;
use App\Models\AcceptanceRound;
use App\Models\Contract;
use App\Models\ServiceRequest;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkPackage;
use App\Support\DocumentNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class Workflow
{
    public function __construct(private Operation $operations, private Inventory $inventory) {}

    private function check(bool $condition, string $message, string $field = 'action'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function version(int $actual, int $expected): void
    {
        $this->check($actual === $expected, 'Данные изменились. Обновите страницу и проверьте действие ещё раз.');
    }

    private function root(User $actor, int $id, string $ability = 'view'): ServiceRequest
    {
        $request = ServiceRequest::visibleTo($actor)->findOrFail($id);
        Gate::forUser($actor)->authorize($ability, $request);

        return $request;
    }

    private function lockRoot(User $actor, int $id, string $ability = 'view'): ServiceRequest
    {
        $request = ServiceRequest::whereKey($id)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor->fresh())->authorize($ability, $request);
        $this->check(! in_array($request->status, ['closed', 'cancelled']), 'Эта заявка уже завершена. Сообщите о новой проблеме.');

        return $request;
    }

    public function create(User $actor, array $input, string $key): array
    {
        Gate::forUser($actor)->authorize('create', ServiceRequest::class);
        $input = Validator::make($input, ['site_id' => 'required|integer', 'description' => 'required|string|max:10000', 'place' => 'nullable|string|max:255', 'related_request_id' => 'nullable|integer'])->validate();
        $site = Site::visibleTo($actor)->findOrFail($input['site_id']);
        if (! empty($input['related_request_id'])) {
            $this->root($actor, $input['related_request_id']);
        }

        return $this->operations->run($actor, $key, 'request.create', $input, function () use ($actor, $input, $site) {
            $site = Site::visibleTo($actor->fresh())->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $this->check($site->active && DB::table('organizations')->where('id', $site->organization_id)->value('active') && Contract::eligible($site)->exists(), 'Обслуживание объекта нужно проверить. Свяжитесь с сервисной компанией.', 'site_id');
            $text = trim($input['description']);
            $this->check($text !== '', 'Опишите, что случилось.', 'description');
            if (! empty($input['related_request_id'])) {
                $old = $this->root($actor->fresh(), $input['related_request_id']);
                $this->check($old->site_id === $site->id, 'Новая проблема должна относиться к тому же объекту.');
            }
            $request = ServiceRequest::create([
                ...$input, 'description' => $text, 'organization_id' => $site->organization_id, 'author_id' => $actor->id,
                'number' => DocumentNumber::next('СП'), 'title' => Str::limit(strtok($text, "\r\n"), 250, ''),
            ]);
            $this->operations->event($actor, $request->id, 'request.created');

            return ['id' => $request->id];
        });
    }

    public function addPackage(User $actor, int $id, int $version, array $input, string $key): array
    {
        $this->root($actor, $id, 'manage');
        $input = Validator::make($input, [
            'title' => 'required|string|max:255', 'scope' => 'required|string|max:10000', 'category' => 'required|string|max:255',
            'contract_id' => 'required|integer', 'lead_id' => 'required|integer', 'kind' => 'required|in:covered,diagnostic,maintenance,paid',
            'checklist' => 'required|array|min:1|max:30', 'checklist.*' => 'required|string|max:255', 'photo_required' => 'required|boolean',
        ])->validate();

        return $this->operations->run($actor, $key, 'package.create', [$id, $version, $input], function () use ($actor, $id, $version, $input) {
            $r = $this->lockRoot($actor, $id, 'manage');
            $this->version($r->version, $version);
            $contract = Contract::eligible($r->site)->whereKey($input['contract_id'])->firstOrFail();
            $this->check($input['kind'] === 'paid' || in_array($input['category'], $contract->covered_categories, true), 'Этот вид работ не включён в выбранный договор.', 'category');
            $lead = User::whereKey($input['lead_id'])->lockForUpdate()->first();
            $this->check($lead?->hasRole('technician') ?? false, 'Выберите действующего ведущего мастера.', 'lead_id');
            $p = $r->packages()->create([
                ...collect($input)->except('checklist')->all(), 'ordinal' => ($r->packages()->max('ordinal') ?? 0) + 1,
                'contract_snapshot' => $contract->only(['number', 'title', 'starts_on', 'ends_on', 'terms', 'covered_categories']),
                'checklist_template' => array_map(fn ($text) => ['text' => $text, 'required' => true], $input['checklist']),
            ]);
            $r->update(['category' => $r->category ?? $input['category']]);
            $this->aggregate($r);
            $this->operations->event($actor, $id, 'package.created', ['package_id' => $p->id]);

            return ['id' => $p->id];
        });
    }

    public function schedule(User $actor, int $packageId, int $version, array $input, string $key): array
    {
        $p = WorkPackage::visibleTo($actor)->findOrFail($packageId);
        $this->root($actor, $p->service_request_id, 'manage');
        $input = Validator::make($input, ['technician_id' => 'required|integer', 'start' => 'required|date_format:Y-m-d\TH:i', 'end' => 'required|date_format:Y-m-d\TH:i', 'continues_visit_id' => 'nullable|integer', 'continues_visit_version' => 'required_with:continues_visit_id|integer', 'continuation_reason' => 'required_with:continues_visit_id|nullable|string|max:5000'])->validate();

        return $this->operations->run($actor, $key, 'visit.schedule', [$packageId, $version, $input], function () use ($actor, $p, $version, $input) {
            $r = $this->lockRoot($actor, $p->service_request_id, 'manage');
            $p = WorkPackage::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->version($p->version, $version);
            $this->check($p->allowsExecution(), 'Сначала разрешите текущую приёмку или замечание.');
            $tech = User::whereKey($input['technician_id'])->lockForUpdate()->firstOrFail();
            $this->check($tech->hasRole('technician'), 'Выберите действующего мастера.', 'technician_id');
            [$start, $end] = $this->bookingWindow($r, $tech, $input);
            $previous = null;
            if (! empty($input['continues_visit_id'])) {
                $previous = $p->visits()->whereKey($input['continues_visit_id'])->lockForUpdate()->firstOrFail();
                $this->version($previous->version, $input['continues_visit_version']);
                $this->check($previous->status === 'interrupted' && $previous->required && $previous->execution_round === $p->execution_round && ! Visit::where('continues_visit_id', $previous->id)->exists(), 'Этот выезд уже продолжен или больше не требует продолжения.');
                $this->check(trim($input['continuation_reason']) !== '', 'Укажите, как будет завершён оставшийся объём.', 'continuation_reason');
            }
            $v = $p->visits()->create(['technician_id' => $tech->id, 'planned_start' => $start, 'planned_end' => $end, 'execution_round' => $p->execution_round, 'continues_visit_id' => $previous?->id, 'continuation_reason' => $previous ? trim($input['continuation_reason']) : null, 'checklist' => array_map(fn ($item) => [...$item, 'done' => false], $p->checklist_template)]);
            $p->increment('version');
            $this->aggregate($r);
            $this->operations->event($actor, $r->id, 'visit.scheduled', ['visit_id' => $v->id]);

            return ['id' => $v->id];
        });
    }

    private function bookingWindow(ServiceRequest $r, User $tech, array $input, ?int $except = null): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $input['start'], $r->site->timezone)->utc();
        $end = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $input['end'], $r->site->timezone)->utc();
        $this->check($start->isFuture() && $end->greaterThan($start), 'Укажите будущий выезд и окончание после начала.', 'start');
        $conflict = Visit::where('technician_id', $tech->id)->whereIn('status', ['scheduled', 'in_progress'])
            ->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where('planned_start', '<', $end)->where('planned_end', '>', $start)->exists();
        $this->check(! $conflict, 'Мастер уже занят в это время.', 'start');

        return [$start, $end];
    }

    public function changeBooking(User $actor, int $id, int $version, array $input, string $key): array
    {
        $v = Visit::findOrFail($id);
        $this->root($actor, $v->package->service_request_id, 'manage');
        $input = Validator::make($input, ['technician_id' => 'required|integer', 'start' => 'required|date_format:Y-m-d\TH:i', 'end' => 'required|date_format:Y-m-d\TH:i', 'reason' => 'required|string|max:5000'])->validate();

        return $this->operations->run($actor, $key, 'visit.change', [$id, $version, $input], function () use ($actor, $v, $version, $input) {
            $r = $this->lockRoot($actor, $v->package->service_request_id, 'manage');
            $p = WorkPackage::whereKey($v->work_package_id)->lockForUpdate()->firstOrFail();
            $v = Visit::findOrFail($v->id);
            $technicians = User::whereIn('id', [$v->technician_id, $input['technician_id']])->orderBy('id')->lockForUpdate()->get();
            $tech = $technicians->firstWhere('id', $input['technician_id']);
            $v = Visit::whereKey($v->id)->lockForUpdate()->firstOrFail();
            $this->version($v->version, $version);
            $this->check($v->status === 'scheduled' && ! $v->actual_start, 'Начатый выезд нельзя переназначить. Прервите его и создайте новый.');
            $this->check($p->allowsExecution(), 'Сначала разрешите текущую приёмку или замечание.');
            $this->check($tech?->hasRole('technician') ?? false, 'Выберите действующего мастера.', 'technician_id');
            $this->check(trim($input['reason']) !== '', 'Укажите причину изменения.', 'reason');
            [$start, $end] = $this->bookingWindow($r, $tech, $input, $v->id);
            $before = $v->only(['technician_id', 'planned_start', 'planned_end']);
            $v->update(['technician_id' => $tech->id, 'planned_start' => $start, 'planned_end' => $end, 'version' => $v->version + 1]);
            $p->increment('version');
            $this->aggregate($r);
            $this->operations->event($actor, $r->id, 'visit.changed', ['visit_id' => $v->id, 'before' => $before, 'after' => $v->only(['technician_id', 'planned_start', 'planned_end']), 'reason' => trim($input['reason'])]);

            return ['id' => $v->id, 'version' => $v->version];
        });
    }

    public function replaceLead(User $actor, int $id, int $version, int $leadId, string $reason, string $key): array
    {
        $p = WorkPackage::visibleTo($actor)->findOrFail($id);
        $this->root($actor, $p->service_request_id, 'manage');

        return $this->operations->run($actor, $key, 'package.lead', [$id, $version, $leadId, $reason], function () use ($actor, $p, $version, $leadId, $reason) {
            $r = $this->lockRoot($actor, $p->service_request_id, 'manage');
            $p = WorkPackage::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->version($p->version, $version);
            Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:5000'])->validate();
            $lead = User::whereKey($leadId)->lockForUpdate()->firstOrFail();
            $this->check($lead->hasRole('technician'), 'Выберите действующего ведущего мастера.', 'lead_id');
            $this->check(! in_array($p->status, ['accepted', 'cancelled']), 'Работа уже завершена.');
            $this->check($p->lead_id !== $leadId, 'Этот мастер уже отвечает за результат.', 'lead_id');
            $before = $p->lead_id;
            $p->update(['lead_id' => $leadId, 'version' => $p->version + 1]);
            $this->aggregate($r);
            $this->operations->event($actor, $r->id, 'package.lead_changed', ['package_id' => $p->id, 'before' => $before, 'after' => $leadId, 'reason' => trim($reason)]);

            return ['id' => $p->id];
        });
    }

    public function removeVisitScope(User $actor, int $id, int $version, string $reason, string $key): array
    {
        $v = Visit::findOrFail($id);
        $this->root($actor, $v->package->service_request_id, 'manage');

        return $this->operations->run($actor, $key, 'visit.scope_remove', [$id, $version, $reason], function () use ($actor, $v, $version, $reason) {
            $r = $this->lockRoot($actor, $v->package->service_request_id, 'manage');
            $p = WorkPackage::whereKey($v->work_package_id)->lockForUpdate()->firstOrFail();
            $v = Visit::whereKey($v->id)->lockForUpdate()->firstOrFail();
            $this->version($v->version, $version);
            Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|max:5000'])->validate();
            $this->check($p->allowsExecution(), 'Сначала разрешите текущую приёмку или замечание.');
            $this->check(in_array($p->kind, ['covered', 'diagnostic', 'maintenance']), 'Изменение платного объёма требует финансового согласования.');
            $this->check(! $v->continues_visit_id && ! Visit::where('continues_visit_id', $v->id)->exists(), 'Продолжение сохраняет обязательный объём. Измените время выезда или согласуйте отмену заявки.');
            $this->check($v->required && in_array($v->status, ['scheduled', 'interrupted']), 'Завершённый отчёт или текущую работу нельзя исключить этим действием.');
            $v->update(['required' => false, 'status' => $v->status === 'scheduled' ? 'cancelled' : 'interrupted', 'scope_removal_reason' => trim($reason), 'version' => $v->version + 1]);
            $p->increment('version');
            $this->aggregate($r);
            $this->operations->event($actor, $r->id, 'visit.cancelled', ['visit_id' => $v->id, 'reason' => trim($reason)]);

            return ['id' => $v->id];
        });
    }

    public function assessRework(User $actor, int $id, int $version, int $roundId, array $input, string $key): array
    {
        $p = WorkPackage::visibleTo($actor)->findOrFail($id);
        $this->root($actor, $p->service_request_id, 'manage');
        $input = Validator::make($input, ['classification' => 'required|in:service_defect,additional_scope', 'evidence' => 'required|string|max:20000', 'reason' => 'required|string|max:5000'])->validate();

        return $this->operations->run($actor, $key, 'rework.assess', [$id, $version, $roundId, $input], function () use ($actor, $p, $version, $roundId, $input) {
            $r = $this->lockRoot($actor, $p->service_request_id, 'manage');
            $p = WorkPackage::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->version($p->version, $version);
            $round = $p->rounds()->orderByDesc('id')->lockForUpdate()->firstOrFail();
            $this->check($p->status === 'rework_pending' && $round->id === $roundId && $round->status === 'rejected' && ! $p->assessments()->where('acceptance_round_id', $roundId)->exists(), 'Замечание уже изменилось или рассмотрено. Обновите данные.');
            $this->check(trim($input['evidence']) !== '' && trim($input['reason']) !== '', 'Опишите основания и решение.', 'evidence');
            $assessment = $p->assessments()->create([...$input, 'evidence' => trim($input['evidence']), 'reason' => trim($input['reason']), 'acceptance_round_id' => $roundId, 'actor_id' => $actor->id]);
            $defect = $input['classification'] === 'service_defect';
            $p->update(['status' => 'rework_pending', 'execution_round' => $p->execution_round + ($defect ? 1 : 0), 'version' => $p->version + 1]);
            $this->aggregate($r);
            $this->operations->event($actor, $r->id, 'rework.assessed', ['package_id' => $p->id, 'assessment_id' => $assessment->id, 'classification' => $input['classification']]);

            return ['id' => $assessment->id];
        });
    }

    public function allowPartialWork(User $actor, int $id, int $version, string $scope, string $key): array
    {
        $v = Visit::findOrFail($id);
        $this->root($actor, $v->package->service_request_id, 'manage');
        $scope = Validator::make(['scope' => trim($scope)], ['scope' => 'required|string|max:5000'])->validate()['scope'];

        return $this->operations->run($actor, $key, 'visit.partial_work', [$id, $version, $scope], function () use ($actor, $v, $version, $scope) {
            $r = $this->lockRoot($actor, $v->package->service_request_id, 'manage');
            $p = WorkPackage::whereKey($v->work_package_id)->lockForUpdate()->firstOrFail();
            $this->inventory->lockStock();
            $v = Visit::whereKey($v->id)->lockForUpdate()->firstOrFail();
            $this->version($v->version, $version);
            $this->check($v->status === 'scheduled' && $v->required && $p->allowsExecution(), 'Разрешение доступно только для предстоящего выезда.');
            $readiness = $this->inventory->readiness($p);
            $this->check(! $readiness['ready'], 'Материалы уже готовы. Разрешение на частичную работу не требуется.');
            $v->update(['partial_work_scope' => $scope, 'partial_work_fingerprint' => $readiness['fingerprint'], 'partial_work_actor_id' => $actor->id, 'version' => $v->version + 1]);
            $this->operations->event($actor, $r->id, 'visit.partial_work', ['visit_id' => $v->id, 'scope' => $scope, 'missing' => $readiness['missing']]);

            return ['id' => $v->id];
        });
    }

    public function visit(User $actor, int $id, int $version, string $command, array $input, string $key): array
    {
        $v = Visit::where('technician_id', $actor->id)->where('status', '!=', 'cancelled')->findOrFail($id);
        $this->check($actor->hasRole('technician'), 'Нужен доступ мастера.');
        $this->root($actor, $v->package->service_request_id);
        $this->check(in_array($command, ['start', 'draft', 'finish', 'interrupt', 'finish_submit']), 'Неизвестное действие.');

        return $this->operations->run($actor, $key, 'visit.'.$command, [$id, $version, $input], function () use ($actor, $v, $version, $command, $input) {
            $r = $this->lockRoot($actor, $v->package->service_request_id);
            $p = WorkPackage::whereKey($v->work_package_id)->lockForUpdate()->firstOrFail();
            $this->inventory->lockStock();
            $tech = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $v = Visit::whereKey($v->id)->lockForUpdate()->firstOrFail();
            $this->check($tech->hasRole('technician') && $v->technician_id === $actor->id, 'Назначение изменилось. Откройте задания заново.');
            $this->version($v->version, $version);
            if (! in_array($command, ['draft', 'interrupt'], true)) {
                $this->check($p->kind !== 'paid' || app(Finance::class)->executionReady($p), 'Нужны утверждённая смета и оплаченный аванс. Сохраните отчёт и остановите выезд — диспетчер организует следующий шаг.');
            }
            if ($command === 'start') {
                $this->check($v->status === 'scheduled' && $v->required && $v->execution_round === $p->execution_round, 'Выезд уже начат, завершён или больше не требуется.');
                $this->check($p->allowsExecution(), 'Диспетчер должен разрешить текущую приёмку или замечание.');
                $readiness = $this->inventory->readiness($p);
                $this->check($readiness['ready'] || ($v->partial_work_scope && $v->partial_work_fingerprint === $readiness['fingerprint']), 'Материалы ещё не выданы команде. Диспетчер может разрешить конкретную часть работы; полный результат пока недоступен.', 'stock');
                $this->check(! Visit::where('technician_id', $actor->id)->where('status', 'in_progress')->exists(), 'Сначала завершите или приостановите текущий выезд.');
                $v->update(['status' => 'in_progress', 'actual_start' => now(), 'version' => $v->version + 1]);
            } else {
                $this->check($v->status === 'in_progress', 'Отчёт этого выезда уже передан или работа ещё не начата.');
                $valid = Validator::make($input, ['report' => 'nullable|string|max:20000', 'checks' => 'required|array', 'checks.*' => 'boolean', 'labor_minutes' => 'nullable|integer|min:0|max:1440', 'reason' => 'nullable|string|max:5000', 'usage_confirmed' => 'nullable|boolean', 'usage' => 'sometimes|array|max:100', 'usage.*' => 'required|string|regex:/^\d{1,9}(?:[.,]\d{1,3})?$/D'])->validate();
                $checks = $v->checklist;
                $this->check(count($valid['checks']) === count($checks), 'Список проверки изменился. Обновите задание.');
                foreach ($checks as $i => &$item) {
                    $item['done'] = (bool) ($valid['checks'][$i] ?? false);
                } unset($item);
                $report = trim($valid['report'] ?? '');
                $usage = $valid['usage'] ?? [];
                if ($command !== 'draft') {
                    $this->check($valid['usage_confirmed'] ?? false, 'Подтвердите фактическое время и расход материалов.', 'usage_confirmed');
                    $this->check(isset($valid['labor_minutes']), 'Укажите фактически отработанные минуты.', 'labor_minutes');
                    if ($command === 'interrupt') {
                        $this->check(trim($valid['reason'] ?? '') !== '', 'Укажите, почему нельзя продолжить.', 'reason');
                    } else {
                        $this->check(! $v->partial_work_scope || $this->inventory->readiness($p)['ready'], 'Разрешена только часть работы. Запишите результат и остановите выезд; остаток продолжится отдельным выездом.', 'stock');
                        $this->check($report !== '', 'Коротко опишите, что сделано.', 'report');
                        $this->check(! collect($checks)->contains(fn ($i) => $i['required'] && ! $i['done']), 'Отметьте обязательные пункты проверки.', 'checks');
                        $this->check(! $p->photo_required || $v->attachments()->exists(), 'Добавьте обязательное фото.', 'photo');
                    }
                }
                $documents = $command === 'draft' ? null : $this->inventory->consumeLocked($actor, $v, $usage);
                $v->update(['report' => $report, 'checklist' => $checks, 'labor_minutes' => $valid['labor_minutes'] ?? null, 'usage_draft' => $usage,
                    ...($documents === null ? [] : ['stock_document_ids' => $documents]), 'version' => $v->version + 1,
                    ...($command === 'draft' ? [] : ['status' => $command === 'interrupt' ? 'interrupted' : 'completed', 'actual_end' => now(), 'interruption_reason' => $command === 'interrupt' ? trim($valid['reason']) : null]),
                ]);
                if ($command === 'finish_submit') {
                    $this->submitLocked($actor, $p, $report);
                }
            }
            if ($command !== 'draft') {
                $this->aggregate($r);
                $this->operations->event($actor, $r->id, 'visit.'.$command, ['visit_id' => $v->id]);
            }

            return ['id' => $v->id, 'version' => $v->version];
        });
    }

    private function submitLocked(User $actor, WorkPackage $p, string $summary): void
    {
        $this->check($p->lead_id === $actor->id && $actor->fresh()->hasRole('technician'), 'Только ведущий мастер передаёт общий результат.');
        $this->check($p->allowsExecution(), 'Результат уже передан или нужно разрешить замечание.');
        $visits = $p->visits()->orderBy('id')->lockForUpdate()->get();
        $continued = $visits->where('required', true)->where('status', '!=', 'cancelled')->pluck('continues_visit_id')->filter()->all();
        $outstanding = $visits->where('required', true)->whereNotIn('id', $continued);
        $current = $outstanding->where('execution_round', $p->execution_round);
        $this->check($visits->whereIn('status', ['scheduled', 'in_progress'])->isEmpty() && $current->isNotEmpty() && $outstanding->every(fn ($v) => $v->status === 'completed'), 'Осталась незавершённая работа команды.');
        $this->check(trim($summary) !== '', 'Добавьте короткий итог работ.', 'summary');
        $evidence = $visits->where('required', true)->map(function ($v) use ($p) {
            if ($v->status === 'completed') {
                $this->check(trim($v->report ?? '') !== '' && ! collect($v->checklist)->contains(fn ($i) => $i['required'] && ! $i['done']), 'В отчёте команды не хватает обязательных сведений.');
                $this->check(! $p->photo_required || $v->attachments()->exists(), 'В отчёте команды не хватает обязательного фото.');
            }

            return ['visit_id' => $v->id, 'technician' => $v->technician->name, 'status' => $v->status, 'continues_visit_id' => $v->continues_visit_id, 'interruption_reason' => $v->interruption_reason, 'report' => $v->report ?? '', 'checklist' => $v->checklist, 'labor_minutes' => $v->labor_minutes, 'attachment_ids' => $v->attachments()->pluck('id')->all(), 'materials' => $this->inventory->usageEvidence($v), 'started_at' => $v->actual_start, 'finished_at' => $v->actual_end];
        })->values()->all();
        $s = $p->results()->create(['round' => ($p->results()->max('round') ?? 0) + 1, 'lead_id' => $actor->id, 'summary' => trim($summary), 'contributions' => $evidence]);
        $p->rounds()->create(['result_snapshot_id' => $s->id]);
        $p->update(['status' => 'awaiting_acceptance', 'version' => $p->version + 1]);
    }

    public function submit(User $actor, int $packageId, int $version, string $summary, string $key): array
    {
        $p = WorkPackage::visibleTo($actor)->findOrFail($packageId);
        $this->root($actor, $p->service_request_id);

        return $this->operations->run($actor, $key, 'result.submit', [$packageId, $version, $summary], function () use ($actor, $p, $version, $summary) {
            $r = $this->lockRoot($actor, $p->service_request_id);
            $p = WorkPackage::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->version($p->version, $version);
            Validator::make(['summary' => $summary], ['summary' => 'required|string|max:20000'])->validate();
            $this->submitLocked($actor, $p, $summary);
            $this->aggregate($r);
            $this->operations->event($actor, $r->id, 'result.submitted', ['package_id' => $p->id]);

            return ['id' => $p->id];
        });
    }

    public function decide(User $actor, int $id, array $roundIds, string $decision, string $reason, string $key): array
    {
        $this->root($actor, $id, 'accept');
        Validator::make(['ids' => $roundIds, 'decision' => $decision, 'reason' => $reason], ['ids' => 'required|array|min:1|max:30', 'ids.*' => 'integer|distinct', 'decision' => 'required|in:accepted,rejected', 'reason' => ($decision === 'rejected' ? 'required' : 'nullable').'|string|max:5000'])->validate();
        $this->check($decision !== 'rejected' || count($roundIds) === 1, 'Выберите один результат с замечанием.');

        return $this->operations->run($actor, $key, 'result.decide', [$id, $roundIds, $decision, $reason], function () use ($actor, $id, $roundIds, $decision, $reason) {
            $r = $this->lockRoot($actor, $id, 'accept');
            $packages = $r->packages()->orderBy('id')->lockForUpdate()->get();
            $rounds = AcceptanceRound::whereIn('work_package_id', $packages->pluck('id'))->whereIn('id', $roundIds)->orderBy('id')->lockForUpdate()->get();
            $this->check($rounds->count() === count($roundIds) && $rounds->every(fn ($a) => $a->status === 'pending'), 'Один из результатов изменился. Проверьте список заново.');
            $this->check($decision !== 'rejected' || trim($reason) !== '', 'Опишите проблему.', 'reason');
            foreach ($rounds as $a) {
                $a->update(['status' => $decision, 'actor_id' => $actor->id, 'reason' => trim($reason) ?: null, 'decided_at' => now()]);
                $p = $packages->firstWhere('id', $a->work_package_id);
                $p->update(['status' => $decision === 'accepted' ? 'accepted' : 'rework_pending', 'version' => $p->version + 1]);
                if ($decision === 'accepted') {
                    app(Finance::class)->acceptedLocked($actor, $p, $a);
                }
            }
            $this->aggregate($r);
            $this->operations->event($actor, $id, 'result.'.$decision, ['round_ids' => $roundIds]);

            return ['id' => $id];
        });
    }

    public function acceptDiagnosticAndEstimate(User $actor, int $id, int $roundId, int $estimateId, string $key): array
    {
        $this->root($actor, $id, 'accept');

        return $this->operations->run($actor, $key, 'diagnostic.estimate_accept', [$id, $roundId, $estimateId], function () use ($actor, $id, $roundId, $estimateId, $key) {
            $r = $this->lockRoot($actor, $id, 'accept');
            $packages = $r->packages()->orderBy('id')->lockForUpdate()->get();
            $diagnosticPackageIds = $packages->where('kind', 'diagnostic')->pluck('id');
            $round = AcceptanceRound::whereKey($roundId)->whereIn('work_package_id', $diagnosticPackageIds)->lockForUpdate()->firstOrFail();
            $this->check($round->status === 'pending', 'Диагностика уже рассмотрена. Проверьте текущий результат.');
            $pendingEstimates = DB::table('estimates')->where('service_request_id', $id)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('estimate_decisions')->whereColumn('estimate_decisions.estimate_id', 'estimates.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('estimates as newer')->whereColumn('newer.group_key', 'estimates.group_key')->whereColumn('newer.revision', '>', 'estimates.revision'))
                ->get();
            $pendingDiagnosticRounds = AcceptanceRound::whereIn('work_package_id', $diagnosticPackageIds)->where('status', 'pending')->get();
            $this->check($pendingEstimates->count() === 1 && $pendingDiagnosticRounds->count() === 1 && $pendingEstimates->first()->id === $estimateId && $pendingDiagnosticRounds->first()->id === $roundId, 'Выберите одну пару «диагностика — смета» для принятия.', 'finance');
            $estimate = app(Finance::class)->decideEstimate($actor, $estimateId, 'approved', '', (string) Uuid::uuid5($key, 'estimate'));
            $this->decide($actor, $id, [$roundId], 'accepted', '', (string) Uuid::uuid5($key, 'diagnostic'));

            return ['id' => $id, 'invoice_id' => $estimate['invoice_id']];
        });
    }

    public function message(User $actor, int $id, array $input, string $key): array
    {
        $this->root($actor, $id);
        $input = Validator::make($input, ['body' => 'required|string|max:20000', 'visibility' => 'required|in:public,internal', 'type' => 'required|in:reply,time_change,help', 'visit_id' => 'nullable|integer'])->validate();
        $this->check($input['visibility'] !== 'internal' || $actor->kind === 'staff', 'Внутренние записи доступны сотрудникам.');
        if ($input['type'] === 'time_change') {
            $this->check($actor->kind === 'client' && $input['visibility'] === 'public', 'Перенос выезда запрашивает клиент.');
        }
        if ($input['type'] === 'help') {
            $this->check($actor->hasRole('technician') && $input['visibility'] === 'internal', 'Помощь мастеру направляется диспетчеру.');
        }

        return $this->operations->run($actor, $key, 'message.send', [$id, $input], function () use ($actor, $id, $input) {
            $r = $this->lockRoot($actor, $id);
            $this->check(trim($input['body']) !== '', 'Напишите сообщение.', 'body');
            if (! empty($input['visit_id'])) {
                $visit = Visit::whereHas('package', fn ($q) => $q->visibleTo($actor)->where('service_request_id', $id))->findOrFail($input['visit_id']);
                if ($input['type'] === 'time_change') {
                    $this->check($visit->status === 'scheduled', 'Этот выезд уже начат или отменён.');
                }
            } else {
                $this->check($input['type'] === 'reply', 'Выберите выезд.');
            }
            $m = $r->messages()->create([...$input, 'body' => trim($input['body']), 'author_id' => $actor->id]);
            $this->operations->event($actor, $id, 'message.'.$input['visibility'], ['message_id' => $m->id]);

            return ['id' => $m->id];
        });
    }

    public function cancel(User $actor, int $id, int $version, string $reason, string $key): array
    {
        $this->root($actor, $id, 'manage');

        return $this->operations->run($actor, $key, 'request.cancel', [$id, $version, $reason], function () use ($actor, $id, $version, $reason) {
            $r = $this->lockRoot($actor, $id, 'manage');
            $this->version($r->version, $version);
            $reason = trim($reason);
            Validator::make(['reason' => $reason], ['reason' => 'required|string|max:5000'])->validate();
            $packages = $r->packages()->orderBy('id')->lockForUpdate()->get();
            $visits = Visit::whereIn('work_package_id', $packages->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            foreach ($visits as $v) {
                if ($v->status === 'scheduled') {
                    $v->update(['status' => 'cancelled', 'version' => $v->version + 1]);
                }
                if ($v->status === 'in_progress') {
                    $v->update(['status' => 'interrupted', 'actual_end' => now(), 'interruption_reason' => $reason, 'version' => $v->version + 1]);
                }
            }
            foreach ($packages as $p) {
                if ($p->status !== 'accepted') {
                    $p->update(['status' => 'cancelled', 'version' => $p->version + 1]);
                }
                $p->rounds()->where('status', 'pending')->update(['status' => 'withdrawn', 'decided_at' => now(), 'actor_id' => $actor->id, 'reason' => $reason]);
            }
            $r->update(['status' => 'cancelled', 'cancellation_reason' => $reason, 'version' => $r->version + 1]);
            $this->operations->event($actor, $id, 'request.cancelled');

            return ['id' => $id];
        });
    }

    public function aggregate(ServiceRequest $r): void
    {
        if (in_array($r->status, ['closed', 'cancelled'])) {
            return;
        }
        $packages = $r->packages()->get();
        foreach ($packages as $p) {
            if (! $p->allowsExecution()) {
                continue;
            }
            $states = $p->outstandingVisits()->where('execution_round', $p->execution_round)->pluck('status');
            $p->update(['status' => $states->contains('in_progress') || ($states->isNotEmpty() && $states->every(fn ($s) => $s === 'completed')) ? 'in_progress' : ($states->contains('scheduled') ? 'scheduled' : ($p->execution_round > 1 ? 'rework_pending' : ($states->contains('interrupted') ? 'on_hold' : 'ready_to_schedule')))]);
        }
        $states = $r->packages()->where('status', '!=', 'cancelled')->pluck('status');
        $closed = $states->isNotEmpty() && $states->every(fn ($s) => $s === 'accepted')
            && ! Visit::whereIn('work_package_id', $packages->pluck('id'))->whereIn('status', ['scheduled', 'in_progress'])->exists();
        $settlement = $closed && ! app(Finance::class)->settled($r->id);
        $closed = $closed && ! $settlement;
        $status = $settlement ? 'awaiting_settlement' : ($closed ? 'closed' : ($states->contains('in_progress') ? 'in_progress' : ($states->contains('rework_pending') ? 'rework_pending' : ($states->contains('scheduled') ? 'assigned' : ($states->contains('awaiting_acceptance') ? 'awaiting_acceptance' : ($states->isNotEmpty() && $states->every(fn ($s) => $s === 'on_hold') ? 'on_hold' : ($packages->isEmpty() ? 'new' : 'processing')))))));
        $r->update(['status' => $status, 'closed_at' => $closed ? now() : null, 'version' => $r->version + 1]);
    }
}
