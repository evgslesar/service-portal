<?php

return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK', 'local'),
        'rules' => ['required', 'file', 'max:20480'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
