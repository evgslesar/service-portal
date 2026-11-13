<?php

namespace App\Http\Controllers;

use App\Actions\Finance\ProviderCommands;
use Illuminate\Http\Request;

class YooKassaWebhookController extends Controller
{
    public function __invoke(Request $request, ProviderCommands $commands)
    {
        abort_if(strlen($request->getContent()) > 16384, 413);
        $input = $request->validate(['event' => 'required|string|max:100', 'object.id' => 'required|string|size:36']);
        // Persist only an untrusted lookup hint. Verification happens asynchronously through the provider API.
        $commands->receive($input['event'], $input['object']['id']);

        return response()->json(['received' => true], 202);
    }
}
