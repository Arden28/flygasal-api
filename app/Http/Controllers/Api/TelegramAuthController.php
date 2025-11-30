<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class TelegramAuthController extends Controller
{
public function login(Request $request)
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $data = $request->all();

        // 1. Separate the hash
        $checkHash = $data['hash'];
        unset($data['hash']);

        // 2. Sort alphabetical
        $dataToCheck = [];
        foreach ($data as $key => $value) {
            $dataToCheck[] = $key . '=' . $value;
        }
        sort($dataToCheck);

        // 3. Verify string
        $stringToCheck = implode("\n", $dataToCheck);

        // 4. Hash
        $secretKey = hash('sha256', $botToken, true);
        $hash = hash_hmac('sha256', $stringToCheck, $secretKey);

        // 5. Compare
        if (strcmp($hash, $checkHash) !== 0) {
            return response()->json(['error' => 'Data is NOT from Telegram'], 403);
        }

        // 6. Check outdated
        if ((time() - $data['auth_date']) > 86400) {
            return response()->json(['error' => 'Data is outdated'], 403);
        }

        // 7. Login or Register
        // firstOrCreate prevents recreating the user if telegram_id exists
        $user = User::firstOrCreate(
            ['telegram_id' => $data['id']],
            [
                'name' => $data['first_name'] . ' ' . ($data['last_name'] ?? ''),
                'telegram_username' => $data['username'] ?? null,
                'photo_url' => $data['photo_url'] ?? null,
                'password' => Hash::make(Str::random(16)),
                'email' => $data['id'] . '@flygasal.telegram.bot' // Fallback email if your DB requires it
            ]
        );

        // 8. Assign Role ONLY if the user is new
        if ($user->wasRecentlyCreated) {
            // Using Spatie's assignRole with a string is safer/faster
            try {
                $user->assignRole('agent');
            } catch (\Exception $e) {
                Log::error('Role "agent" does not exist: ' . $e->getMessage());
            }
        }

        // Optional: If user exists (not new), update their info (e.g. they changed their profile pic)
        if (!$user->wasRecentlyCreated) {
            $user->update([
                'name' => $data['first_name'] . ' ' . ($data['last_name'] ?? ''),
                'photo_url' => $data['photo_url'] ?? $user->photo_url,
                'telegram_username' => $data['username'] ?? $user->telegram_username,
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        // return response()->json(['token' => $token, 'user' => $user]);

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

    // public function login(Request $request)
    // {
    //     $data = $request->all();

    //     // 1) Basic presence checks
    //     foreach (['id','auth_date','hash'] as $k) {
    //         if (!isset($data[$k])) {
    //             return response()->json(['status' => 'error', 'error' => 'Invalid Telegram payload'], 400);
    //         }
    //     }

    //     // 2) Verify signature
    //     $botToken = config('services.telegram.bot_token'); // put your bot token in config/services.php
    //     if (!$this->verifyTelegramAuth($data, $botToken)) {
    //         return response()->json(['status' => 'error', 'error' => 'Signature verification failed'], 401);
    //     }

    //     // 3) Optional: check auth_date freshness (e.g. 1 day)
    //     $authDate = Carbon::createFromTimestamp((int)$data['auth_date']);
    //     if ($authDate->lt(now()->subDay())) {
    //         return response()->json(['status' => 'error', 'error' => 'Telegram login expired'], 401);
    //     }

    //     // 4) Find or link a user
    //     // Strategy A: only allow pre-approved/created accounts to login:
    //     $user = User::where('telegram_id', $data['id'])->first();

    //     if (!$user) {
    //         // try match by username/email if you want to auto-link
    //         if (!empty($data['username'])) {
    //             $user = User::where('telegram_username', $data['username'])->first();
    //         }

    //         if (!$user) {
    //             // No linked account
    //             return response()->json(['status' => 'no_account'], 200);
    //         }

    //         // Link now (if you allow auto-linking)
    //         $user->telegram_id = $data['id'];
    //         $user->telegram_username = $data['username'] ?? $user->telegram_username;
    //         $user->save();
    //     }

    //     // Example: require approval
    //     if (method_exists($user, 'isApproved') && !$user->isApproved()) {
    //         return response()->json(['status' => 'pending'], 200);
    //     }

    //     // 5) Create session / token
    //     // If you use Sanctum SPA (cookie-based), you can log the user into this API request:
    //     auth()->login($user); // sets the session if you’re using web guard on API (ensure sanctum/cors setup)
    //     // Or issue a token:
    //     $token = $user->createToken('tg')->plainTextToken;

    //     return response()->json([
    //         'status' => 'ok',
    //         'user' => $user,
    //         'role' => $user->getRoleNames()->first() ?? 'No role assigned', // Single role or fallback
    //         'telegram' => [
    //             'id'       => $user->telegram_id,
    //             'username' => $user->telegram_username,
    //         ],
    //         'access_token' => $token,
    //         'token_type' => 'Bearer',
    //     ]);
    // }

    // private function verifyTelegramAuth(array $data, string $botToken): bool
    // {
    //     if (!$botToken) return false;

    //     $checkHash = (string)($data['hash'] ?? '');
    //     unset($data['hash']);

    //     // Keep only scalar fields (Telegram sends scalars for login)
    //     $clean = [];
    //     foreach ($data as $k => $v) {
    //         if (is_scalar($v)) $clean[$k] = (string)$v;
    //     }

    //     ksort($clean); // sort by keys
    //     $pairs = [];
    //     foreach ($clean as $k => $v) {
    //         $pairs[] = "{$k}={$v}";
    //     }
    //     $dataCheckString = implode("\n", $pairs);

    //     $secretKey = hash('sha256', $botToken, true);        // binary key
    //     $hash      = hash_hmac('sha256', $dataCheckString, $secretKey);

    //     return hash_equals($hash, $checkHash);
    // }

}
