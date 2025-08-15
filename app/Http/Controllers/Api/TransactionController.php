<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flights\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        // Validate API key and user_id
        // $validator = Validator::make($request->all(), [
        //     'user_id' => 'required|exists:users,id',
        // ]);

        // if ($validator->fails()) {
        //     return response()->json(['status' => 'false', 'errors' => $validator->errors()], 422);
        // }

        if ($request->user()->hasRole('agent')) {
            // Agents can only view their own wallet_topup transactions
            $transactions = $request->user()
                ->transactions()
                ->where('type', 'wallet_topup')
                ->latest()
                ->paginate(20);
        } else {
            // Admins can view all wallet_topup transactions
            $transactions = Transaction::with([
                'booking:id,order_num,status', // only needed booking fields
                'user:id,name,email'           // only needed user fields
            ])
            ->latest()
                ->paginate(10);
        }

        // Fetch transactions for the user
        $transactions = Transaction::where('user_id', $request->user_id)
            ->where('type', 'wallet_topup') // Only fetch wallet_topup transactions
            ->get();

        return response()->json([
            'status' => true,
            'data' => $transactions->map(function ($transaction) {
                return [
                    'trx_id' => $transaction->payment_gateway_reference,
                    'date' => $transaction->transaction_date->toDateString(),
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'payment_gateway' => $transaction->payment_gateway ?? 'bank', // Fallback if not set
                    'status' => $transaction->status,
                    'description' => null, // Not in schema, return null for compatibility
                ];
            }),
        ]);
    }

    /**
     * Store a newly created user in storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validate request
        $validatedData = $request->validate([
            'user_id' => 'required|exists:users,id',
            'type' => 'required|string|in:wallet_topup',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'payment_gateway_reference' => 'required|string|unique:transactions,payment_gateway_reference',
            'payment_gateway' => 'required|string',
        ]);

        // Create transaction
        $transaction = Transaction::create([
            'user_id' => $validatedData['user_id'] ?? Auth::user()->id,
            'booking_id' => null, // Not used for wallet_topup
            'amount' => $validatedData['amount'],
            'currency' => $validatedData['currency'],
            'type' => $validatedData['type'],
            'status' => 'pending',
            'payment_gateway_reference' => $validatedData['payment_gateway_reference'],
            'transaction_date' => now(),
            'payment_gateway' => $validatedData['payment_gateway'], // Store payment gateway
        ]);

        return response()->json([
            'status' => 'true',
            'data' => [
                'trx_id' => $transaction->payment_gateway_reference,
                'date' => $transaction->transaction_date->toDateString(),
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'payment_gateway' => $transaction->payment_gateway,
                'status' => $transaction->status,
                'description' => null,
            ],
            'message' => 'Deposit request submitted successfully',
        ], 201);
    }
}
