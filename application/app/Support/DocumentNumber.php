<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DocumentNumber
{
    public static function next(string $kind): string
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        DB::table('document_sequences')->insertOrIgnore(['kind' => $kind, 'next' => 1]);
        $row = DB::table('document_sequences')->where('kind', $kind)->lockForUpdate()->firstOrFail();
        DB::table('document_sequences')->where('kind', $kind)->increment('next');

        return $kind.'-'.str_pad((string) $row->next, 8, '0', STR_PAD_LEFT);
    }
}
