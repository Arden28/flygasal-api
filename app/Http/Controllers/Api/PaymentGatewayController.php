<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentGatewayController extends Controller
{
    public function index(Request $request)
    {

        // Mock payment gateways (replace with database query if needed)
        $gateways = [
            [
                'name' => 'Bank Transfer',
                'c1' => 'Bank Name: Example Bank',
                'c2' => 'Account Number: 1234567890',
                'c3' => 'SWIFT: EXABUSXX',
                'c4' => 'IBAN: US12345678901234567890',
            ],
        ];

        return response()->json([
            'status' => 'true',
            'data' => $gateways,
        ]);
    }
}
