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

        // 1. Get all data, but separate the hash immediately
        $data = $request->all();
        $checkHash = $data['hash'] ?? '';

        // 2. Define the ONLY keys Telegram sends/signs
        // If the frontend sends "remember_me" or "_token", it breaks the hash unless we ignore it.
        $allowedKeys = ['auth_date', 'first_name', 'id', 'last_name', 'photo_url', 'username'];

        // 3. Prepare data for checking
        $dataToCheck = [];

        foreach ($allowedKeys as $key) {
            // Only add the key if it exists in the request AND is not null
            // Telegram omits keys (like username/last_name) if they are empty.
            if (isset($data[$key])) {
                $dataToCheck[] = $key . '=' . $data[$key];
            }
        }

        // 4. Sort alphabetically (Critical Step)
        sort($dataToCheck);

        // 5. Create the check string
        $stringToCheck = implode("\n", $dataToCheck);

        // 6. Generate Secret Key & Hash
        $secretKey = hash('sha256', $botToken, true);
        $hash = hash_hmac('sha256', $stringToCheck, $secretKey);

        // --- DEBUGGING BLOCK (Uncomment if still failing) ---
        // Log::info("Telegram String to Check: \n" . $stringToCheck);
        // Log::info("Generated Hash: " . $hash);
        // Log::info("Received Hash: " . $checkHash);
        // ----------------------------------------------------

        // 7. Compare Hash
        if (strcmp($hash, $checkHash) !== 0) {
            return response()->json([
                'error' => 'Data integrity check failed. Data is not from Telegram.',
                // 'debug_message' => 'Check Laravel logs for details' // only for dev
            ], 403);
        }

        // 8. Check Outdated (Replay Attack Protection)
        if ((time() - $data['auth_date']) > 86400) {
            return response()->json(['error' => 'Data is outdated'], 403);
        }

        // 9. Login or Register Logic
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

        // 10. Role Assignment (Only for new users)
        if ($user->wasRecentlyCreated) {
            try {
                $user->assignRole('agent');
            } catch (\Exception $e) {
                Log::error('Role "agent" does not exist: ' . $e->getMessage());
            }
        }

        // Optional: Update info for returning users
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
            'role' => $user->getRoleNames()->first() ?? 'No role assigned',
            'telegram' => [
                'id'       => $user->telegram_id,
                'username' => $user->telegram_username,
            ],
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }
}
