<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\ServiceRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('portal.demo') || ! app()->environment('local')) {
            throw new \RuntimeException('Demo data is local-only.');
        }
        DB::transaction(function () {
            foreach (['Демо · Северные офисы', 'Демо · Городские магазины'] as $name) {
                DB::table('organizations')->updateOrInsert(['name' => $name], ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            $orgs = DB::table('organizations')->where('name', 'like', 'Демо ·%')->orderBy('id')->pluck('id');
            $site = Site::firstOrCreate(['name' => 'Демо · Офис на Лесной'], ['organization_id' => $orgs[0], 'address' => 'Демонстрационный адрес: Москва, Лесная, 12', 'contact_name' => 'Демо · Администратор объекта', 'access_notes' => 'Вход со двора. На стойке сообщите номер заявки.']);
            Site::firstOrCreate(['name' => 'Демо · Офис на Набережной'], ['organization_id' => $orgs[0], 'address' => 'Демонстрационный адрес: Москва, Набережная, 8']);
            Site::firstOrCreate(['name' => 'Демо · Магазин'], ['organization_id' => $orgs[1], 'address' => 'Демонстрационный адрес: Москва, Садовая, 25']);
            $users = [];
            foreach ([
                ['client', 'Демо · Ответственный клиента', 'client', [], true, $orgs[0]],
                ['contact', 'Демо · Контакт объекта', 'client', [], false, $orgs[0]],
                ['other', 'Демо · Другой клиент', 'client', [], true, $orgs[1]],
                ['dispatcher', 'Демо · Диспетчер', 'staff', ['dispatcher', 'administrator'], false, null],
                ['technician', 'Демо · Ведущий мастер', 'staff', ['technician'], false, null],
                ['worker', 'Демо · Мастер команды', 'staff', ['technician'], false, null],
                ['electrician', 'Демо · Электрик', 'staff', ['technician'], false, null],
            ] as [$login, $name, $kind, $roles, $accept, $org]) {
                $u = User::where('email', $login.'@example.test')->first();
                if (! $u) {
                    $u = new User;
                    $u->forceFill(['email' => $login.'@example.test', 'name' => $name, 'password' => Str::random(48), 'kind' => $kind, 'organization_id' => $org, 'roles' => $roles, 'accept_work' => $accept])->save();
                }
                $users[$login] = $u;
            }
            $users['contact']->sites()->syncWithoutDetaching([$site->id]);
            $contract = Contract::firstOrCreate(['number' => 'ДЕМО-ОБС-001'], ['organization_id' => $orgs[0], 'title' => 'Демо · Обслуживание и диагностика', 'starts_on' => now()->startOfYear(), 'ends_on' => now()->addYear()->endOfYear(), 'covered_categories' => ['plumbing', 'electrical', 'maintenance'], 'terms' => ['scope' => 'Без доплаты: осмотр и диагностика без использования материалов', 'currency' => 'RUB']]);
            $contract->sites()->syncWithoutDetaching(Site::where('organization_id', $orgs[0])->pluck('id'));
            if (ServiceRequest::where('number', 'ДЕМО-0001')->exists()) {
                return;
            }
            $r = ServiceRequest::create(['number' => 'ДЕМО-0001', 'organization_id' => $orgs[0], 'site_id' => $site->id, 'author_id' => $users['client']->id, 'title' => 'Вода под раковиной в кухне', 'description' => 'Демонстрационная заявка: под раковиной появилась вода. Нужен осмотр соединений.', 'place' => 'Второй этаж, кухня', 'category' => 'plumbing', 'status' => 'assigned']);
            $p = $r->packages()->create(['ordinal' => 1, 'title' => 'Осмотр сантехники', 'scope' => 'Найти причину протечки, проверить соединения, описать результат. Материалы не использовать.', 'category' => 'plumbing', 'kind' => 'diagnostic', 'contract_id' => $contract->id, 'contract_snapshot' => $contract->toArray(), 'lead_id' => $users['technician']->id, 'status' => 'scheduled', 'photo_required' => true, 'checklist_template' => [['text' => 'Проверена безопасность рабочего места', 'required' => true], ['text' => 'Проверены соединения и следы воды', 'required' => true]]]);
            foreach (['technician', 'worker'] as $login) {
                $p->visits()->create(['technician_id' => $users[$login]->id, 'planned_start' => now('Europe/Moscow')->addDay()->setTime(10, 0)->utc(), 'planned_end' => now('Europe/Moscow')->addDay()->setTime(11, 0)->utc(), 'checklist' => array_map(fn ($i) => [...$i, 'done' => false], $p->checklist_template)]);
            }
            ServiceRequest::create(['number' => 'ДЕМО-0002', 'organization_id' => $orgs[0], 'site_id' => $site->id, 'author_id' => $users['contact']->id, 'title' => 'Свет мигает в переговорной', 'description' => 'Демонстрационная заявка: свет мигает в переговорной на первом этаже.', 'place' => 'Переговорная', 'status' => 'new']);
        });
    }
}
