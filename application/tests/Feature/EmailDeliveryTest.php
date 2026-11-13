<?php

namespace Tests\Feature;

use App\Actions\Identity\ManageAccounts;
use App\Actions\Notifications\DeliverPortalOutbox;
use App\Actions\Notifications\EmailDelivery;
use App\Actions\Operation;
use App\Livewire\DeliveryWorkbench;
use App\Mail\PortalMessage;
use App\Models\AccountInvitation;
use App\Models\PortalNotification;
use App\Models\ServiceRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class EmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $staff;

    private ServiceRequest $requestRecord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        config(['portal.delivery.email_enabled' => true]);
        $this->staff = User::factory()->create(['kind' => 'staff', 'roles' => ['finance']]);
        $org = DB::table('organizations')->insertGetId(['name' => 'Test buyer']);
        $site = Site::create(['organization_id' => $org, 'name' => 'Test site', 'address' => 'Test address']);
        $this->client = User::factory()->create(['organization_id' => $org, 'accept_work' => true]);
        $this->requestRecord = ServiceRequest::create(['site_id' => $site->id, 'organization_id' => $org, 'author_id' => $this->client->id, 'number' => 'MAIL-TEST', 'title' => 'PRIVATE REPAIR DETAILS', 'description' => 'PRIVATE REPORT']);
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function queue(string $action = 'finance.invoice_issued'): void
    {
        DB::transaction(fn () => app(Operation::class)->event($this->staff, $this->requestRecord->id, $action));
        app(DeliverPortalOutbox::class)->execute();
    }

    public function test_portal_and_email_retry_deduplicate_without_changing_business_state(): void
    {
        $this->queue();
        $this->assertDatabaseCount('notification_deliveries', 1);
        DB::table('outbox_events')->update(['status' => 'pending']);
        app(DeliverPortalOutbox::class)->execute();
        $this->assertDatabaseCount('notification_deliveries', 1);
        $delivery = app(EmailDelivery::class);
        $this->assertSame(1, $delivery->execute());
        $this->assertSame(0, $delivery->execute());
        Mail::assertSent(PortalMessage::class, 1);
        Mail::assertSent(PortalMessage::class, fn ($mail) => $mail->hasTo($this->client->email) && $mail->taskUrl === route('requests.billing', $this->requestRecord->id));
        $this->assertSame('new', $this->requestRecord->fresh()->status);
        $this->assertDatabaseCount('payment_receipts', 0);
        $this->assertDatabaseHas('notification_delivery_attempts', ['status' => 'sent']);
    }

    public function test_email_template_contains_only_event_and_authenticated_link(): void
    {
        $this->queue();
        app(EmailDelivery::class)->execute();
        Mail::assertSent(PortalMessage::class, function ($mail) {
            $html = $mail->render();

            return str_contains($html, route('requests.billing', $this->requestRecord->id)) && ! str_contains($html, 'PRIVATE REPORT') && ! str_contains($html, 'PRIVATE REPAIR DETAILS');
        });
    }

    public function test_preferences_suppress_pending_email_not_portal_notice(): void
    {
        $this->queue();
        Livewire::actingAs($this->client)->test(DeliveryWorkbench::class)->set('email', false)->call('save')->assertHasNoErrors();
        app(EmailDelivery::class)->execute();
        Mail::assertNothingSent();
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'suppressed']);
        $this->assertSame(1, PortalNotification::visibleTo($this->client)->count());
    }

    public function test_representative_permission_loss_suppresses_financial_delivery(): void
    {
        $this->queue();
        $this->client->forceFill(['accept_work' => false])->save();
        $this->client->sites()->attach($this->requestRecord->site_id);
        $this->assertTrue(ServiceRequest::visibleTo($this->client)->exists());
        app(EmailDelivery::class)->execute();
        Mail::assertNothingSent();
        $this->assertFalse(PortalNotification::visibleTo($this->client)->exists());
    }

    public function test_transport_failure_has_safe_backoff_and_operator_retry(): void
    {
        $this->queue();
        $fake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('PRIVATE TRANSPORT TOKEN'));
        app(EmailDelivery::class)->execute();
        $row = DB::table('notification_deliveries')->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame('transport_failed', $row->failure_code);
        $this->assertSame(0, app(EmailDelivery::class)->execute());
        $admin = User::factory()->create(['kind' => 'staff', 'roles' => ['administrator']]);
        Livewire::actingAs($admin)->test(DeliveryWorkbench::class)->set('reasons.'.$row->id, 'Transport repaired')->call('retry', $row->id)->assertHasNoErrors();
        Mail::swap($fake);
        $this->assertSame(1, app(EmailDelivery::class)->execute());
        Mail::assertSent(PortalMessage::class, 1);
        $this->assertDatabaseCount('notification_delivery_attempts', 2);
    }

    public function test_disabled_transport_does_not_claim_or_report_sent(): void
    {
        $this->queue();
        config(['portal.delivery.email_enabled' => false]);
        $this->assertSame(0, app(EmailDelivery::class)->execute());
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'pending', 'attempts' => 0]);
        Mail::assertNothingSent();
    }

    public function test_invitation_is_queued_transactionally_and_revocation_stops_delivery(): void
    {
        $admin = User::factory()->create(['kind' => 'staff', 'roles' => ['administrator']]);
        $key = $this->key();
        $first = app(ManageAccounts::class)->invite($admin, $this->client->id, $this->client->session_version, 'Provide access', $key);
        $this->assertSame($first, app(ManageAccounts::class)->invite($admin, $this->client->id, $this->client->session_version, 'Provide access', $key));
        $this->assertDatabaseCount('notification_deliveries', 1);
        AccountInvitation::findOrFail($first['invitation_id'])->update(['revoked_at' => now()]);
        app(EmailDelivery::class)->execute();
        Mail::assertNothingSent();
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'suppressed']);
    }

    public function test_invitation_ignores_event_preference_and_uses_fresh_signed_link(): void
    {
        $admin = User::factory()->create(['kind' => 'staff', 'roles' => ['administrator']]);
        app(EmailDelivery::class)->preference($this->client, false, $this->key());
        $first = app(ManageAccounts::class)->invite($admin, $this->client->id, $this->client->session_version, 'Provide access', $this->key());
        app(EmailDelivery::class)->execute();
        Mail::assertSent(PortalMessage::class, fn ($mail) => $mail->invitation && $mail->taskUrl === AccountInvitation::find($first['invitation_id'])->setupUrl());
    }

    public function test_financial_corrections_notify_only_finance_and_representatives(): void
    {
        $dispatcher = User::factory()->create(['kind' => 'staff', 'roles' => ['dispatcher']]);
        $ordinaryClient = User::factory()->create(['organization_id' => $this->requestRecord->organization_id, 'accept_work' => false]);
        $ordinaryClient->sites()->attach($this->requestRecord->site_id);
        $this->queue('finance.receipt_reversed');
        $this->queue('finance.invoice_credited');
        $this->assertSame(2, PortalNotification::visibleTo($this->client)->count());
        $this->assertSame(0, PortalNotification::visibleTo($ordinaryClient)->count());
        $this->assertSame(0, PortalNotification::visibleTo($dispatcher)->count());
        $this->assertDatabaseCount('notification_deliveries', 2);
        app(EmailDelivery::class)->execute();
        Mail::assertSent(PortalMessage::class, 2);
        Mail::assertSent(PortalMessage::class, fn ($mail) => $mail->eventTitle === 'Выпущен кредит к принятому счёту' && $mail->taskUrl === route('requests.billing', $this->requestRecord->id));
        $this->assertSame('new', $this->requestRecord->fresh()->status);
    }

    public function test_delivery_page_combines_preferences_with_role_scoped_queue(): void
    {
        Livewire::actingAs($this->client)
            ->test(DeliveryWorkbench::class)
            ->assertSet('canManageDeliveries', false)
            ->assertSee('Личные настройки')
            ->assertDontSee('Очередь доставки');

        Livewire::actingAs($this->staff)
            ->test(DeliveryWorkbench::class)
            ->assertSet('canManageDeliveries', true)
            ->assertSee('Личные настройки')
            ->assertSee('Очередь доставки');
    }

    public function test_legacy_preferences_url_redirects_to_delivery_page(): void
    {
        $this->actingAs($this->client)
            ->withSession(['account_version' => $this->client->session_version])
            ->get(route('notification-preferences'))
            ->assertRedirect(route('delivery'));
    }
}
