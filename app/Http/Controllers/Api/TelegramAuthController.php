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
        // 1. HARDCODE NEW TOKEN HERE for testing (Replace "PASTE_NEW_TOKEN_HERE")
        // Make sure there are NO SPACES inside the quotes.
        $botToken = "PASTE_NEW_TOKEN_HERE";

        // 2. Get RAW Data (Bypasses Laravel's TrimStrings middleware)
        // This ensures if a name has a trailing space, we respect it.
        $content = $request->getContent();
        $data = json_decode($content, true);

        // Fallback to request->all() if raw content is empty
        if (!$data) {
            $data = $request->all();
        }

        $checkHash = $data['hash'] ?? '';

        // 3. Filter Keys & Handle Large IDs
        $allowedKeys = ['auth_date', 'first_name', 'id', 'last_name', 'photo_url', 'username'];
        $dataToCheck = [];

        foreach ($allowedKeys as $key) {
            if (isset($data[$key])) {
                $value = $data[$key];
                // Force ID to be a string to prevent float/scientific notation issues on 32-bit systems
                if ($key === 'id') {
                    $value = sprintf('%.0f', $value);
                }
                $dataToCheck[] = $key . '=' . $value;
            }
        }

        // 4. Sort & Create String
        sort($dataToCheck);
        $stringToCheck = implode("\n", $dataToCheck);

        // 5. Hash Calculation
        $secretKey = hash('sha256', trim($botToken), true);
        $hash = hash_hmac('sha256', $stringToCheck, $secretKey);

        // 6. Compare
        if (strcmp($hash, $checkHash) !== 0) {
            Log::error('Telegram Hash Mismatch', [
                'server_string' => $stringToCheck,
                'server_hash' => $hash,
                'received_hash' => $checkHash
            ]);
            return response()->json(['error' => 'Data integrity check failed.'], 403);
        }

        // 7. Check Date
        if ((time() - $data['auth_date']) > 86400) {
            return response()->json(['error' => 'Data is outdated'], 403);
        }

        // 8. Success Logic
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

        if ($user->wasRecentlyCreated && method_exists($user, 'assignRole')) {
            try { $user->assignRole('agent'); } catch (\Exception $e) {}
        }

        // Update existing user
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
    }
}
