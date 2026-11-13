<?php

namespace Tests\Feature;

use App\Actions\Finance\Finance;
use App\Actions\Finance\Fiscalization;
use App\Actions\Finance\ProviderCommands;
use App\Actions\ServiceRequests\Workflow;
use App\Integrations\YooKassa;
use App\Livewire\Billing;
use App\Models\Contract;
use App\Models\ServiceRequest;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ProviderTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT = '00000000-0000-4000-8000-000000000001';

    private const REFUND = '00000000-0000-4000-8000-000000000002';

    private User $client;

    private User $staff;

    private ServiceRequest $requestRecord;

    private WorkPackage $package;

    private int $invoice;

    private string $providerStatus = 'succeeded';

    private array $providerOverrides = [];

    private array $refundOverrides = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['portal.finance.approved' => true, 'portal.finance.legal_header' => 'Test seller', 'portal.finance.tax_label' => 'Test tax configuration', 'portal.yookassa.sandbox_enabled' => true, 'portal.yookassa.shop_id' => 'test-shop', 'portal.yookassa.secret_key' => 'test_fixture_only']);
        $this->staff = User::factory()->create(['kind' => 'staff', 'roles' => ['finance', 'dispatcher']]);
        $tech = User::factory()->create(['kind' => 'staff', 'roles' => ['technician']]);
        $org = DB::table('organizations')->insertGetId(['name' => 'Test buyer']);
        $site = Site::create(['organization_id' => $org, 'name' => 'Test site', 'address' => 'Test address']);
        $this->client = User::factory()->create(['organization_id' => $org, 'accept_work' => true, 'totp_confirmed_at' => now()]);
        $this->requestRecord = ServiceRequest::create(['site_id' => $site->id, 'organization_id' => $org, 'author_id' => $this->client->id, 'number' => 'PAY-TEST', 'title' => 'Repair', 'description' => 'Test']);
        $contract = Contract::create(['organization_id' => $org, 'number' => 'PAY-CONTRACT', 'title' => 'Coverage', 'starts_on' => now()->subYear(), 'ends_on' => now()->addYear(), 'covered_categories' => ['plumbing'], 'terms' => []]);
        $this->package = WorkPackage::create(['service_request_id' => $this->requestRecord->id, 'ordinal' => 1, 'contract_id' => $contract->id, 'contract_snapshot' => [], 'title' => 'Repair', 'scope' => 'Repair', 'category' => 'plumbing', 'kind' => 'paid', 'lead_id' => $tech->id, 'checklist_template' => [], 'photo_required' => false]);
        $f = app(Finance::class);
        $estimate = $f->issue($this->staff, $this->requestRecord->id, 1, 'independent_packages', [['package_id' => $this->package->id, 'kind' => 'labor', 'description' => 'Labor', 'quantity' => '100', 'rate' => '10']], 'Verified scope', $this->key())['id'];
        $this->invoice = $f->decideEstimate($this->client, $estimate, 'approved', '', $this->key())['invoice_id'];
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function checkout(): int
    {
        return app(ProviderCommands::class)->checkout($this->client, $this->invoice, $this->key())['id'];
    }

    private function payment(array $overrides = []): array
    {
        $attempt = DB::table('provider_attempts')->where('kind', 'payment')->firstOrFail();

        return array_replace_recursive(['id' => self::PAYMENT, 'test' => true, 'recipient' => ['account_id' => 'test-shop'], 'amount' => ['value' => '500.00', 'currency' => 'RUB'], 'status' => $this->providerStatus, 'paid' => $this->providerStatus === 'succeeded', 'metadata' => ['portal_attempt' => $attempt->command_key], 'confirmation' => ['confirmation_url' => 'https://yoomoney.ru/checkout/payments/test']], $overrides);
    }

    private function fakeProvider(array $overrides = []): void
    {
        $this->providerOverrides = $overrides;
        $baseline = DB::transactionLevel();
        Http::fake(function ($request) use ($baseline) {
            $this->assertSame($baseline, DB::transactionLevel(), 'Provider I/O must not hold business transaction locks');
            if (str_contains($request->url(), '/refunds')) {
                $attempt = DB::table('provider_attempts')->where('kind', 'refund')->firstOrFail();

                return Http::response(array_replace_recursive(['id' => self::REFUND, 'payment_id' => self::PAYMENT, 'amount' => ['value' => '500.00', 'currency' => 'RUB'], 'description' => 'portal-refund:'.$attempt->command_key, 'status' => 'succeeded'], $this->refundOverrides));
            }

            return Http::response($this->payment($this->providerOverrides));
        });
    }

    public function test_checkout_is_durable_server_priced_and_retry_has_no_network(): void
    {
        Http::fake();
        $key = $this->key();
        $commands = app(ProviderCommands::class);
        $first = $commands->checkout($this->client, $this->invoice, $key);
        $this->assertSame($first, $commands->checkout($this->client, $this->invoice, $key));
        $this->assertDatabaseCount('provider_attempts', 1);
        $attempt = DB::table('provider_attempts')->first();
        $this->assertSame(50000, $attempt->amount_kopecks);
        $this->assertSame('500.00', json_decode($attempt->payload, true)['amount']['value']);
        Http::assertNothingSent();
        $this->expectException(ValidationException::class);
        $this->checkout();
    }

    public function test_only_authoritative_success_posts_once_and_funds_execution(): void
    {
        $this->checkout();
        $this->fakeProvider();
        $commands = app(ProviderCommands::class);
        $commands->execute();
        $commands->receive('payment.succeeded', self::PAYMENT);
        $commands->receive('payment.succeeded', self::PAYMENT);
        $commands->verifyWebhooks();
        $this->assertDatabaseCount('provider_webhooks', 1);
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertDatabaseCount('fiscal_receipt_records', 1);
        $this->assertDatabaseHas('fiscal_receipt_records', ['basis_type' => 'payment', 'scenario' => 'advance', 'amount_kopecks' => 50000]);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertTrue($this->package->fresh()->allowsExecution());
        $this->assertDatabaseHas('provider_attempts', ['status' => 'succeeded']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && filled($request->header('Idempotence-Key')));
    }

    public function test_pending_and_browser_return_do_not_confirm_money(): void
    {
        $this->checkout();
        $this->providerStatus = 'pending';
        $this->fakeProvider();
        app(ProviderCommands::class)->execute();
        $this->assertDatabaseCount('payment_receipts', 0);
        $this->assertFalse($this->package->fresh()->allowsExecution());
        $this->assertDatabaseHas('provider_attempts', ['status' => 'pending']);
        $this->actingAs($this->client)->withSession(['account_version' => $this->client->session_version, 'mfa_passed' => true])->get(route('requests.billing', $this->requestRecord->id).'?status=succeeded')->assertOk();
        $this->assertDatabaseCount('payment_receipts', 0);
    }

    public function test_forged_callback_status_is_ignored_and_verified_by_api(): void
    {
        $this->checkout();
        $this->providerStatus = 'pending';
        $this->fakeProvider();
        $payload = ['event' => 'payment.succeeded', 'object' => [...$this->payment(), 'status' => 'succeeded', 'paid' => true]];
        $this->postJson(route('yookassa.webhook'), $payload)->assertStatus(202);
        Http::assertNothingSent();
        app(ProviderCommands::class)->verifyWebhooks();
        $this->assertDatabaseCount('payment_receipts', 0);
        $this->assertDatabaseHas('provider_webhooks', ['status' => 'verified']);
    }

    public function test_wrong_amount_is_quarantined(): void
    {
        $this->checkout();
        $this->fakeProvider(['amount' => ['value' => '499.99']]);
        app(ProviderCommands::class)->execute();
        $this->assertDatabaseCount('payment_receipts', 0);
        $this->assertDatabaseHas('provider_attempts', ['status' => 'quarantined', 'failure_code' => 'verification_failed']);
    }

    public function test_wrong_merchant_and_live_objects_are_not_posted(): void
    {
        $this->checkout();
        $this->fakeProvider(['test' => false, 'recipient' => ['account_id' => 'other-shop']]);
        app(ProviderCommands::class)->execute();
        $this->assertDatabaseCount('payment_receipts', 0);
        $this->assertDatabaseHas('provider_attempts', ['status' => 'quarantined']);
    }

    public function test_unknown_callback_correlation_is_quarantined(): void
    {
        $this->checkout();
        $this->fakeProvider(['metadata' => ['portal_attempt' => $this->key()]]);
        app(ProviderCommands::class)->receive('payment.succeeded', self::PAYMENT);
        app(ProviderCommands::class)->verifyWebhooks();
        $this->assertDatabaseCount('payment_receipts', 0);
        $this->assertDatabaseHas('provider_webhooks', ['status' => 'quarantined']);
    }

    public function test_expired_unknown_creation_is_reviewed_without_new_post(): void
    {
        $id = $this->checkout();
        Http::fake();
        DB::table('provider_attempts')->where('id', $id)->update(['status' => 'unknown', 'first_sent_at' => now()->subHours(25)]);
        app(ProviderCommands::class)->execute();
        Http::assertNothingSent();
        $this->assertDatabaseHas('provider_attempts', ['status' => 'review']);
    }

    public function test_known_id_reconciles_after_window_without_recreating(): void
    {
        $id = $this->checkout();
        $this->fakeProvider();
        DB::table('provider_attempts')->where('id', $id)->update(['status' => 'unknown', 'provider_id' => self::PAYMENT, 'first_sent_at' => now()->subHours(25)]);
        app(ProviderCommands::class)->execute();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        $this->assertDatabaseCount('payment_receipts', 1);
    }

    public function test_late_capture_after_cancellation_is_credit_not_service_resume(): void
    {
        $id = $this->checkout();
        $this->providerStatus = 'pending';
        $this->fakeProvider();
        app(ProviderCommands::class)->execute();
        app(Workflow::class)->cancel($this->staff, $this->requestRecord->id, $this->requestRecord->fresh()->version, 'Cancelled scope', $this->key());
        $this->providerStatus = 'succeeded';
        DB::table('provider_attempts')->where('id', $id)->update(['available_at' => now()]);
        app(ProviderCommands::class)->execute();
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertSame('cancelled', $this->requestRecord->fresh()->status);
        $this->assertSame(50000, app(Finance::class)->balance($this->requestRecord->id)['credit']);
    }

    public function test_refund_uses_original_payment_and_authoritative_success_once(): void
    {
        $this->checkout();
        $this->fakeProvider();
        $commands = app(ProviderCommands::class);
        $commands->execute();
        app(Workflow::class)->cancel($this->staff, $this->requestRecord->id, $this->requestRecord->fresh()->version, 'Cancelled scope', $this->key());
        $allocation = DB::table('payment_allocations')->first();
        app(Finance::class)->reverseAllocation($this->staff, $allocation->id, '500', 'Unused scope', $this->key());
        $refund = app(Finance::class)->approveRefund($this->staff, $allocation->payment_receipt_id, '500', 'Cancelled scope', $this->key())['id'];
        $key = $this->key();
        $first = $commands->refund($this->staff, $refund, $key);
        $this->assertSame($first, $commands->refund($this->staff, $refund, $key));
        $this->assertFalse(DB::table('fiscal_receipt_records')->where('basis_type', 'refund')->exists());
        $this->refundOverrides = ['receipt_registration' => 'pending'];
        $commands->execute();
        $record = app(Fiscalization::class)->records($this->requestRecord->id)->firstWhere('basis_type', 'refund');
        $this->assertSame('provider_pending', $record->provider_status);
        $this->refundOverrides = ['receipt_registration' => 'succeeded'];
        $commands->receive('refund.succeeded', self::REFUND);
        $commands->verifyWebhooks();
        $this->assertDatabaseCount('refund_confirmations', 1);
        $this->assertDatabaseHas('refunds', ['id' => $refund, 'status' => 'succeeded']);
        $this->assertDatabaseHas('fiscal_receipt_records', ['basis_type' => 'refund', 'basis_id' => $refund, 'scenario' => 'refund', 'amount_kopecks' => -50000]);
        $this->assertSame(1, DB::table('fiscal_receipt_records')->where('basis_type', 'refund')->count());
        $this->assertSame('provider_registered', app(Fiscalization::class)->records($this->requestRecord->id)->firstWhere('basis_type', 'refund')->provider_status);
        $this->assertSame(0, app(Finance::class)->balance($this->requestRecord->id)['credit']);
    }

    public function test_billing_checkout_queues_and_displays_safe_pending_state(): void
    {
        $this->fakeProvider();
        Livewire::actingAs($this->client)->test(Billing::class, ['id' => $this->requestRecord->id])
            ->assertSee('Подготовить тестовую оплату')
            ->call('checkout', $this->invoice)
            ->assertHasNoErrors()
            ->assertSee('Не создавайте ещё один платёж');
        $this->assertDatabaseCount('provider_attempts', 1);
        Http::assertNothingSent();
        $this->providerStatus = 'pending';
        app(ProviderCommands::class)->execute();
        Livewire::actingAs($this->client)->test(Billing::class, ['id' => $this->requestRecord->id])
            ->assertSee('https://yoomoney.ru/checkout/payments/test', false)
            ->assertDontSee('test_fixture_only')->assertDontSee('portal_attempt');
    }

    public function test_finance_can_queue_reasoned_reconciliation_but_not_client_checkout(): void
    {
        $id = $this->checkout();
        Http::fake();
        Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])
            ->set('reason', 'Verify provider outcome')->call('reconcilePayment', $id)->assertHasNoErrors();
        $audit = DB::table('audit_events')->where('action', 'provider.reconciliation_requested')->firstOrFail();
        $this->assertSame('Verify provider outcome', json_decode($audit->data, true)['reason']);
        Livewire::actingAs($this->client)->test(Billing::class, ['id' => $this->requestRecord->id])
            ->set('reason', 'Not finance')->call('reconcilePayment', $id)->assertForbidden();
        Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])
            ->call('checkout', $this->invoice)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_checkout_cannot_target_another_visible_page_invoice(): void
    {
        $other = ServiceRequest::create(['site_id' => $this->requestRecord->site_id, 'organization_id' => $this->requestRecord->organization_id, 'author_id' => $this->client->id, 'number' => 'PAY-OTHER', 'title' => 'Other request', 'description' => 'Test']);
        Livewire::actingAs($this->client)->test(Billing::class, ['id' => $other->id])
            ->call('checkout', $this->invoice)->assertNotFound();
        $this->assertDatabaseCount('provider_attempts', 0);
    }

    public function test_refund_rejects_original_payment_that_is_no_longer_paid(): void
    {
        $this->checkout();
        $this->fakeProvider();
        $commands = app(ProviderCommands::class);
        $commands->execute();
        app(Workflow::class)->cancel($this->staff, $this->requestRecord->id, $this->requestRecord->fresh()->version, 'Cancelled scope', $this->key());
        $allocation = DB::table('payment_allocations')->firstOrFail();
        app(Finance::class)->reverseAllocation($this->staff, $allocation->id, '500', 'Unused scope', $this->key());
        $refund = app(Finance::class)->approveRefund($this->staff, $allocation->payment_receipt_id, '500', 'Cancelled scope', $this->key())['id'];
        Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])->call('dispatchRefund', $refund)->assertHasNoErrors();
        $this->providerStatus = 'pending';
        $commands->execute();
        $this->assertDatabaseCount('refund_confirmations', 0);
        $this->assertDatabaseHas('provider_attempts', ['kind' => 'refund', 'status' => 'quarantined']);
        $this->assertFalse(app(Finance::class)->settled($this->requestRecord->id));
    }

    public function test_checkout_prices_credit_adjusted_invoice(): void
    {
        $finance = app(Finance::class);
        $revision = $finance->issue($this->staff, $this->requestRecord->id, $this->requestRecord->fresh()->version, 'independent_packages', [['package_id' => $this->package->id, 'kind' => 'labor', 'description' => 'Labor', 'quantity' => '80', 'rate' => '10']], 'Reduced scope', $this->key())['id'];
        $finance->decideEstimate($this->client, $revision, 'approved', '', $this->key());
        Http::fake();
        $id = $this->checkout();
        $this->assertDatabaseHas('provider_attempts', ['id' => $id, 'amount_kopecks' => 40000]);
        $this->assertSame('400.00', json_decode(DB::table('provider_attempts')->find($id)->payload, true)['amount']['value']);
        Http::assertNothingSent();
    }

    public function test_capture_after_package_cancellation_does_not_allocate_to_it(): void
    {
        $this->checkout();
        $this->package->update(['status' => 'cancelled']);
        $this->fakeProvider();
        app(ProviderCommands::class)->execute();
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertSame(50000, app(Finance::class)->balance($this->requestRecord->id)['credit']);
        $this->assertSame('cancelled', $this->package->fresh()->status);
    }

    public function test_unresolved_payment_blocks_otherwise_empty_settlement(): void
    {
        $finance = app(Finance::class);
        $this->assertTrue($finance->settled($this->requestRecord->id));
        $id = $this->checkout();
        foreach (['queued', 'pending', 'unknown', 'review', 'quarantined'] as $status) {
            DB::table('provider_attempts')->where('id', $id)->update(['status' => $status]);
            $this->assertFalse($finance->settled($this->requestRecord->id));
        }
        DB::table('provider_attempts')->where('id', $id)->update(['status' => 'cancelled']);
        $this->assertTrue($finance->settled($this->requestRecord->id));
    }

    private function reviewedPayment(): int
    {
        $id = $this->checkout();
        DB::table('provider_attempts')->where('id', $id)->update(['status' => 'review', 'first_sent_at' => now()->subHours(25)]);

        return $id;
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid provider action accepted');
        } catch (ValidationException) {
        }
    }

    public function test_operator_candidate_is_durable_but_only_verified_get_posts_money(): void
    {
        $id = $this->reviewedPayment();
        $this->fakeProvider();
        $commands = app(ProviderCommands::class);
        $before = DB::table('provider_attempts')->find($id);
        $key = $this->key();
        $result = $commands->requestBinding($this->staff, $id, self::PAYMENT, 'Found in merchant test account', $key);
        $this->assertSame($result, $commands->requestBinding($this->staff, $id, self::PAYMENT, 'Found in merchant test account', $key));
        $this->assertDatabaseHas('provider_attempts', ['id' => $id, 'provider_id' => null, 'candidate_provider_id' => self::PAYMENT, 'status' => 'unknown']);
        $this->assertDatabaseCount('payment_receipts', 0);
        Http::assertNothingSent();
        $commands->execute();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        $this->assertDatabaseHas('provider_attempts', ['id' => $id, 'provider_id' => self::PAYMENT, 'candidate_provider_id' => null, 'status' => 'succeeded', 'send_count' => 0, 'command_key' => $before->command_key, 'first_sent_at' => $before->first_sent_at]);
        $this->assertDatabaseHas('provider_attempt_events', ['provider_attempt_id' => $id, 'source' => 'operator_binding_verified']);
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'provider.binding_requested')->count());
        $commands->receive('payment.succeeded', self::PAYMENT);
        $commands->verifyWebhooks();
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_wrong_candidate_correlation_is_quarantined_without_binding(): void
    {
        $id = $this->reviewedPayment();
        $this->fakeProvider(['metadata' => ['portal_attempt' => $this->key()]]);
        $commands = app(ProviderCommands::class);
        $commands->requestBinding($this->staff, $id, self::PAYMENT, 'Investigate merchant record', $this->key());
        $commands->execute();
        $this->assertDatabaseHas('provider_attempts', ['id' => $id, 'status' => 'quarantined', 'provider_id' => null, 'candidate_provider_id' => self::PAYMENT, 'failure_code' => 'verification_failed']);
        $this->assertDatabaseCount('payment_receipts', 0);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertFalse(app(Finance::class)->settled($this->requestRecord->id));
    }

    public function test_candidate_transport_retry_keeps_get_only_after_expired_window(): void
    {
        $id = $this->reviewedPayment();
        $baseline = DB::transactionLevel();
        Http::fake(['https://api.yookassa.ru/v3/*' => Http::sequence()->push([], 503)->push($this->payment())]);
        $commands = app(ProviderCommands::class);
        $commands->requestBinding($this->staff, $id, self::PAYMENT, 'Merchant record found', $this->key());
        $commands->execute();
        $this->assertSame($baseline, DB::transactionLevel());
        $this->assertDatabaseHas('provider_attempts', ['id' => $id, 'status' => 'unknown', 'provider_id' => null, 'candidate_provider_id' => self::PAYMENT]);
        $commands->reconcile($this->staff, $id, 'Retry verification after outage', $this->key());
        $commands->execute();
        $this->assertDatabaseHas('provider_attempts', ['id' => $id, 'status' => 'succeeded', 'send_count' => 0]);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertDatabaseCount('payment_receipts', 1);
    }

    public function test_verified_cancelled_candidate_clears_uncertainty_without_money(): void
    {
        $id = $this->reviewedPayment();
        $this->fakeProvider(['status' => 'canceled', 'paid' => false]);
        $commands = app(ProviderCommands::class);
        $commands->requestBinding($this->staff, $id, self::PAYMENT, 'Found cancellation', $this->key());
        $commands->execute();
        $this->assertDatabaseHas('provider_attempts', ['id' => $id, 'status' => 'cancelled', 'provider_id' => self::PAYMENT, 'candidate_provider_id' => null]);
        $this->assertDatabaseCount('payment_receipts', 0);
        $this->assertTrue(app(Finance::class)->settled($this->requestRecord->id));
    }

    public function test_binding_form_is_finance_only_scoped_and_does_not_send_network(): void
    {
        $id = $this->reviewedPayment();
        Http::fake();
        Livewire::actingAs($this->client)->test(Billing::class, ['id' => $this->requestRecord->id])->assertDontSee('Проверить найденную операцию')->set('providerCandidates.'.$id, self::PAYMENT)->set('reason', 'Not finance')->call('requestProviderBinding', $id)->assertForbidden();
        $other = ServiceRequest::create(['site_id' => $this->requestRecord->site_id, 'organization_id' => $this->requestRecord->organization_id, 'author_id' => $this->client->id, 'number' => 'BIND-OTHER', 'title' => 'Other request', 'description' => 'Test']);
        Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $other->id])->set('providerCandidates.'.$id, self::PAYMENT)->set('reason', 'Wrong page')->call('requestProviderBinding', $id)->assertNotFound();
        $page = Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])->assertSee('Проверить найденную операцию')->set('providerCandidates.'.$id, self::PAYMENT)->set('reason', 'Verified merchant record')->call('requestProviderBinding', $id)->assertHasNoErrors()->assertSee('· проверяем '.self::PAYMENT);
        $this->assertDatabaseCount('payment_receipts', 0);
        $page->set('providerCandidates.'.$id, self::PAYMENT);
        $this->staff->forceFill(['roles' => []])->save();
        $page->call('requestProviderBinding', $id)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_binding_rejects_unsent_locked_known_terminal_and_invalid_inputs(): void
    {
        $id = $this->checkout();
        $commands = app(ProviderCommands::class);
        Http::fake();
        $this->rejects(fn () => $commands->requestBinding($this->staff, $id, self::PAYMENT, 'Unsent', $this->key()));
        foreach ([['status' => 'review', 'locked_at' => now()], ['status' => 'review', 'locked_at' => null, 'provider_id' => self::PAYMENT], ['status' => 'succeeded', 'provider_id' => null], ['status' => 'cancelled']] as $changes) {
            DB::table('provider_attempts')->where('id', $id)->update(['first_sent_at' => now()->subHours(25), ...$changes]);
            $this->rejects(fn () => $commands->requestBinding($this->staff, $id, self::PAYMENT, 'Invalid state', $this->key()));
        }
        DB::table('provider_attempts')->where('id', $id)->update(['status' => 'review']);
        $this->rejects(fn () => $commands->requestBinding($this->staff, $id, 'not-an-id', 'Invalid ID', $this->key()));
        $this->rejects(fn () => $commands->requestBinding($this->staff, $id, self::PAYMENT, '   ', $this->key()));
        $this->assertDatabaseHas('provider_attempts', ['id' => $id, 'candidate_provider_id' => null]);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'provider.binding_requested')->count());
        Http::assertNothingSent();
    }

    public function test_refund_candidate_verifies_original_payment_without_recreating_refund(): void
    {
        $this->checkout();
        $this->fakeProvider();
        $commands = app(ProviderCommands::class);
        $commands->execute();
        app(Workflow::class)->cancel($this->staff, $this->requestRecord->id, $this->requestRecord->fresh()->version, 'Cancelled scope', $this->key());
        $allocation = DB::table('payment_allocations')->firstOrFail();
        $finance = app(Finance::class);
        $finance->reverseAllocation($this->staff, $allocation->id, '500', 'Unused advance', $this->key());
        $refund = $finance->approveRefund($this->staff, $allocation->payment_receipt_id, '500', 'Cancelled scope', $this->key())['id'];
        $id = $commands->refund($this->staff, $refund, $this->key())['id'];
        DB::table('provider_attempts')->where('id', $id)->update(['status' => 'review', 'first_sent_at' => now()->subHours(25)]);
        $commands->requestBinding($this->staff, $id, self::REFUND, 'Found original-payment refund', $this->key());
        $commands->execute();
        $this->assertDatabaseHas('provider_attempts', ['id' => $id, 'status' => 'succeeded', 'provider_id' => self::REFUND, 'candidate_provider_id' => null, 'send_count' => 0]);
        $this->assertDatabaseHas('refunds', ['id' => $refund, 'status' => 'succeeded']);
        $this->assertDatabaseCount('refund_confirmations', 1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/refunds'));
    }

    public function test_verified_duplicate_success_updates_registration_without_reposting_money(): void
    {
        $this->checkout();
        $this->fakeProvider(['receipt_registration' => 'pending']);
        $commands = app(ProviderCommands::class);
        $commands->execute();
        $fiscal = app(Fiscalization::class);
        $record = $fiscal->records($this->requestRecord->id)->first();
        $this->assertSame('provider_pending', $record->provider_status);
        $this->assertSame('awaiting_configuration', $record->configuration_status);
        $this->fakeProvider(['receipt_registration' => 'succeeded']);
        $commands->receive('payment.succeeded', self::PAYMENT);
        $commands->verifyWebhooks();
        $this->assertSame('provider_registered', $fiscal->records($this->requestRecord->id)->first()->provider_status);
        $this->assertDatabaseCount('fiscal_receipt_states', 3);
        DB::table('provider_webhooks')->update(['status' => 'pending']);
        $commands->verifyWebhooks();
        $this->fakeProvider(['receipt_registration' => 'canceled']);
        DB::table('provider_webhooks')->update(['status' => 'pending']);
        $commands->verifyWebhooks();
        $this->assertDatabaseCount('fiscal_receipt_states', 3);
        $this->assertDatabaseCount('fiscal_receipt_records', 1);
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])->assertSee('сообщил об успешной регистрации')->assertSee('Реквизиты фискального документа не получены');
        $evidence = json_decode(DB::table('fiscal_receipt_states')->latest('id')->value('evidence'), true);
        $this->assertSame('verified_provider_api', $evidence['source']);
        $this->assertTrue($evidence['test']);
        $this->assertArrayNotHasKey('customer', $evidence);
        config(['portal.finance.fiscal' => ['approved' => true, 'approved_by' => 'Test accountant', 'approved_on' => now()->format('Y-m-d'), 'arrangement' => 'Test arrangement', 'tax_system' => 'Test system', 'prices_include_tax' => true, 'scenarios' => ['advance' => ['required' => false]]]]);
        $fiscal->review($this->staff, $record->id, 'Reviewed test arrangement', $this->key());
        $reviewed = $fiscal->records($this->requestRecord->id)->first();
        $this->assertSame('not_required', $reviewed->configuration_status);
        $this->assertSame('provider_registered', $reviewed->provider_status);
    }

    public function test_registration_unknown_failure_and_pending_are_separate_from_payment_success(): void
    {
        $this->checkout();
        $this->fakeProvider();
        $commands = app(ProviderCommands::class);
        $commands->execute();
        $this->assertDatabaseHas('fiscal_receipt_states', ['status' => 'provider_unknown']);
        $commands->receive('payment.succeeded', self::PAYMENT);
        foreach (['canceled' => 'provider_failed', 'pending' => 'provider_pending', 'unrecognized' => 'provider_unknown'] as $registration => $status) {
            $this->fakeProvider(['receipt_registration' => $registration]);
            DB::table('provider_webhooks')->update(['status' => 'pending']);
            $commands->verifyWebhooks();
            $this->assertSame($status, app(Fiscalization::class)->records($this->requestRecord->id)->first()->provider_status);
            $this->assertDatabaseHas('provider_attempts', ['kind' => 'payment', 'status' => 'succeeded']);
        }
        $this->assertDatabaseCount('payment_receipts', 1);
    }

    public function test_unverified_registration_success_cannot_create_fiscal_or_money_evidence(): void
    {
        $this->checkout();
        $this->fakeProvider(['amount' => ['value' => '499.99'], 'receipt_registration' => 'succeeded']);
        app(ProviderCommands::class)->execute();
        $this->assertDatabaseCount('fiscal_receipt_records', 0);
        $this->assertDatabaseCount('fiscal_receipt_states', 0);
        $this->assertDatabaseCount('payment_receipts', 0);
    }

    public function test_final_payment_fiscal_scenario_uses_the_verified_actual_invoice(): void
    {
        $this->checkout();
        $this->fakeProvider();
        $commands = app(ProviderCommands::class);
        $commands->execute();
        $workflow = app(Workflow::class);
        $this->package->update(['checklist_template' => [['text' => 'Safety', 'required' => true]]]);
        $tech = User::findOrFail($this->package->lead_id);
        $visit = $workflow->schedule($this->staff, $this->package->id, $this->package->fresh()->version, ['technician_id' => $tech->id, 'start' => now()->addDay()->timezone($this->requestRecord->site->timezone)->format('Y-m-d\TH:i'), 'end' => now()->addDay()->addHour()->timezone($this->requestRecord->site->timezone)->format('Y-m-d\TH:i')], $this->key())['id'];
        $workflow->visit($tech, $visit, 1, 'start', [], $this->key());
        $workflow->visit($tech, $visit, 2, 'finish_submit', ['report' => 'Completed', 'checks' => [true], 'labor_minutes' => 100, 'usage_confirmed' => true, 'usage' => []], $this->key());
        $workflow->decide($this->client, $this->requestRecord->id, [$this->package->rounds()->latest('id')->value('id')], 'accepted', '', $this->key());
        $actual = DB::table('finance_documents')->where('kind', 'actual')->firstOrFail();
        $id = $commands->checkout($this->client, $actual->id, $this->key())['id'];
        $attempt = DB::table('provider_attempts')->find($id);
        $this->fakeProvider(['id' => self::REFUND, 'metadata' => ['portal_attempt' => $attempt->command_key], 'receipt_registration' => 'pending']);
        $commands->execute();
        $this->assertDatabaseHas('fiscal_receipt_records', ['basis_type' => 'payment', 'basis_id' => DB::table('provider_attempts')->find($id)->payment_receipt_id, 'scenario' => 'final_payment', 'amount_kopecks' => 50000]);
        $this->assertDatabaseCount('payment_receipts', 2);
    }

    public function test_disabled_or_live_credentials_cannot_create_payment(): void
    {
        Http::fake();
        config(['portal.yookassa.secret_key' => 'live_fixture_only']);
        $this->assertFalse(app(YooKassa::class)->enabled());
        try {
            $this->checkout();
            $this->fail('Live credentials accepted');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('provider_attempts', 0);
        Http::assertNothingSent();
    }
}
