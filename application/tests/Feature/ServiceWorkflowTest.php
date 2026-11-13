<?php

namespace Tests\Feature;

use App\Actions\Notifications\DeliverPortalOutbox;
use App\Actions\Photos\SavePhoto;
use App\Actions\ServiceRequests\SendMessage;
use App\Actions\ServiceRequests\Workflow;
use App\Livewire\CreateRequest;
use App\Livewire\Inbox;
use App\Livewire\Job;
use App\Livewire\RequestDetail;
use App\Livewire\Workspace;
use App\Models\AcceptanceRound;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\PortalNotification;
use App\Models\ServiceRequest;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkPackage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $contact;

    private User $dispatcher;

    private User $tech;

    private User $worker;

    private Site $site;

    private Contract $contract;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $org = DB::table('organizations')->insertGetId(['name' => 'Fictional client', 'active' => true]);
        $this->site = Site::create(['organization_id' => $org, 'name' => 'Fictional office', 'address' => 'Sample address']);
        $this->client = User::factory()->create(['kind' => 'client', 'organization_id' => $org, 'roles' => [], 'accept_work' => true]);
        $this->contact = User::factory()->create(['kind' => 'client', 'organization_id' => $org, 'roles' => [], 'accept_work' => false]);
        $this->contact->sites()->attach($this->site);
        $this->dispatcher = User::factory()->create(['kind' => 'staff', 'roles' => ['dispatcher'], 'organization_id' => null]);
        $this->tech = User::factory()->create(['kind' => 'staff', 'roles' => ['technician'], 'organization_id' => null]);
        $this->worker = User::factory()->create(['kind' => 'staff', 'roles' => ['technician'], 'organization_id' => null]);
        $this->contract = Contract::create(['organization_id' => $org, 'number' => 'TEST-1', 'title' => 'Covered diagnostics', 'starts_on' => now()->subYear(), 'ends_on' => now()->addYear(), 'covered_categories' => ['plumbing'], 'terms' => ['currency' => 'RUB']]);
        $this->contract->sites()->attach($this->site);
        $this->workflow = app(Workflow::class);
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function requestRecord(): ServiceRequest
    {
        return ServiceRequest::findOrFail($this->workflow->create($this->client, ['site_id' => $this->site->id, 'description' => "Pipe is leaking\nKitchen"], $this->key())['id']);
    }

    private function package(ServiceRequest $r): WorkPackage
    {
        return WorkPackage::findOrFail($this->workflow->addPackage($this->dispatcher, $r->id, $r->fresh()->version, ['title' => 'Inspect pipe', 'scope' => 'Covered inspection without materials', 'category' => 'plumbing', 'kind' => 'diagnostic', 'contract_id' => $this->contract->id, 'lead_id' => $this->tech->id, 'checklist' => ['Check safety'], 'photo_required' => false], $this->key())['id']);
    }

    private function schedule(WorkPackage $p, User $tech, int $hours = 1): Visit
    {
        $tz = $this->site->timezone;

        return Visit::findOrFail($this->workflow->schedule($this->dispatcher, $p->id, $p->fresh()->version, ['technician_id' => $tech->id, 'start' => now($tz)->addHours($hours)->format('Y-m-d\TH:i'), 'end' => now($tz)->addHours($hours + 1)->format('Y-m-d\TH:i')], $this->key())['id']);
    }

    private function start(Visit $v, User $u): void
    {
        $this->workflow->visit($u, $v->id, $v->fresh()->version, 'start', [], $this->key());
    }

    private function report(): array
    {
        return ['report' => 'Inspected pipe; source identified.', 'checks' => [true], 'labor_minutes' => 20, 'usage_confirmed' => true];
    }

    private function readyRound(): AcceptanceRound
    {
        $r = $this->requestRecord();
        $p = $this->package($r);
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        $this->workflow->visit($this->tech, $v->id, $v->fresh()->version, 'finish_submit', $this->report(), $this->key());

        return $p->rounds()->firstOrFail();
    }

    public function test_continuation_preserves_interrupted_evidence_and_blocks_until_finished(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        $this->workflow->visit($this->tech, $v->id, $v->fresh()->version, 'interrupt', [...$this->report(), 'checks' => [false], 'reason' => 'No access'], $this->key());
        $before = $v->fresh()->toArray();
        $input = ['technician_id' => $this->worker->id, 'start' => now($this->site->timezone)->addHours(3)->format('Y-m-d\TH:i'), 'end' => now($this->site->timezone)->addHours(4)->format('Y-m-d\TH:i'), 'continues_visit_id' => $v->id, 'continues_visit_version' => $v->fresh()->version, 'continuation_reason' => 'Finish remaining inspection'];
        $version = $p->fresh()->version;
        $key = $this->key();
        $result = $this->workflow->schedule($this->dispatcher, $p->id, $version, $input, $key);
        $this->assertSame($result, $this->workflow->schedule($this->dispatcher, $p->id, $version, $input, $key));
        $this->assertSame($before, $v->fresh()->toArray());
        try {
            $this->workflow->submit($this->tech, $p->id, $p->fresh()->version, 'Premature', $this->key());
            $this->fail('Unfinished continuation accepted');
        } catch (ValidationException) {
        }
        $next = Visit::findOrFail($result['id']);
        $this->start($next, $this->worker);
        $this->workflow->visit($this->worker, $next->id, $next->fresh()->version, 'finish', $this->report(), $this->key());
        Livewire::actingAs($this->tech)->test(Workspace::class)->assertSee('Проверить результат команды');
        Livewire::actingAs($this->tech)->test(RequestDetail::class, ['id' => $p->service_request_id])
            ->assertSet('summaries.'.$p->id, $this->worker->name.': '.$this->report()['report'])
            ->call('submit', $p->id)->assertHasNoErrors();
        $evidence = $p->results()->firstOrFail()->contributions;
        $this->assertCount(2, $evidence);
        $this->assertSame('interrupted', $evidence[0]['status']);
        $this->assertSame('No access', $evidence[0]['interruption_reason']);
        $this->assertFalse($evidence[0]['checklist'][0]['done']);
        $this->assertSame($v->id, $evidence[1]['continues_visit_id']);
    }

    public function test_continuation_cannot_be_duplicated_or_removed_from_required_work(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        $this->workflow->visit($this->tech, $v->id, $v->fresh()->version, 'interrupt', [...$this->report(), 'reason' => 'No access'], $this->key());
        $input = ['technician_id' => $this->worker->id, 'start' => now($this->site->timezone)->addHours(3)->format('Y-m-d\TH:i'), 'end' => now($this->site->timezone)->addHours(4)->format('Y-m-d\TH:i'), 'continues_visit_id' => $v->id, 'continues_visit_version' => $v->fresh()->version, 'continuation_reason' => 'Resume'];
        $next = $this->workflow->schedule($this->dispatcher, $p->id, $p->fresh()->version, $input, $this->key())['id'];
        try {
            $this->workflow->schedule($this->dispatcher, $p->id, $p->fresh()->version, [...$input, 'technician_id' => $this->tech->id], $this->key());
            $this->fail('Duplicate continuation accepted');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('visits', 2);
        $this->expectException(ValidationException::class);
        $this->workflow->removeVisitScope($this->dispatcher, $next, 1, 'Remove obligation', $this->key());
    }

    public function test_minimal_intake_and_retry_have_one_effect(): void
    {
        $key = $this->key();
        $input = ['site_id' => $this->site->id, 'description' => 'Pipe is leaking'];
        $first = $this->workflow->create($this->contact, $input, $key);
        $this->assertSame($first, $this->workflow->create($this->contact, $input, $key));
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertNull(ServiceRequest::first()->category);
    }

    public function test_request_numbers_are_sequential_and_readable(): void
    {
        $first = $this->requestRecord();
        $second = $this->requestRecord();
        $this->assertSame('СП-00000001', $first->number);
        $this->assertSame('СП-00000002', $second->number);
    }

    public function test_new_problem_from_finished_request_creates_linked_request(): void
    {
        $old = $this->requestRecord();
        $this->workflow->cancel($this->dispatcher, $old->id, $old->fresh()->version, 'Resolved by phone', $this->key());
        Livewire::actingAs($this->client)->test(RequestDetail::class, ['id' => $old->id])->assertSee('Сообщить о новой проблеме');
        Livewire::withQueryParams(['related' => $old->id])->actingAs($this->client)->test(CreateRequest::class)
            ->assertSet('site_id', $this->site->id)->assertSee($old->number)
            ->set('description', 'Leak returned')->call('send')->assertHasNoErrors();
        $new = ServiceRequest::latest('id')->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($old->id, $new->related_request_id);
        $this->assertSame('cancelled', $old->fresh()->status);
    }

    public function test_hidden_related_request_is_ignored_on_intake(): void
    {
        $other = DB::table('organizations')->insertGetId(['name' => 'Other client', 'active' => true]);
        $hiddenSite = Site::create(['organization_id' => $other, 'name' => 'Other office', 'address' => 'Elsewhere']);
        $hidden = ServiceRequest::create(['site_id' => $hiddenSite->id, 'organization_id' => $other, 'author_id' => $this->dispatcher->id, 'number' => 'HIDDEN-1', 'title' => 'Secret', 'description' => 'Secret']);
        Livewire::withQueryParams(['related' => $hidden->id])->actingAs($this->client)->test(CreateRequest::class)
            ->assertSet('relatedRequestId', null)->assertDontSee('HIDDEN-1');
    }

    public function test_time_change_request_is_a_message_and_keeps_appointment(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        Livewire::actingAs($this->client)->test(RequestDetail::class, ['id' => $p->service_request_id])
            ->call('requestTimeChange', $v->id)->set('body', 'After 15:00 please')->call('sendMessage')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('request_messages', ['visit_id' => $v->id, 'type' => 'time_change', 'visibility' => 'public']);
        $this->assertSame($v->planned_start->toIso8601String(), $v->fresh()->planned_start->toIso8601String());
    }

    public function test_client_cannot_request_time_change_for_visit_of_another_request(): void
    {
        $a = $this->requestRecord();
        $b = $this->requestRecord();
        $pa = $this->package($a);
        $pb = $this->package($b);
        $va = $this->schedule($pa, $this->tech, 1);
        $vb = $this->schedule($pb, $this->tech, 3);
        Livewire::actingAs($this->client)->test(RequestDetail::class, ['id' => $a->id])
            ->call('requestTimeChange', $vb->id)
            ->assertForbidden();
        $this->assertDatabaseMissing('request_messages', ['visit_id' => $vb->id]);
    }

    public function test_retry_key_cannot_change_input(): void
    {
        $key = $this->key();
        $input = ['site_id' => $this->site->id, 'description' => 'Pipe is leaking'];
        $this->workflow->create($this->client, $input, $key);
        $this->expectException(ValidationException::class);
        $this->workflow->create($this->client, [...$input, 'description' => 'Different'], $key);
    }

    public function test_site_memberships_are_enforced_before_lists_and_actions(): void
    {
        $second = Site::create(['organization_id' => $this->site->organization_id, 'name' => 'Restricted office', 'address' => 'Hidden']);
        $this->assertFalse(Site::visibleTo($this->contact)->whereKey($second->id)->exists());
        $this->assertTrue(Site::visibleTo($this->client)->whereKey($second->id)->exists());
        $this->actingAs($this->contact)->withSession(['account_version' => 1])->get('/requests/999')->assertRedirect(route('home'))->assertSessionHas('status', 'Заявка недоступна или удалена.');
    }

    public function test_missing_contract_blocks_intake_and_rolls_back_audit(): void
    {
        $this->contract->update(['active' => false]);
        try {
            $this->requestRecord();
            $this->fail('Expected coverage validation.');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('action_executions', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_parallel_visits_allow_different_technicians(): void
    {
        $r = $this->requestRecord();
        $p = $this->package($r);
        $a = $this->schedule($p, $this->tech);
        $b = $this->schedule($p, $this->worker);
        $this->start($a, $this->tech);
        $this->start($b, $this->worker);
        $this->assertSame(2, Visit::where('status', 'in_progress')->count());
    }

    public function test_overlapping_booking_is_rejected(): void
    {
        $p = $this->package($this->requestRecord());
        $this->schedule($p, $this->tech);
        $this->expectException(ValidationException::class);
        $this->schedule($p, $this->tech);
    }

    public function test_adjacent_half_open_bookings_are_allowed(): void
    {
        $p = $this->package($this->requestRecord());
        $this->schedule($p, $this->tech, 1);
        $this->schedule($p, $this->tech, 2);
        $this->assertDatabaseCount('visits', 2);
    }

    public function test_actual_execution_conflict_blocks_even_after_planned_end(): void
    {
        $p = $this->package($this->requestRecord());
        $a = $this->schedule($p, $this->tech, 1);
        $b = $this->schedule($p, $this->tech, 3);
        $this->start($a, $this->tech);
        $this->expectException(ValidationException::class);
        $this->start($b, $this->tech);
    }

    public function test_independent_worker_drafts_do_not_conflict(): void
    {
        $p = $this->package($this->requestRecord());
        $a = $this->schedule($p, $this->tech);
        $b = $this->schedule($p, $this->worker);
        $this->start($a, $this->tech);
        $this->start($b, $this->worker);
        $rootVersion = $p->request->fresh()->version;
        $this->workflow->visit($this->tech, $a->id, $a->fresh()->version, 'draft', $this->report(), $this->key());
        $this->workflow->visit($this->worker, $b->id, $b->fresh()->version, 'draft', $this->report(), $this->key());
        $this->assertSame($rootVersion, $p->request->fresh()->version);
    }

    public function test_missing_checklist_rolls_back_completion(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        try {
            $this->workflow->visit($this->tech, $v->id, $v->fresh()->version, 'finish_submit', [...$this->report(), 'checks' => [false]], $this->key());
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame('in_progress', $v->fresh()->status);
        $this->assertDatabaseCount('result_snapshots', 0);
    }

    public function test_sole_completion_cannot_bypass_another_required_visit(): void
    {
        $p = $this->package($this->requestRecord());
        $a = $this->schedule($p, $this->tech);
        $this->schedule($p, $this->worker);
        $this->start($a, $this->tech);
        try {
            $this->workflow->visit($this->tech, $a->id, $a->fresh()->version, 'finish_submit', $this->report(), $this->key());
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame('in_progress', $a->fresh()->status);
        $this->workflow->visit($this->tech, $a->id, $a->fresh()->version, 'finish', $this->report(), $this->key());
        $this->assertSame('completed', $a->fresh()->status);
    }

    public function test_acceptance_is_separate_and_closes_covered_work_once(): void
    {
        $a = $this->readyRound();
        $r = $a->package->request;
        $this->assertSame('awaiting_acceptance', $r->status);
        $key = $this->key();
        $this->workflow->decide($this->client, $r->id, [$a->id], 'accepted', '', $key);
        $this->assertSame('closed', $r->fresh()->status);
        $this->workflow->decide($this->client, $r->id, [$a->id], 'accepted', '', $key);
        $this->assertDatabaseCount('acceptance_rounds', 1);
    }

    public function test_nonapprover_cannot_accept(): void
    {
        $a = $this->readyRound();
        $this->assertFalse(Gate::forUser($this->contact)->allows('accept', $a->package->request));
        $this->expectException(AuthorizationException::class);
        $this->workflow->decide($this->contact, $a->package->service_request_id, [$a->id], 'accepted', '', $this->key());
    }

    public function test_return_preserves_result_and_requires_staff_resolution(): void
    {
        $a = $this->readyRound();
        $this->workflow->decide($this->client, $a->package->service_request_id, [$a->id], 'rejected', 'Still leaking', $this->key());
        $this->assertSame('rework_pending', $a->package->fresh()->status);
        $this->assertSame('rejected', $a->fresh()->status);
        $this->assertDatabaseCount('result_snapshots', 1);
    }

    public function test_composite_stale_decision_is_atomic(): void
    {
        $a = $this->readyRound();
        $r = $a->package->request;
        $p = $this->package($r);
        $v = $this->schedule($p, $this->tech, 3);
        $this->start($v, $this->tech);
        $this->workflow->visit($this->tech, $v->id, $v->fresh()->version, 'finish_submit', $this->report(), $this->key());
        $b = $p->rounds()->firstOrFail();
        $this->workflow->decide($this->client, $r->id, [$a->id], 'accepted', '', $this->key());
        try {
            $this->workflow->decide($this->client, $r->id, [$a->id, $b->id], 'accepted', '', $this->key());
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame('pending', $b->fresh()->status);
    }

    public function test_time_change_is_only_a_message(): void
    {
        $r = $this->requestRecord();
        $p = $this->package($r);
        $v = $this->schedule($p, $this->tech);
        $before = $v->planned_start;
        $this->workflow->message($this->contact, $r->id, ['body' => 'Please come later', 'visibility' => 'public', 'type' => 'time_change', 'visit_id' => $v->id], $this->key());
        $this->assertTrue($before->equalTo($v->fresh()->planned_start));
        $this->assertDatabaseCount('request_messages', 1);
    }

    public function test_printable_result_uses_snapshot_and_package_access(): void
    {
        $this->withSession(['account_version' => 1, 'mfa_passed' => true]);
        $this->worker->forceFill(['totp_confirmed_at' => now()])->save();
        $round = $this->readyRound();
        $result = $round->result;
        $package = WorkPackage::findOrFail($round->work_package_id);
        $package->visits()->update(['report' => 'Later mutable report']);
        $url = route('documents.result', ['id' => $package->service_request_id, 'result' => $result->id]);
        $this->actingAs($this->contact)->get($url)->assertOk()->assertSee('Inspected pipe; source identified.')->assertDontSee('Later mutable report');
        $this->actingAs($this->worker)->get($url)->assertNotFound();
        $this->actingAs($this->contact)->get(route('documents.result', ['id' => $this->requestRecord()->id, 'result' => $result->id]))->assertNotFound();
    }

    public function test_internal_message_attachments_are_atomic_private_and_retry_safe(): void
    {
        $this->withSession(['account_version' => 1, 'mfa_passed' => true]);
        $this->dispatcher->forceFill(['totp_confirmed_at' => now()])->save();
        Storage::fake('local');
        $request = $this->requestRecord();
        $file = UploadedFile::fake()->createWithContent('internal.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $input = ['body' => 'Private evidence', 'visibility' => 'internal', 'type' => 'reply'];
        $key = $this->key();
        $action = app(SendMessage::class);
        $result = $action->execute($this->dispatcher, $request->id, $input, [$file], $key);
        $this->assertSame($result, $action->execute($this->dispatcher, $request->id, $input, [$file], $key));
        $attachment = Attachment::firstOrFail();
        $this->assertDatabaseCount('request_messages', 1);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('messages'));
        Livewire::actingAs($this->contact)->test(RequestDetail::class, ['id' => $request->id])->assertDontSee('internal.pdf')->assertViewHas('photos', fn ($photos) => $photos->isEmpty());
        $this->actingAs($this->contact)->get(route('photos.show', $attachment))->assertNotFound();
        $this->actingAs($this->dispatcher)->withSession(['mfa_passed' => true])->get(route('photos.show', $attachment))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'attachment; filename=internal.pdf');
        try {
            $action->execute($this->dispatcher, $request->id, [...$input, 'body' => 'Changed'], [$file], $key);
            $this->fail('A retry key must not accept changed content.');
        } catch (ValidationException) {
        }
        $this->assertCount(1, Storage::disk('local')->allFiles('messages'));
    }

    public function test_failed_message_attachment_keeps_text_and_rolls_back_all_files(): void
    {
        $this->withSession(['account_version' => 1, 'mfa_passed' => true]);
        $this->client->forceFill(['totp_confirmed_at' => now()])->save();
        Storage::fake('local');
        $request = $this->requestRecord();
        $page = Livewire::actingAs($this->contact)->test(RequestDetail::class, ['id' => $request->id])
            ->set('body', 'Keep this message')->set('messageFiles', [UploadedFile::fake()->image('good.png'), UploadedFile::fake()->create('bad.exe', 10, 'application/octet-stream')]);
        $page->call('sendMessage')->assertHasErrors('messageFiles.1')->assertSet('body', 'Keep this message');
        $this->assertDatabaseCount('request_messages', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('messages'));
        $page->call('removeMessageFile', 1)->call('sendMessage')->assertHasNoErrors()->assertSet('body', '')->assertSet('messageFiles', []);
        $attachment = Attachment::firstOrFail();
        $this->assertSame('image/jpeg', $attachment->mime);
        $this->actingAs($this->client)->get(route('photos.show', $attachment))->assertOk();
    }

    public function test_message_failure_after_file_storage_leaves_no_orphans(): void
    {
        Storage::fake('local');
        $request = $this->requestRecord();
        try {
            app(SendMessage::class)->execute($this->contact, $request->id, ['body' => 'Not authorized', 'visibility' => 'internal', 'type' => 'reply'], [UploadedFile::fake()->image('private.jpg')], $this->key());
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('attachments', 0);
        $this->assertDatabaseCount('request_messages', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('messages'));
    }

    public function test_clients_do_not_receive_internal_messages(): void
    {
        $r = $this->requestRecord();
        $this->workflow->message($this->dispatcher, $r->id, ['body' => 'INTERNAL-SECRET', 'visibility' => 'internal', 'type' => 'reply'], $this->key());
        Livewire::actingAs($this->contact)->test(RequestDetail::class, ['id' => $r->id])->assertDontSee('INTERNAL-SECRET');
    }

    public function test_cancel_interrupts_active_visits_without_acceptance(): void
    {
        $r = $this->requestRecord();
        $p = $this->package($r);
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        $this->workflow->cancel($this->dispatcher, $r->id, $r->fresh()->version, 'Client canceled', $this->key());
        $this->assertSame('interrupted', $v->fresh()->status);
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertDatabaseCount('acceptance_rounds', 0);
    }

    public function test_cancellation_reason_is_separate_from_rejection_reason(): void
    {
        $r = $this->requestRecord();
        $p = $this->package($r);
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        Livewire::actingAs($this->dispatcher)->test(RequestDetail::class, ['id' => $r->id])
            ->set('reason', 'Round issue')
            ->set('cancelReason', 'Client cancelled')
            ->call('cancel')
            ->assertHasNoErrors();
        $this->assertSame('Client cancelled', $r->fresh()->cancellation_reason);
        $this->assertSame('interrupted', $v->fresh()->status);
    }

    public function test_cancel_reason_is_trimmed_before_storage(): void
    {
        $r = $this->requestRecord();
        $p = $this->package($r);
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        $this->workflow->cancel($this->dispatcher, $r->id, $r->fresh()->version, "  Client canceled \t", $this->key());
        $this->assertSame('Client canceled', $r->fresh()->cancellation_reason);
        $this->assertSame('Client canceled', $v->fresh()->interruption_reason);
    }

    public function test_stale_draft_does_not_overwrite_report(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        $version = $v->fresh()->version;
        $this->workflow->visit($this->tech, $v->id, $version, 'draft', $this->report(), $this->key());
        try {
            $this->workflow->visit($this->tech, $v->id, $version, 'draft', [...$this->report(), 'report' => 'Overwrite'], $this->key());
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame($this->report()['report'], $v->fresh()->report);
    }

    public function test_livewire_intake_is_minimal(): void
    {
        Livewire::actingAs($this->contact)->test(CreateRequest::class)->assertSet('site_id', $this->site->id)->set('description', 'Pipe is leaking')->call('send')->assertHasNoErrors();
        $this->assertDatabaseCount('service_requests', 1);
    }

    public function test_create_request_success_message_includes_number(): void
    {
        Livewire::actingAs($this->contact)->test(CreateRequest::class)
            ->set('description', 'Pipe is leaking')
            ->call('send')
            ->assertRedirect(route('requests.show', 1))
            ->assertSessionHas('status', 'Заявка СП-00000001 отправлена. Диспетчер планирует выезд.');
    }

    public function test_create_request_rejects_non_photo_files(): void
    {
        Livewire::actingAs($this->contact)->test(CreateRequest::class)
            ->set('description', 'Pipe is leaking')
            ->set('photos', [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
            ->call('send')
            ->assertHasErrors('photos.0');
    }

    private function bookingInput(User $tech, int $hours = 4): array
    {
        return ['technician_id' => $tech->id, 'start' => now($this->site->timezone)->addHours($hours)->format('Y-m-d\TH:i'), 'end' => now($this->site->timezone)->addHours($hours + 1)->format('Y-m-d\TH:i'), 'reason' => 'Agreed with client'];
    }

    private function assessment(string $classification = 'service_defect'): array
    {
        return ['classification' => $classification, 'evidence' => 'Inspection confirms the agreed scope remains incomplete.', 'reason' => 'Arrange a company-funded corrective visit.'];
    }

    public function test_booking_change_is_audited_idempotent_and_revokes_former_assignment(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->worker);
        app(DeliverPortalOutbox::class)->execute();
        $this->assertTrue(PortalNotification::visibleTo($this->worker)->exists());
        $key = $this->key();
        $version = $v->version;
        $input = $this->bookingInput($this->tech);
        $first = $this->workflow->changeBooking($this->dispatcher, $v->id, $version, $input, $key);
        $this->assertSame($first, $this->workflow->changeBooking($this->dispatcher, $v->id, $version, $input, $key));
        $this->assertSame($this->tech->id, $v->fresh()->technician_id);
        $this->assertFalse(ServiceRequest::visibleTo($this->worker)->whereKey($p->service_request_id)->exists());
        $this->assertFalse(PortalNotification::visibleTo($this->worker)->exists());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'visit.changed')->count());
        $audit = json_decode(DB::table('audit_events')->where('action', 'visit.changed')->value('data'), true);
        $this->assertSame($this->worker->id, $audit['before']['technician_id']);
        $this->assertSame($this->tech->id, $p->fresh()->lead_id);
        Livewire::actingAs($this->worker)->test(Inbox::class)->assertDontSee('Выезд запланирован');
    }

    public function test_booking_change_checks_overlap_and_rolls_back(): void
    {
        $p = $this->package($this->requestRecord());
        $this->schedule($p, $this->tech, 4);
        $v = $this->schedule($p, $this->worker);
        try {
            $this->workflow->changeBooking($this->dispatcher, $v->id, $v->version, $this->bookingInput($this->tech), $this->key());
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame($this->worker->id, $v->fresh()->technician_id);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'visit.changed')->count());
    }

    public function test_started_visit_cannot_be_reassigned(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->worker);
        $this->start($v, $this->worker);
        try {
            $this->workflow->changeBooking($this->dispatcher, $v->id, $v->fresh()->version, $this->bookingInput($this->tech), $this->key());
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame($this->worker->id, $v->fresh()->technician_id);
        $this->assertSame('in_progress', $v->fresh()->status);
    }

    public function test_stale_booking_change_does_not_overwrite_new_time(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $version = $v->version;
        $this->workflow->changeBooking($this->dispatcher, $v->id, $version, $this->bookingInput($this->tech), $this->key());
        $changed = $v->fresh()->planned_start;
        try {
            $this->workflow->changeBooking($this->dispatcher, $v->id, $version, $this->bookingInput($this->tech, 6), $this->key());
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertTrue($changed->equalTo($v->fresh()->planned_start));
    }

    public function test_replacing_lead_preserves_ongoing_visit_ownership(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        $key = $this->key();
        $version = $p->fresh()->version;
        $first = $this->workflow->replaceLead($this->dispatcher, $p->id, $version, $this->worker->id, 'Lead unavailable', $key);
        $this->assertSame($first, $this->workflow->replaceLead($this->dispatcher, $p->id, $version, $this->worker->id, 'Lead unavailable', $key));
        $this->assertSame($this->worker->id, $p->fresh()->lead_id);
        $this->assertSame($this->tech->id, $v->fresh()->technician_id);
        $this->workflow->visit($this->tech, $v->id, $v->fresh()->version, 'finish', $this->report(), $this->key());
        $this->workflow->submit($this->worker, $p->id, $p->fresh()->version, 'Completed team work', $this->key());
        $this->assertSame($this->worker->id, $p->results()->first()->lead_id);
    }

    public function test_former_lead_without_visits_loses_access(): void
    {
        $p = $this->package($this->requestRecord());
        $this->workflow->replaceLead($this->dispatcher, $p->id, $p->version, $this->worker->id, 'New lead', $this->key());
        $this->assertFalse(WorkPackage::visibleTo($this->tech)->whereKey($p->id)->exists());
    }

    public function test_service_defect_requires_new_visit_and_preserves_rejected_evidence(): void
    {
        $round = $this->readyRound();
        $p = $round->package;
        $snapshot = $round->result->toArray();
        $this->workflow->decide($this->client, $p->service_request_id, [$round->id], 'rejected', 'Still leaking', $this->key());
        $version = $p->fresh()->version;
        $key = $this->key();
        $first = $this->workflow->assessRework($this->dispatcher, $p->id, $version, $round->id, $this->assessment(), $key);
        $this->assertSame('rework_pending', $p->fresh()->status);
        $this->assertSame('rework_pending', $p->request->fresh()->status);
        $this->assertSame($first, $this->workflow->assessRework($this->dispatcher, $p->id, $version, $round->id, $this->assessment(), $key));
        try {
            $this->workflow->submit($this->tech, $p->id, $p->fresh()->version, 'Unchanged result', $this->key());
            $this->fail();
        } catch (ValidationException) {
        }
        $v = $this->schedule($p, $this->tech, 4);
        $this->assertSame(2, $v->execution_round);
        $this->start($v, $this->tech);
        $this->workflow->visit($this->tech, $v->id, $v->fresh()->version, 'finish_submit', [...$this->report(), 'report' => 'Leak repaired'], $this->key());
        $next = $p->rounds()->latest('id')->first();
        $this->assertNotSame($round->id, $next->id);
        $this->assertSame(2, $next->result->round);
        $this->assertSame($snapshot, $round->result->fresh()->toArray());
        $this->assertSame('rejected', $round->fresh()->status);
        $this->assertCount(2, $next->result->contributions);
        $this->workflow->decide($this->client, $p->service_request_id, [$next->id], 'accepted', '', $this->key());
        $this->assertSame('closed', $p->request->fresh()->status);
    }

    public function test_new_scope_assessment_cannot_force_acceptance_or_unlock_work(): void
    {
        $round = $this->readyRound();
        $p = $round->package;
        $this->workflow->decide($this->client, $p->service_request_id, [$round->id], 'rejected', 'Extra work needed', $this->key());
        $this->workflow->assessRework($this->dispatcher, $p->id, $p->fresh()->version, $round->id, $this->assessment('additional_scope'), $this->key());
        $this->assertSame('rework_pending', $p->fresh()->status);
        $this->assertSame('rejected', $round->fresh()->status);
        $this->assertDatabaseCount('result_snapshots', 1);
        $this->expectException(ValidationException::class);
        $this->schedule($p, $this->tech, 4);
    }

    public function test_technician_cannot_assess_responsibility(): void
    {
        $round = $this->readyRound();
        $p = $round->package;
        $this->workflow->decide($this->client, $p->service_request_id, [$round->id], 'rejected', 'Defect', $this->key());
        $this->expectException(AuthorizationException::class);
        $this->workflow->assessRework($this->tech, $p->id, $p->fresh()->version, $round->id, $this->assessment(), $this->key());
    }

    public function test_interrupted_visit_can_be_replaced_without_rewriting_report(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        $this->workflow->visit($this->tech, $v->id, $v->fresh()->version, 'interrupt', [...$this->report(), 'reason' => 'No access'], $this->key());
        $report = $v->fresh()->report;
        $this->workflow->removeVisitScope($this->dispatcher, $v->id, $v->fresh()->version, 'Remaining scope moved to replacement visit', $this->key());
        $next = $this->schedule($p, $this->tech, 4);
        $this->start($next, $this->tech);
        $this->workflow->visit($this->tech, $next->id, $next->fresh()->version, 'finish_submit', $this->report(), $this->key());
        $this->assertSame('interrupted', $v->fresh()->status);
        $this->assertFalse($v->fresh()->required);
        $this->assertSame($report, $v->fresh()->report);
        $this->assertCount(1, $p->results()->first()->contributions);
    }

    public function test_active_visit_cannot_be_removed_from_required_work(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        $this->expectException(ValidationException::class);
        $this->workflow->removeVisitScope($this->dispatcher, $v->id, $v->fresh()->version, 'Omit work', $this->key());
    }

    public function test_outbox_recipients_follow_event_matrix_and_retries_deduplicate(): void
    {
        $round = $this->readyRound();
        $p = $round->package;
        $this->workflow->message($this->dispatcher, $p->service_request_id, ['body' => 'INTERNAL-NOTICE', 'visibility' => 'internal', 'type' => 'reply'], $this->key());
        $outbox = app(DeliverPortalOutbox::class);
        $delivered = $outbox->execute();
        $this->assertGreaterThan(0, $delivered);
        $count = PortalNotification::count();
        $this->assertSame(0, $outbox->execute());
        $this->assertSame(1, PortalNotification::where('user_id', $this->dispatcher->id)->where('title', 'Получена новая заявка')->count());
        $this->assertSame(0, PortalNotification::where('user_id', $this->client->id)->where('title', 'Получена новая заявка')->count());
        $this->assertSame(1, PortalNotification::where('user_id', $this->client->id)->where('title', 'Проверьте результат работ')->count());
        $this->assertSame(0, PortalNotification::where('user_id', $this->contact->id)->where('title', 'Проверьте результат работ')->count());
        $this->assertSame(0, PortalNotification::where('user_id', $this->contact->id)->where('title', 'Новое сообщение по заявке')->count());
        DB::table('outbox_events')->update(['status' => 'pending']);
        $outbox->execute();
        $this->assertSame($count, PortalNotification::count());
        Livewire::actingAs($this->client)->test(Inbox::class)->assertSee('Проверьте результат работ')->assertDontSee('INTERNAL-NOTICE');
        $notice = PortalNotification::visibleTo($this->client)->where('title', 'Проверьте результат работ')->firstOrFail();
        Livewire::actingAs($this->client)->test(Inbox::class)->call('open', $notice->id)->assertRedirect(route('requests.show', $p->service_request_id));
        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_inbox_cannot_read_another_users_notification_or_revoked_site(): void
    {
        $p = $this->package($this->requestRecord());
        $this->schedule($p, $this->tech);
        app(DeliverPortalOutbox::class)->execute();
        $notice = PortalNotification::visibleTo($this->contact)->firstOrFail();
        Livewire::actingAs($this->client)->test(Inbox::class)->call('markRead', $notice->id);
        $this->assertNull($notice->fresh()->read_at);
        $this->contact->sites()->detach();
        $this->assertFalse(PortalNotification::visibleTo($this->contact)->exists());
        Livewire::actingAs($this->contact)->test(Inbox::class)->call('open', $notice->id)->assertNotFound();
    }

    public function test_dispatcher_booking_form_changes_only_selected_visit(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $other = $this->schedule($p, $this->worker);
        $input = $this->bookingInput($this->tech);
        Livewire::actingAs($this->dispatcher)->test(RequestDetail::class, ['id' => $p->service_request_id])->call('editBooking', $v->id)->assertSet('editingVisit', $v->id)->set('start', $input['start'])->set('end', $input['end'])->set('bookingReason', $input['reason'])->call('changeBooking')->assertHasNoErrors()->assertSet('editingVisit', null);
        $this->assertSame(2, $v->fresh()->version);
        $this->assertSame(1, $other->fresh()->version);
    }

    public function test_livewire_intake_retries_original_intent_after_lost_response(): void
    {
        $page = Livewire::actingAs($this->contact)->test(CreateRequest::class);
        $key = $page->get('operationKeys')['create'];
        $input = ['site_id' => $this->site->id, 'description' => 'Lost response intake', 'place' => 'Kitchen'];
        $created = $this->workflow->create($this->contact, $input, $key);
        $page->set('description', $input['description'])->set('place', $input['place'])->call('send')->assertHasNoErrors()->assertRedirect(route('requests.show', $created['id']));
        $this->assertDatabaseCount('service_requests', 1);
    }

    public function test_livewire_acceptance_retries_original_intent_after_lost_response(): void
    {
        $round = $this->readyRound();
        $r = $round->package->request;
        $page = Livewire::actingAs($this->client)->test(RequestDetail::class, ['id' => $r->id]);
        $key = $page->get('operationKeys')['decision'];
        $this->workflow->decide($this->client, $r->id, [$round->id], 'accepted', '', $key);
        $page->call('decide', 'accepted', $round->id)->assertHasNoErrors();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'result.accepted')->count());
        $this->assertNotSame($key, $page->get('operationKeys')['decision']);
    }

    public function test_photo_retry_has_one_attachment_one_version_change_and_no_extra_file(): void
    {
        Storage::fake('local');
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        $version = $v->fresh()->version;
        $file = UploadedFile::fake()->image('result.jpg', 80, 60);
        $key = $this->key();
        $action = app(SavePhoto::class);
        $first = $action->execute($this->tech, $p->service_request_id, $v->id, $file, $key);
        $again = $action->execute($this->tech, $p->service_request_id, $v->id, $file, $key);
        $this->assertSame($first->id, $again->id);
        $this->assertSame($version + 1, $v->fresh()->version);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('photos'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'photo.attached')->count());
    }

    public function test_partial_photo_failure_preserves_successes_and_refreshes_draft_version(): void
    {
        Storage::fake('local');
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $this->start($v, $this->tech);
        $page = Livewire::actingAs($this->tech)->test(Job::class, ['id' => $v->id])->set('photos', [UploadedFile::fake()->image('good.jpg'), UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf')]);
        $page->call('savePhotos')->assertHasErrors('photos')->assertSet('version', $v->fresh()->version);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertCount(1, $page->get('photos'));
        $page->call('removePhoto', 1)->set('report', 'Preserved after partial upload')->assertHasNoErrors()->assertSet('saved', true);
        $this->assertSame('Preserved after partial upload', $v->fresh()->report);
    }

    public function test_photo_retry_key_cannot_adopt_different_file(): void
    {
        Storage::fake('local');
        $r = $this->requestRecord();
        $key = $this->key();
        $action = app(SavePhoto::class);
        $action->execute($this->client, $r->id, null, UploadedFile::fake()->image('first.jpg', 50, 50), $key);
        try {
            $action->execute($this->client, $r->id, null, UploadedFile::fake()->image('second.jpg', 60, 60), $key);
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('attachments', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('photos'));
    }

    public function test_workspace_filters_by_site_status_and_address_without_expanding_access(): void
    {
        $request = $this->requestRecord();
        $page = Livewire::actingAs($this->contact)->test(Workspace::class)
            ->set('search', 'Sample address')->assertViewHas('requests', fn ($requests) => $requests->total() === 1)
            ->set('site', $this->site->id)->set('status', 'assigned')->assertViewHas('requests', fn ($requests) => $requests->isEmpty())
            ->call('clearFilters')->assertViewHas('requests', fn ($requests) => $requests->total() === 1)
            ->set('section', 'sites')->assertSee('История объекта');
        $hidden = Site::create(['organization_id' => $this->site->organization_id, 'name' => 'Hidden site', 'address' => 'Hidden address']);
        $page->assertDontSee('Hidden site')->set('site', $hidden->id)->assertNotFound();
    }

    public function test_technician_date_filter_uses_site_local_day_and_retains_history(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 21:30:00', 'UTC'));
        $package = $this->package($this->requestRecord());
        $visit = $this->schedule($package, $this->tech);
        $page = Livewire::actingAs($this->tech)->test(Workspace::class)
            ->set('date', '2026-11-13')->assertViewHas('visits', fn ($visits) => $visits->total() === 1)
            ->set('date', '2026-11-12')->assertViewHas('visits', fn ($visits) => $visits->isEmpty())
            ->call('clearFilters')->assertViewHas('visits', fn ($visits) => $visits->total() === 1);
        $this->start($visit, $this->tech);
        $this->workflow->visit($this->tech, $visit->id, $visit->fresh()->version, 'finish', $this->report(), $this->key());
        $page->set('section', 'history')->assertViewHas('visits', fn ($visits) => $visits->total() === 1);
    }

    public function test_next_step_hint_is_role_aware_for_awaiting_acceptance(): void
    {
        $round = $this->readyRound();
        $r = $round->package->request;
        $this->assertSame('awaiting_acceptance', $r->fresh()->status);
        Livewire::actingAs($this->dispatcher)->test(RequestDetail::class, ['id' => $r->id])
            ->assertSee('Ожидает приёмки клиентом')
            ->assertDontSee('Проверьте результат и подтвердите');
        Livewire::actingAs($this->client)->test(RequestDetail::class, ['id' => $r->id])
            ->assertSee('Проверьте результат и подтвердите');
        Livewire::actingAs($this->contact)->test(RequestDetail::class, ['id' => $r->id])
            ->assertSee('Следующий шаг — за ответственным вашей организации');
    }

    public function test_workspace_header_uses_configured_timezone(): void
    {
        config(['portal.timezone' => 'Asia/Tokyo']);
        $this->travelTo(Carbon::parse('2026-11-12 20:00:00', 'UTC'));
        $this->package($this->requestRecord());
        Livewire::actingAs($this->tech)->test(Workspace::class)->assertSee('13 ноября');
    }

    public function test_workspace_invalid_url_filters_fall_back_to_defaults(): void
    {
        $this->package($this->requestRecord());
        Livewire::withQueryParams(['section' => 'invalid', 'status' => 'invalid', 'date' => 'not-a-date'])
            ->actingAs($this->dispatcher)->test(Workspace::class)
            ->assertViewHas('requests', fn ($requests) => $requests->total() === 1);
    }

    public function test_workspace_search_escapes_sql_wildcards(): void
    {
        $this->package($this->requestRecord());
        Livewire::actingAs($this->dispatcher)->test(Workspace::class)
            ->set('search', '_ipe')->assertViewHas('requests', fn ($requests) => $requests->isEmpty())
            ->set('search', '%')->assertViewHas('requests', fn ($requests) => $requests->isEmpty());
    }

    public function test_environment_banner_hides_when_live_payments_enabled(): void
    {
        config(['portal.live_payments' => true]);
        $this->actingAs($this->client);
        session(['account_version' => $this->client->session_version]);
        $this->get(route('home'))->assertOk()->assertDontSee('реальные платежи выключены');
    }

    public function test_delivery_link_is_visible_only_to_finance_and_admins(): void
    {
        $finance = User::factory()->create(['kind' => 'staff', 'roles' => ['finance']]);
        $link = 'href="'.url('/delivery').'"';
        $this->actingAs($finance);
        session(['account_version' => $finance->session_version]);
        $this->get(route('delivery'))->assertOk()->assertSee($link, false);
        $this->actingAs($this->tech);
        session(['account_version' => $this->tech->session_version]);
        $this->get(route('delivery'))->assertOk()->assertDontSee($link, false);
        $this->actingAs($this->client);
        session(['account_version' => $this->client->session_version]);
        $this->get(route('delivery'))->assertOk()->assertDontSee($link, false);
    }

    public function test_workspace_rejects_an_unauthenticated_component_request(): void
    {
        Livewire::test(Workspace::class)->assertStatus(401);
    }

    public function test_workspace_rejects_an_inactive_user(): void
    {
        $this->contact->forceFill(['active' => false])->save();
        Livewire::actingAs($this->contact)->test(Workspace::class)->assertForbidden();
    }

    public function test_workspace_redirects_a_directory_only_administrator(): void
    {
        $admin = User::factory()->create(['kind' => 'staff', 'roles' => ['administrator']]);
        Livewire::actingAs($admin)->test(Workspace::class)->assertRedirect(route('accounts'));
    }

    public function test_combined_roles_switch_explicitly_to_the_dispatch_workspace(): void
    {
        $this->tech->forceFill(['roles' => ['technician', 'dispatcher']])->save();
        $p = $this->package($this->requestRecord());
        $this->schedule($p, $this->tech);
        Livewire::actingAs($this->tech)->test(Workspace::class)
            ->assertViewHas('technician', true)
            ->assertViewHas('visits', fn ($visits) => $visits->total() === 1)
            ->set('workspace', 'dispatch')
            ->assertViewHas('technician', false)
            ->assertViewHas('requests', fn ($requests) => $requests->total() === 1);
    }

    public function test_client_cannot_select_the_dispatch_workspace(): void
    {
        Livewire::actingAs($this->contact)->test(Workspace::class)
            ->set('workspace', 'dispatch')->assertForbidden();
    }

    public function test_interruption_notifies_only_dispatchers_and_retries_deduplicate(): void
    {
        $p = $this->package($this->requestRecord());
        $v = $this->schedule($p, $this->tech);
        $other = $this->schedule($p, $this->worker);
        $this->start($v, $this->tech);
        $this->start($other, $this->worker);
        $key = $this->key();
        $version = $v->fresh()->version;
        $input = [...$this->report(), 'reason' => 'No access'];
        $result = $this->workflow->visit($this->tech, $v->id, $version, 'interrupt', $input, $key);
        $this->assertSame($result, $this->workflow->visit($this->tech, $v->id, $version, 'interrupt', $input, $key));
        $this->assertSame('in_progress', $other->fresh()->status);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'visit.interrupt')->count());
        $outbox = app(DeliverPortalOutbox::class);
        $outbox->execute();
        $title = 'Выезд прерван: требуется помощь диспетчера';
        $this->assertSame([$this->dispatcher->id], PortalNotification::where('title', $title)->pluck('user_id')->all());
        Livewire::actingAs($this->dispatcher)->test(Inbox::class)->assertSee($title);
        DB::table('outbox_events')->update(['status' => 'pending']);
        $outbox->execute();
        $this->assertSame(1, PortalNotification::where('title', $title)->count());
    }

    public function test_temporary_upload_accepts_a_twenty_megabyte_original(): void
    {
        Storage::fake('tmp-for-tests');
        $file = UploadedFile::fake()->image('large.jpg')->size(20480);
        $paths = app(FileUploadController::class)->validateAndStore([$file], 'tmp-for-tests');
        $this->assertCount(1, $paths);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_temporary_upload_rejects_an_original_above_twenty_megabytes(): void
    {
        Storage::fake('tmp-for-tests');
        $file = UploadedFile::fake()->image('too-large.jpg')->size(20481);
        try {
            app(FileUploadController::class)->validateAndStore([$file], 'tmp-for-tests');
            $this->fail('Expected the global upload size limit to reject the file.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('files.0', $exception->errors());
        }
        $this->assertSame([], Storage::disk('tmp-for-tests')->allFiles());
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_task_screens_render_and_reports_leave_active_work(): void
    {
        $a = $this->readyRound();
        $v = Visit::first();
        Livewire::actingAs($this->tech)->test(Workspace::class)->assertDontSee('Отчёт передан');
        Livewire::actingAs($this->tech)->test(Job::class, ['id' => $v->id])->assertSee('Этот отчёт сохранён');
        Livewire::actingAs($this->client)->test(RequestDetail::class, ['id' => $a->package->service_request_id])->assertSee('Принять эти работы')->call('decide', 'accepted', $a->id)->assertHasNoErrors();
    }

    public function test_assemble_summary_trims_at_paragraph_boundary(): void
    {
        $round = $this->readyRound();
        $p = $round->package;
        $v = $p->visits->first();
        $long = str_repeat("Line\n", 5000);
        $v->update(['report' => $long]);
        $page = Livewire::actingAs($this->tech)->test(RequestDetail::class, ['id' => $p->service_request_id]);
        $page->call('assembleSummary', $p->id);
        $summary = $page->get('summaries.'.$p->id);
        $this->assertLessThanOrEqual(20000, mb_strlen($summary));
        $this->assertStringEndsWith("\n", $summary);
    }
}
