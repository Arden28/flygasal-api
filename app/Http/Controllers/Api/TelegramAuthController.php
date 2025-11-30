<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramAuthController extends Controller
{
    public function login(Request $request)
    {
        // 1. Debug: Check if Token is loaded
        $botToken = env('TELEGRAM_BOT_TOKEN');
        if (empty($botToken)) {
            Log::error('TELEGRAM_BOT_TOKEN is missing or empty in .env');
            return response()->json(['error' => 'Server Configuration Error'], 500);
        }

        // 2. Get Data
        $data = $request->all();
        $checkHash = $data['hash'] ?? '';

        // Log the raw incoming data to see what React sent
        Log::info('--- TELEGRAM LOGIN ATTEMPT ---');
        Log::info('Incoming Data:', $data);

        // 3. Filter Keys
        $allowedKeys = ['auth_date', 'first_name', 'id', 'last_name', 'photo_url', 'username'];
        $dataToCheck = [];

        foreach ($allowedKeys as $key) {
            if (isset($data[$key])) {
                $dataToCheck[] = $key . '=' . $data[$key];
            }
        }

        // 4. Sort
        sort($dataToCheck);

        // 5. Create String
        $stringToCheck = implode("\n", $dataToCheck);

        // 6. Hash
        $secretKey = hash('sha256', $botToken, true);
        $hash = hash_hmac('sha256', $stringToCheck, $secretKey);

        // --- CRITICAL DEBUGGING LOGS ---
        Log::info("Computed String to Check:\n" . $stringToCheck);
        Log::info("Computed Hash: " . $hash);
        Log::info("Received Hash: " . $checkHash);
        // -------------------------------

        // 7. Compare
        if (strcmp($hash, $checkHash) !== 0) {
            Log::error('Telegram Hash Mismatch!');
            return response()->json([
                'error' => 'Data integrity check failed.',
                'server_string' => $stringToCheck, // Return this temporarily to see it in Network tab
                'server_hash' => $hash,
                'received_hash' => $checkHash
            ], 403);
        }

        // 8. Check Date
        if ((time() - $data['auth_date']) > 86400) {
            Log::error('Telegram Data Outdated');
            return response()->json(['error' => 'Data is outdated'], 403);
        }

        // 9. Success Logic
        try {
            $user = User::firstOrCreate(
                ['telegram_id' => $data['id']],
                [
                    'name' => $data['first_name'] . ' ' . ($data['last_name'] ?? ''),
                    'telegram_username' => $data['username'] ?? null,
                    'photo_url' => $data['photo_url'] ?? null,
                    'password' => Hash::make(Str::random(16)),
                    'email' => $data['id'] . '@flygasal.telegram.bot'
                ]
            );

            if ($user->wasRecentlyCreated) {
                // Ensure you have Spatie Permission installed, otherwise comment this out
                if (method_exists($user, 'assignRole')) {
                    $user->assignRole('agent');
                }
            }

            // Update existing user data
            if (!$user->wasRecentlyCreated) {
                 $user->update([
                    'name' => $data['first_name'] . ' ' . ($data['last_name'] ?? ''),
                    'photo_url' => $data['photo_url'] ?? $user->photo_url,
                    'telegram_username' => $data['username'] ?? $user->telegram_username,
                 ]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            Log::info('Telegram Login Success: User ID ' . $user->id);

            return response()->json([
                'status' => 'ok',
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);

        } catch (\Exception $e) {
            Log::error('DB Error during Telegram Login: ' . $e->getMessage());
            return response()->json(['error' => 'Database error'], 500);
        }
    }
}
