<?php

namespace App\Livewire;

use App\Actions\Notifications\EmailDelivery;
use Illuminate\Support\Facades\DB;

class DeliveryWorkbench extends TaskPage
{
    public bool $email = true;

    public bool $canManageDeliveries = false;

    public array $reasons = [];

    public function mount(EmailDelivery $delivery): void
    {
        $actor = $this->actor()->fresh();
        abort_unless($actor?->active, 403);

        $this->email = (bool) (DB::table('notification_preferences')->where('user_id', $actor->id)->value('email') ?? true);
        $this->canManageDeliveries = $actor->canManageUsers() || $actor->hasRole('finance');
        $this->prepareKeys(['save']);

        if ($this->canManageDeliveries) {
            foreach ($delivery->operatorQuery($actor)->whereIn('status', ['pending', 'failed'])->limit(100)->pluck('id') as $id) {
                $this->prepareKeys(['retry-'.$id]);
            }
        }
    }

    public function save(EmailDelivery $delivery): void
    {
        $delivery->preference($this->actor(), $this->email, $this->key('save'));
        $this->done('save', 'Настройки писем сохранены. Уведомления в портале остаются доступны.');
    }

    public function retry(int $id, EmailDelivery $delivery): void
    {
        $delivery->retry($this->actor(), $id, $this->reasons[$id] ?? '', $this->key('retry-'.$id));
        $this->done('retry-'.$id, 'Повтор поставлен в очередь. Получатель и права будут проверены заново перед отправкой.');
    }

    public function render(EmailDelivery $delivery)
    {
        abort_unless($this->actor()->fresh()?->active, 403);

        $deliveries = $this->canManageDeliveries
            ? $delivery->operatorQuery($this->actor())->orderByDesc('id')->limit(100)->get()
            : collect();

        return view('livewire.delivery-workbench', [
            'enabled' => $delivery->enabled(),
            'deliveries' => $deliveries,
        ]);
    }
}
