<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class WebhookTestController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->all();
        return response()->json([
            'message' => 'Webhook received',
            'method' => $request->method(),
            'data' => $data
        ]);
    }
}
