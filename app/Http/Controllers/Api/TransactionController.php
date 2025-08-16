<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flights\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
        public function index(Request $request)
        {
            if ($request->user()->hasRole('agent')) {
                // Agents can only view their own wallet_topup transactions
                $transactions = $request->user()
                    ->transactions()
                    ->with([
                        'booking:id,order_num,status,user_id', // booking with its user_id
                        'booking.user:id,name,email',          // booking's user
                        'user:id,name,email'                   // transaction's user
                    ])
                    ->where('type', 'wallet_topup')
                    ->latest()
                    ->get();
            } else {
                // Admins can view all wallet_topup transactions
                $transactions = Transaction::with([
                        'booking:id,order_num,status,user_id',
                        'booking.user:id,name,email',
                        'user:id,name,email'
                    ])
                    ->where('type', 'wallet_topup')
                    ->latest()
                    ->get();
            }

            return response()->json([
                'status' => true,
                'data' => $transactions->map(function ($transaction) {
                    $type = $transaction->user
                        ? 'wallet_topup'
                        : ($transaction->booking ? 'booking' : null);

                    $name = $type === 'wallet_topup'
                        ? optional($transaction->user)->name
                        : optional(optional($transaction->booking)->user)->name;

                    $email = $type === 'wallet_topup'
                        ? optional($transaction->user)->email
                        : optional(optional($transaction->booking)->user)->email;

                    return [
                        'id' => $transaction->id,
                        'trx_id'          => $transaction->payment_gateway_reference,
                        'booking'         => $transaction->booking ?? [],
                        'date'            => $transaction->transaction_date->toDateString(),
                        'amount'          => $transaction->amount,
                        'currency'        => $transaction->currency,
                        'payment_gateway' => $transaction->payment_gateway ?? 'bank',
                        'status'          => $transaction->status,
                        'type'            => $type,
                        'name'            => $name,
                        'email'            => $email,
                        'description'     => null,
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

    /**
     * Approve or reject a transaction.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function approveOrReject(Request $request){

        if (! $request->user()->hasRole('admin')) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized: Only admins can approve or reject transactions',
            ], 403);
        }

        $validatedData = $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|string|in:approved,rejected',
            'note' => 'nullable|string',
        ]);
        $transaction = Transaction::find($validatedData['transaction_id']);
        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }


        // idempotency: only pending can transition
        if ($transaction->status !== 'pending') {
            return response()->json([
                'status'  => true,
                'message' => 'No change: transaction is already '. $transaction->status,
                'data'    => [
                    'trx_id'   => $transaction->payment_gateway_reference,
                    'status'   => $transaction->status,
                    'amount'   => (float) $transaction->amount,
                    'currency' => $transaction->currency,
                ],
            ], 200);
        }

        // normalize to storage statuses used by UI
        $normalized = $validatedData['status'] === 'approved' ? 'completed' : 'failed';

        // optional override: only allow amount override when approving
        if ($normalized === 'completed') {
            $amount = isset($validatedData['amount']) ? (float) $validatedData['amount'] : (float) $transaction->amount;
            if ($amount <= 0) {
                return response()->json(['message' => 'Amount must be greater than 0'], 422);
            }

            // update tx first
            $transaction->amount      = $amount;
            $transaction->status      = 'completed';
            // $transaction->approve_note= $validatedData['note'] ?? null;
            $transaction->save();

            // lock user row then credit
            $user = User::whereKey($transaction->user_id)->lockForUpdate()->first();
            $user->wallet_balance = (float) $user->wallet_balance + $amount;
            $user->save();

        } else {
            $transaction->status         = 'failed';
            // $transaction->decline_reason = $validatedData['note'] ?? null;
            $transaction->save();
        }

        return response()->json([
            'status' => true,
            'message' => 'Transaction updated successfully',
            'data' => [
                'trx_id' => $transaction->payment_gateway_reference,
                'status' => $transaction->status,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
            ],
        ]);
    }
}
