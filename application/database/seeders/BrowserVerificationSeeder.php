<?php

namespace Database\Seeders;

use App\Actions\Finance\Finance;
use App\Actions\Inventory\Inventory;
use App\Actions\Inventory\Purchasing;
use App\Models\Contract;
use App\Models\ServiceRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class BrowserVerificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'sqlite'
            || realpath(storage_path('framework/testing/browser-verification.sqlite')) === false
            || realpath(DB::connection()->getDatabaseName()) !== realpath(storage_path('framework/testing/browser-verification.sqlite'))) {
            throw new RuntimeException('Browser fixtures require the isolated verification database.');
        }
        if (DB::table('users')->exists()) {
            throw new RuntimeException('Browser fixtures require an empty verification database.');
        }
        config(['portal.finance.approved' => true, 'portal.finance.legal_header' => 'Browser fixture seller', 'portal.finance.tax_label' => 'Browser fixture tax; not approved for real documents']);
        DB::transaction(function () {
            $staff = User::factory()->create(['name' => 'Проверка · Финансист и склад', 'email' => 'browser-staff@example.test', 'password' => 'Browser-Fixture-Only-41!', 'kind' => 'staff', 'roles' => ['finance', 'warehouse', 'dispatcher'], 'totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_confirmed_at' => now(), 'recovery_codes' => [Hash::make('browser-staff-recovery')]]);
            $technician = User::factory()->create(['name' => 'Проверка · Мастер', 'kind' => 'staff', 'roles' => ['technician']]);
            $organization = DB::table('organizations')->insertGetId(['name' => 'Проверка · Заказчик']);
            $client = User::factory()->create(['name' => 'Проверка · Представитель', 'email' => 'browser-client@example.test', 'password' => 'Browser-Fixture-Only-41!', 'organization_id' => $organization, 'accept_work' => true, 'totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_confirmed_at' => now(), 'recovery_codes' => [Hash::make('browser-client-recovery')]]);
            $site = Site::create(['organization_id' => $organization, 'name' => 'Проверка · Объект', 'address' => 'Вымышленный адрес']);
            $contract = Contract::create(['organization_id' => $organization, 'number' => 'BROWSER-CONTRACT', 'title' => 'Проверка договора', 'starts_on' => now()->subYear(), 'ends_on' => now()->addYear(), 'covered_categories' => ['plumbing'], 'terms' => []]);
            $request = ServiceRequest::create(['site_id' => $site->id, 'organization_id' => $organization, 'author_id' => $client->id, 'number' => 'BROWSER-001', 'title' => 'Проверка · Замена соединения', 'description' => 'Изолированная проверка финансовых и складских экранов']);
            $package = $request->packages()->create(['ordinal' => 1, 'contract_id' => $contract->id, 'contract_snapshot' => [], 'title' => 'Замена соединения', 'scope' => 'Проверочный объём', 'category' => 'plumbing', 'kind' => 'paid', 'lead_id' => $technician->id, 'photo_required' => false, 'checklist_template' => []]);
            $inventory = app(Inventory::class);
            $key = fn () => (string) Str::uuid();
            $material = $inventory->material($staff, ['code' => 'BROWSER-PIPE', 'name' => 'Соединитель проверочный', 'unit' => 'шт.', 'reason' => 'Browser fixture'], $key())['id'];
            $store = $inventory->location($staff, 'Проверка · Склад', null, 'Browser fixture', $key())['id'];
            $inventory->opening($staff, $material, $store, '1', '100', 'Browser fixture', $key());
            $finance = app(Finance::class);
            $lines = [['package_id' => $package->id, 'kind' => 'labor', 'description' => 'Работа мастера', 'quantity' => '100', 'rate' => '10'], ['package_id' => $package->id, 'kind' => 'material', 'material_id' => $material, 'description' => 'Соединители', 'quantity' => '3', 'rate' => '200']];
            $estimate = $finance->issue($staff, $request->id, $request->fresh()->version, 'independent_packages', $lines, 'Исходный проверочный объём', $key())['id'];
            $finance->decideEstimate($client, $estimate, 'approved', '', $key());
            $lines[0]['quantity'] = '120';
            $finance->issue($staff, $request->id, $request->fresh()->version, 'independent_packages', $lines, 'Добавлен проверочный объём работ', $key());
            $purchasing = app(Purchasing::class);
            $supplier = $purchasing->supplier($staff, 'Проверка · Поставщик', 'Вымышленные реквизиты', $key())['id'];
            $purchasing->order($staff, ['supplier_id' => $supplier, 'location_id' => $store, 'expected_on' => now()->addDay()->format('Y-m-d'), 'reason' => 'Browser fixture', 'lines' => [['material_id' => $material, 'quantity' => '2', 'unit_cost' => '100']]], $key());
        });
    }
}
