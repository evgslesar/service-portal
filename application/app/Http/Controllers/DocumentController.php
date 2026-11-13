<?php

namespace App\Http\Controllers;

use App\Models\ResultSnapshot;
use App\Models\ServiceRequest;
use App\Models\WorkPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class DocumentController extends Controller
{
    public function result(Request $request, int $id, int $result): Response
    {
        $record = ServiceRequest::visibleTo($request->user())->findOrFail($id);
        $snapshot = ResultSnapshot::findOrFail($result);
        $package = WorkPackage::visibleTo($request->user())->where('service_request_id', $id)->findOrFail($snapshot->work_package_id);

        return $this->printable([
            'record' => $record, 'title' => 'Отчёт о работах',
            'number' => $record->number.' / '.$package->ordinal.' / '.$snapshot->round,
            'snapshot' => $snapshot, 'document' => null, 'header' => [], 'lines' => [],
        ]);
    }

    public function estimate(Request $request, int $id, int $estimate): Response
    {
        $record = $this->financeRecord($request, $id);
        $document = DB::table('estimates')->where('service_request_id', $id)->where('id', $estimate)->firstOrFail();

        return $this->printable([
            'record' => $record, 'title' => 'Смета · версия '.$document->revision,
            'number' => $document->number, 'snapshot' => null, 'document' => $document,
            'header' => json_decode($document->header, true), 'lines' => json_decode($document->lines, true),
        ]);
    }

    public function invoice(Request $request, int $id, int $invoice): Response
    {
        $record = $this->financeRecord($request, $id);
        $document = DB::table('finance_documents')->where('service_request_id', $id)->where('id', $invoice)->firstOrFail();

        return $this->printable([
            'record' => $record,
            'title' => match ($document->kind) {
                'advance' => 'Счёт на аванс', 'actual' => 'Счёт по принятым работам',
                'advance_credit', 'actual_credit' => 'Кредитный документ', default => 'Счёт',
            },
            'number' => $document->number, 'snapshot' => null, 'document' => $document,
            'header' => json_decode($document->header, true), 'lines' => json_decode($document->lines, true),
        ]);
    }

    private function financeRecord(Request $request, int $id): ServiceRequest
    {
        $user = $request->user();
        abort_unless($user->hasRole('finance') || ($user->kind === 'client' && $user->accept_work), 404);

        return ServiceRequest::visibleTo($user)->findOrFail($id);
    }

    private function printable(array $data): Response
    {
        return response()->view('documents.print', $data)->withHeaders([
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'same-origin',
        ]);
    }
}
