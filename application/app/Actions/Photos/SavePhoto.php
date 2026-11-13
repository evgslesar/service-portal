<?php

namespace App\Actions\Photos;

use App\Actions\Operation;
use App\Models\Attachment;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SavePhoto
{
    public function __construct(private Operation $operations) {}

    public function execute(User $actor, int $requestId, ?int $visitId, UploadedFile $file, string $key): Attachment
    {
        $r = ServiceRequest::visibleTo($actor)->findOrFail($requestId);
        Gate::forUser($actor)->authorize('view', $r);
        $bytes = $this->normalize($file);
        $path = 'photos/'.Str::uuid().'.jpg';
        if (! Storage::disk('local')->put($path, $bytes)) {
            $this->error('Фото не сохранено. Повторите загрузку.');
        }
        try {
            $result = $this->operations->run($actor, $key, 'photo.bind', [$requestId, $visitId, hash_file('sha256', $file->getRealPath())], function () use ($actor, $r, $visitId, $path, $bytes) {
                $r = ServiceRequest::whereKey($r->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor->fresh())->authorize('view', $r);
                if (in_array($r->status, ['closed', 'cancelled'])) {
                    $this->error('В эту заявку больше нельзя добавлять фото.');
                }
                if ($visitId) {
                    $v = Visit::whereHas('package', fn ($q) => $q->where('service_request_id', $r->id))->whereKey($visitId)->lockForUpdate()->firstOrFail();
                    abort_unless($actor->fresh()->hasRole('technician') && $v->technician_id === $actor->id && $v->status === 'in_progress', 403);
                    $v->increment('version');
                } else {
                    abort_unless($actor->kind === 'client' || $actor->hasRole('dispatcher'), 403);
                }
                $a = Attachment::create(['service_request_id' => $r->id, 'visit_id' => $visitId, 'author_id' => $actor->id, 'path' => $path, 'mime' => 'image/jpeg', 'name' => 'Фото.jpg', 'size' => strlen($bytes)]);
                $this->operations->event($actor, $r->id, 'photo.attached', ['attachment_id' => $a->id, 'visit_id' => $visitId]);

                return ['id' => $a->id];
            });
            $attachment = Attachment::findOrFail($result['id']);
            if ($attachment->path !== $path) {
                Storage::disk('local')->delete($path);
            }

            return $attachment;
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function normalize(UploadedFile $file): string
    {
        if ($file->getSize() > 20 * 1024 * 1024) {
            $this->error('Фото больше 20 МБ. Выберите меньший файл.');
        }
        $mime = $file->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->error('Сейчас доступны JPEG, PNG и WebP. Поддержка фото HEIC/HEIF ещё проверяется.');
        }
        $info = @getimagesize($file->getRealPath());
        if (! $info || $info[0] * $info[1] > 40000000) {
            $this->error('Фото повреждено или больше 40 мегапикселей.');
        }
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file->getRealPath()),
            'image/png' => @imagecreatefrompng($file->getRealPath()),
            'image/webp' => @imagecreatefromwebp($file->getRealPath()),
        };
        if (! $image) {
            $this->error('Не удалось открыть фото. Попробуйте другой файл.');
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $orientation = @exif_read_data($file->getRealPath())['Orientation'] ?? 1;
            if (in_array($orientation, [2, 4, 5, 7])) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
            $angle = match ($orientation) {
                3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
            };
            if ($angle) {
                $rotated = imagerotate($image, $angle, 0);
                imagedestroy($image);
                $image = $rotated;
            }
        }
        $scale = min(1, 2048 / max(imagesx($image), imagesy($image)));
        $target = imagecreatetruecolor((int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale));
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), imagesx($image), imagesy($image));
        ob_start();
        imagejpeg($target, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($target);
        imagedestroy($image);
        if (strlen($bytes) > 10 * 1024 * 1024) {
            $this->error('Не удалось безопасно уменьшить фото. Выберите другой файл.');
        }

        return $bytes;
    }

    private function error(string $message): never
    {
        throw ValidationException::withMessages(['photos' => $message]);
    }
}
