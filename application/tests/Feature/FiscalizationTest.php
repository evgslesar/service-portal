<?php

namespace Tests\Feature;

use App\Actions\Finance\Finance;
use App\Actions\Finance\Fiscalization;
use App\Actions\Notifications\DeliverPortalOutbox;
use App\Actions\Notifications\EmailDelivery;
use App\Livewire\Billing;
use App\Models\PortalNotification;
use App\Models\ServiceRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FiscalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $client;

    private ServiceRequest $requestRecord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->staff = User::factory()->create(['kind' => 'staff', 'roles' => ['finance']]);
        $organization = DB::table('organizations')->insertGetId(['name' => 'Fiscal test buyer']);
        $site = Site::create(['organization_id' => $organization, 'name' => 'Test site', 'address' => 'Test address']);
        $this->client = User::factory()->create(['organization_id' => $organization, 'accept_work' => true]);
        $this->requestRecord = ServiceRequest::create(['site_id' => $site->id, 'organization_id' => $organization, 'author_id' => $this->client->id, 'number' => 'FISCAL-TEST', 'title' => 'Repair', 'description' => 'Test']);
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function configuration(bool $required = true): void
    {
        config(['portal.finance.fiscal' => [
            'approved' => true, 'approved_by' => 'Test accountant', 'approved_on' => now()->format('Y-m-d'),
            'arrangement' => 'Test arrangement only', 'tax_system' => 'Test tax system', 'prices_include_tax' => true,
            'scenarios' => ['bank_transfer' => $required ? ['required' => true, 'vat_code' => 'test-vat', 'payment_mode' => 'test-mode', 'labor_subject' => 'test-service', 'material_subject' => 'test-goods'] : ['required' => false]],
        ]]);
    }

    private function bank(): int
    {
        $receipt = app(Finance::class)->recordBank($this->staff, $this->requestRecord->id, ['reference' => $this->key(), 'account' => 'Test account', 'paid_on' => now()->format('Y-m-d'), 'payer' => 'Fiscal test buyer', 'evidence' => 'Verified test statement', 'amount' => '123.45'], $this->key())['id'];

        return DB::table('fiscal_receipt_records')->where('basis_type', 'payment')->where('basis_id', $receipt)->value('id');
    }

    private function databaseRejects(callable $action, string $message): void
    {
        try {
            DB::transaction($action);
            $this->fail('Invalid fiscal database mutation accepted');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_unconfigured_fiscal_review_does_not_block_confirmed_bank_money(): void
    {
        $id = $this->bank();
        $record = DB::table('fiscal_receipt_records')->find($id);
        $this->assertSame(12345, $record->amount_kopecks);
        $this->assertStringStartsWith('FIS-', $record->number);
        $this->assertSame('bank_transfer', $record->scenario);
        $this->assertDatabaseHas('fiscal_receipt_states', ['fiscal_receipt_record_id' => $id, 'status' => 'awaiting_configuration', 'mapping' => null]);
        $this->assertSame(12345, app(Finance::class)->balance($this->requestRecord->id)['received']);
        Http::assertNothingSent();
        $this->assertFalse(config('portal.live_payments'));
    }

    public function test_required_mapping_needs_every_explicit_accountant_field(): void
    {
        $this->configuration();
        $fiscal = app(Fiscalization::class);
        $mapping = $fiscal->mapping('bank_transfer');
        $this->assertSame('test-vat', $mapping['vat_code']);
        $this->assertTrue($mapping['prices_include_tax']);
        $baseline = config('portal.finance.fiscal');
        foreach (['approved', 'approved_by', 'approved_on', 'arrangement', 'tax_system', 'prices_include_tax', 'scenarios.bank_transfer.required', 'scenarios.bank_transfer.vat_code', 'scenarios.bank_transfer.payment_mode', 'scenarios.bank_transfer.labor_subject', 'scenarios.bank_transfer.material_subject'] as $field) {
            config(['portal.finance.fiscal' => $baseline, 'portal.finance.fiscal.'.$field => null]);
            try {
                $fiscal->mapping('bank_transfer');
                $this->fail('Missing explicit fiscal configuration accepted: '.$field);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        config(['portal.finance.fiscal' => $baseline, 'portal.finance.fiscal.scenarios.bank_transfer.required' => 'false']);
        $this->expectException(ValidationException::class);
        $fiscal->mapping('bank_transfer');
    }

    public function test_mapping_does_not_invent_unconfigured_scenarios_or_provider_fields(): void
    {
        $this->configuration();
        config(['portal.finance.fiscal.scenarios.bank_transfer.private_provider_key' => 'fixture-not-forwarded']);
        $this->assertArrayNotHasKey('private_provider_key', app(Fiscalization::class)->mapping('bank_transfer'));
        foreach (['advance', 'final_payment', 'final_settlement', 'advance_offset', 'refund', 'correction', 'unsupported'] as $scenario) {
            try {
                app(Fiscalization::class)->mapping($scenario);
                $this->fail('Unconfigured scenario accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_explicit_not_required_is_distinct_from_missing_configuration(): void
    {
        $this->configuration(false);
        $id = $this->bank();
        $state = DB::table('fiscal_receipt_states')->where('fiscal_receipt_record_id', $id)->first();
        $this->assertSame('not_required', $state->status);
        $mapping = json_decode($state->mapping, true);
        $this->assertFalse($mapping['required']);
        $this->assertArrayNotHasKey('vat_code', $mapping);
        Http::assertNothingSent();
    }

    public function test_review_appends_mapping_preserves_snapshot_and_replays_without_network(): void
    {
        $id = $this->bank();
        $before = DB::table('fiscal_receipt_records')->find($id);
        $this->configuration();
        $fiscal = app(Fiscalization::class);
        $key = $this->key();
        $result = $fiscal->review($this->staff, $id, 'Verified accountant decision', $key);
        $this->assertSame($result, $fiscal->review($this->staff, $id, 'Verified accountant decision', $key));
        $this->assertEquals($before, DB::table('fiscal_receipt_records')->find($id));
        $this->assertDatabaseCount('fiscal_receipt_states', 2);
        $this->assertSame('awaiting_transport', $fiscal->records($this->requestRecord->id)->first()->configuration_status);
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertDatabaseCount('action_executions', 2);
        Http::assertNothingSent();
        $this->expectException(ValidationException::class);
        $fiscal->review($this->staff, $id, 'Changed decision', $key);
    }

    public function test_review_requires_current_finance_permission_even_for_replay(): void
    {
        $id = $this->bank();
        $this->configuration();
        $key = $this->key();
        app(Fiscalization::class)->review($this->staff, $id, 'Verified decision', $key);
        DB::table('users')->where('id', $this->staff->id)->update(['roles' => json_encode(['dispatcher'])]);
        $this->expectException(HttpException::class);
        app(Fiscalization::class)->review($this->staff, $id, 'Verified decision', $key);
    }

    public function test_invalid_review_rolls_back_operation_and_evidence(): void
    {
        $id = $this->bank();
        foreach (['', 'Reason but unapproved mapping'] as $reason) {
            try {
                app(Fiscalization::class)->review($this->staff, $id, $reason, $this->key());
                $this->fail('Invalid review accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('fiscal_receipt_states', 1);
                $this->assertDatabaseCount('action_executions', 1);
            }
        }
    }

    public function test_record_retry_requires_the_same_ledger_snapshot(): void
    {
        $id = $this->bank();
        $record = DB::table('fiscal_receipt_records')->find($id);
        $snapshot = json_decode($record->snapshot, true);
        $retry = fn (array $input): int => DB::transaction(fn () => app(Fiscalization::class)->recordLocked($this->staff, $this->requestRecord->id, 'payment', $record->basis_id, 'bank_transfer', 12345, $input));
        $this->assertSame($id, $retry($snapshot));
        $this->assertDatabaseCount('fiscal_receipt_states', 1);
        $this->expectException(HttpException::class);
        $retry([...$snapshot, 'payer' => 'Changed snapshot']);
    }

    public function test_database_enforces_fiscal_basis_amount_scenario_and_immutability(): void
    {
        $id = $this->bank();
        $record = (array) DB::table('fiscal_receipt_records')->find($id);
        unset($record['id']);
        foreach (['amount_kopecks' => 1, 'scenario' => 'refund', 'basis_id' => 999999, 'basis_type' => 'unsupported', 'service_request_id' => 999999] as $field => $value) {
            $this->databaseRejects(fn () => DB::table('fiscal_receipt_records')->insert([...$record, 'number' => 'FIS-'.$this->key(), $field => $value]), 'Invalid fiscal ledger basis');
        }
        foreach (['fiscal_receipt_records', 'fiscal_receipt_states'] as $table) {
            $this->databaseRejects(fn () => DB::table($table)->update(['updated_at' => now()->addDay()]), 'Fiscal evidence is immutable');
            $this->databaseRejects(fn () => DB::table($table)->delete(), 'Fiscal evidence is immutable');
        }
        $state = (array) DB::table('fiscal_receipt_states')->first();
        unset($state['id']);
        $this->databaseRejects(fn () => DB::table('fiscal_receipt_states')->insert([...$state, 'event_key' => $this->key(), 'status' => 'issued']), 'Invalid fiscal evidence status');
    }

    public function test_fiscal_ui_is_finance_only_scoped_and_does_not_confirm_a_receipt(): void
    {
        $id = $this->bank();
        $this->configuration();
        Livewire::actingAs($this->client)->test(Billing::class, ['id' => $this->requestRecord->id])->assertDontSee('Фискальная проверка')->assertDontSee('FIS-')->call('reviewFiscal', $id)->assertForbidden();
        $page = Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $this->requestRecord->id])->assertSee('не номер кассового чека')->set('fiscalReasons.'.$id, 'Verified decision')->call('reviewFiscal', $id)->assertHasNoErrors()->assertSee('утверждена, отправка не выполнена');
        $other = $this->requestRecord->replicate();
        $other->number = 'FISCAL-OTHER';
        $other->save();
        Livewire::actingAs($this->staff)->test(Billing::class, ['id' => $other->id])->set('fiscalReasons.'.$id, 'Wrong page')->call('reviewFiscal', $id)->assertNotFound();
        $this->assertDatabaseCount('fiscal_receipt_states', 2);
        $page->set('fiscalReasons.'.$id, 'Stale permission');
        DB::table('users')->where('id', $this->staff->id)->update(['roles' => json_encode(['dispatcher'])]);
        $page->call('reviewFiscal', $id)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_fiscal_review_notifications_do_not_leak_private_data_or_survive_role_loss(): void
    {
        Mail::fake();
        $recipient = User::factory()->create(['kind' => 'staff', 'roles' => ['finance']]);
        User::factory()->create(['kind' => 'staff', 'roles' => ['dispatcher']]);
        $id = $this->bank();
        $this->configuration();
        app(Fiscalization::class)->review($this->staff, $id, 'Private accountant reasoning', $this->key());
        $event = DB::table('outbox_events')->where('payload', 'like', '%finance.fiscal_reviewed%')->first();
        $this->assertStringNotContainsString('Private', $event->payload);
        $this->assertStringNotContainsString('fiscal_record_id', $event->payload);
        $this->assertStringNotContainsString('test-vat', $event->payload);
        app(DeliverPortalOutbox::class)->execute();
        $notices = DB::table('portal_notifications')->where('outbox_event_id', $event->id)->get();
        $this->assertSame([$recipient->id], $notices->pluck('user_id')->all());
        $this->assertSame('finance_internal', $notices->first()->audience);
        DB::table('users')->where('id', $recipient->id)->update(['kind' => 'client', 'roles' => '[]', 'organization_id' => $this->client->organization_id, 'accept_work' => true]);
        $this->assertFalse(PortalNotification::visibleTo($recipient->fresh())->where('outbox_event_id', $event->id)->exists());
        config(['portal.delivery.email_enabled' => true]);
        app(EmailDelivery::class)->execute();
        $this->assertDatabaseHas('notification_deliveries', ['portal_notification_id' => $notices->first()->id, 'status' => 'suppressed']);
    }

    public function test_fiscal_migration_can_reapply_without_removing_finance_guards(): void
    {
        $this->bank();
        $migration = require database_path('migrations/2026_11_12_140346_create_fiscal_receipt_records.php');
        $migration->down();
        $this->databaseRejects(fn () => DB::table('payment_receipts')->update(['amount_kopecks' => 1]), 'Posted finance is immutable');
        $migration->up();
        $this->bank();
        $this->assertDatabaseCount('fiscal_receipt_records', 1);
        $this->assertSame(6, DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'fiscal_receipt_%')->count());
    }
}
