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


}
