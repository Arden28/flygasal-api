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
        // 1. Get Token (Try Config -> Env -> Hardcoded Fallback)
        // We use config() because env() returns null if config is cached.
        $botToken = config('services.telegram.bot_token') ?? env('TELEGRAM_BOT_TOKEN');

        // TEMPORARY DEBUG FALLBACK:
        // If the above are null, use the token you gave me to prove it's a config issue.
        if (!$botToken) {
            $botToken = "8099340852:AAGrm3O1SwQCQjc-LsCWlo_CvUNCW_K3pg4";
            Log::warning('Using hardcoded fallback token. Check your .env or config cache!');
        }

        // 2. Get Data
        $data = $request->all();
        $checkHash = $data['hash'] ?? '';

        // 3. Filter Keys
        $allowedKeys = ['auth_date', 'first_name', 'id', 'last_name', 'photo_url', 'username'];
        $dataToCheck = [];

        foreach ($allowedKeys as $key) {
            if (isset($data[$key])) {
                $dataToCheck[] = $key . '=' . $data[$key];
            }
        }

        // 4. Sort Alphabetically
        sort($dataToCheck);

        // 5. Create String (Implode with NEWLINE character)
        $stringToCheck = implode("\n", $dataToCheck);

        // 6. Generate Secret Key & Hash
        // The secret key is the SHA256 hash of the bot token (binary)
        $secretKey = hash('sha256', $botToken, true);
        $hash = hash_hmac('sha256', $stringToCheck, $secretKey);

        // 7. Compare
        if (strcmp($hash, $checkHash) !== 0) {
            Log::error('Telegram Hash Mismatch!', [
                'computed_hash' => $hash,
                'received_hash' => $checkHash,
                'used_token_substring' => substr($botToken, 0, 5) . '...', // Check if token was loaded
            ]);

            return response()->json([
                'error' => 'Data integrity check failed.',
                'hint' => 'Check laravel.log for "used_token_substring" to see if token is loaded.'
            ], 403);
        }

        // 8. Check Date (24 hours)
        if ((time() - $data['auth_date']) > 86400) {
            return response()->json(['error' => 'Data is outdated'], 403);
        }

        // 9. Success: Login/Register
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

            // Assign Role if new
            if ($user->wasRecentlyCreated && method_exists($user, 'assignRole')) {
                $user->assignRole('agent');
            }

            // Update profile if returning
            if (!$user->wasRecentlyCreated) {
                 $user->update([
                    'name' => $data['first_name'] . ' ' . ($data['last_name'] ?? ''),
                    'photo_url' => $data['photo_url'] ?? $user->photo_url,
                    'telegram_username' => $data['username'] ?? $user->telegram_username,
                 ]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'ok',
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);

        } catch (\Exception $e) {
            Log::error('DB Error: ' . $e->getMessage());
            return response()->json(['error' => 'Database error'], 500);
        }
    }
}
