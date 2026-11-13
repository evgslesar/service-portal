<?php

namespace App\Actions\ServiceRequests;

use App\Actions\Operation;
use App\Actions\Photos\SavePhoto;
use App\Models\Attachment;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class SendMessage
{
    public function __construct(private Operation $operations, private Workflow $workflow, private SavePhoto $photos) {}

    /** @param array<int, UploadedFile> $files */
    public function execute(User $actor, int $requestId, array $input, array $files, string $key): array
    {
        ServiceRequest::visibleTo($actor->fresh())->findOrFail($requestId);
        Validator::make(['messageFiles' => $files], ['messageFiles' => 'array|max:5', 'messageFiles.*' => 'file|max:20480|mimes:pdf,jpg,jpeg,png,webp'])->validate();
        $prepared = [];
        try {
            foreach ($files as $file) {
                $mime = $file->getMimeType();
                if ($mime === 'application/pdf') {
                    if ($file->getSize() > 10 * 1024 * 1024) {
                        throw ValidationException::withMessages(['messageFiles' => 'PDF больше 10 МБ. Выберите меньший файл.']);
                    }
                    $bytes = file_get_contents($file->getRealPath());
                    if (! str_starts_with($bytes, '%PDF-')) {
                        throw ValidationException::withMessages(['messageFiles' => 'Не удалось проверить PDF. Выберите другой файл.']);
                    }
                    $extension = 'pdf';
                } else {
                    $bytes = $this->photos->normalize($file);
                    $mime = 'image/jpeg';
                    $extension = 'jpg';
                }
                $path = 'messages/'.Str::uuid().'.'.$extension;
                $name = Str::limit(pathinfo(basename(str_replace('\\', '/', $file->getClientOriginalName())), PATHINFO_FILENAME), 150, '').'.'.$extension;
                $prepared[] = ['path' => $path, 'name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'hash' => hash_file('sha256', $file->getRealPath())];
                if (! Storage::disk('local')->put($path, $bytes)) {
                    throw ValidationException::withMessages(['messageFiles' => 'Файл не сохранён. Текст и выбранные файлы остаются для повтора.']);
                }
            }
            $fingerprint = array_map(fn ($file) => [$file['hash'], $file['name']], $prepared);
            $result = $this->operations->run($actor, $key, 'message.attachments_send', [$requestId, $input, $fingerprint], function () use ($actor, $requestId, $input, $key, $prepared) {
                $result = $this->workflow->message($actor->fresh(), $requestId, $input, (string) Uuid::uuid5($key, 'message'));
                foreach ($prepared as $file) {
                    unset($file['hash']);
                    Attachment::create([...$file, 'service_request_id' => $requestId, 'request_message_id' => $result['id'], 'author_id' => $actor->id]);
                }

                return $result;
            });
            $persisted = Attachment::where('request_message_id', $result['id'])->pluck('path')->all();
            foreach ($prepared as $file) {
                if (! in_array($file['path'], $persisted, true)) {
                    Storage::disk('local')->delete($file['path']);
                }
            }

            return $result;
        } catch (\Throwable $exception) {
            foreach ($prepared as $file) {
                if (! Attachment::where('path', $file['path'])->exists()) {
                    Storage::disk('local')->delete($file['path']);
                }
            }
            if ($exception instanceof ValidationException && isset($exception->errors()['photos'])) {
                throw ValidationException::withMessages(['messageFiles' => $exception->errors()['photos']]);
            }
            throw $exception;
        }
    }
}
