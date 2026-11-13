<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoController extends Controller
{
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $attachment), 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        abort_unless(in_array($attachment->mime, ['image/jpeg', 'application/pdf'], true) && $disk->exists($attachment->path), 404);
        $headers = ['Content-Type' => $attachment->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'Referrer-Policy' => 'same-origin'];

        return $attachment->mime === 'application/pdf'
            ? $disk->download($attachment->path, $attachment->name, $headers)
            : $disk->response($attachment->path, $attachment->name, $headers);
    }
}
