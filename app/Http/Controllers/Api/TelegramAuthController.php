<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TelegramAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->all();

        // 1) Basic presence checks
        foreach (['id','auth_date','hash'] as $k) {
            if (!isset($data[$k])) {
                return response()->json(['status' => 'error', 'error' => 'Invalid Telegram payload'], 400);
            }
        }

        // 2) Verify signature
        $botToken = config('services.telegram.bot_token'); // put your bot token in config/services.php
        if (!$this->verifyTelegramAuth($data, $botToken)) {
            return response()->json(['status' => 'error', 'error' => 'Signature verification failed'], 401);
        }

        // 3) Optional: check auth_date freshness (e.g. 1 day)
        $authDate = Carbon::createFromTimestamp((int)$data['auth_date']);
        if ($authDate->lt(now()->subDay())) {
            return response()->json(['status' => 'error', 'error' => 'Telegram login expired'], 401);
        }

        // 4) Find or link a user
        // Strategy A: only allow pre-approved/created accounts to login:
        $user = User::where('telegram_id', $data['id'])->first();

        if (!$user) {
            // try match by username/email if you want to auto-link
            if (!empty($data['username'])) {
                $user = User::where('telegram_username', $data['username'])->first();
            }

            if (!$user) {
                // No linked account
                return response()->json(['status' => 'no_account'], 200);
            }

            // Link now (if you allow auto-linking)
            $user->telegram_id = $data['id'];
            $user->telegram_username = $data['username'] ?? $user->telegram_username;
            $user->save();
        }

        // Example: require approval
        if (method_exists($user, 'isApproved') && !$user->isApproved()) {
            return response()->json(['status' => 'pending'], 200);
        }

        // 5) Create session / token
        // If you use Sanctum SPA (cookie-based), you can log the user into this API request:
        auth()->login($user); // sets the session if you’re using web guard on API (ensure sanctum/cors setup)
        // Or issue a token:
        $token = $user->createToken('tg')->plainTextToken;

        return response()->json([
            'status' => 'ok',
            'user' => $user,
            'role' => $user->getRoleNames()->first() ?? 'No role assigned', // Single role or fallback
            'telegram' => [
                'id'       => $user->telegram_id,
                'username' => $user->telegram_username,
            ],
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    private function verifyTelegramAuth(array $data, string $botToken): bool
    {
        $checkHash = $data['hash'];
        unset($data['hash']);

        // build data_check_string
        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }
        $dataCheckString = implode("\n", $pairs);

        // per Telegram spec:
        $secretKey = hash('sha256', $botToken, true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($hash, $checkHash);
    }
}
