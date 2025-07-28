<?php

namespace App\Http\Controllers;

use App\Models\Settings\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SettingsController extends Controller
{
    /**
     * Get general site settings from DB
     */
    public function getGeneralSettings()
    {
        $settings = Setting::find(1);

        if (!$settings) {
            return response()->json(['error' => 'Settings not found.'], 404);
        }

        return response()->json([
            'site_name' => $settings->site_name,
            'default_currency' => $settings->default_currency,
            'timezone' => $settings->timezone,
            'language' => $settings->language,
            'login_attemps' => $settings->login_attemps,
            'email_notification' => $settings->email_notification,
            'sms_notification' => $settings->sms_notification,
            'booking_confirmation_email' => $settings->booking_confirmation_email,
            'booking_confirmation_sms' => $settings->booking_confirmation_sms,
        ]);
    }

    /**
     * Update general site settings in DB
     */
    public function updateGeneralSettings(Request $request)
    {
        $validated = $request->validate([
            'site_name' => 'required|string',
            'default_currency' => 'required|string',
            'timezone' => 'required|string',
            'language' => 'required|string',
            'login_attemps' => 'nullable|integer',
            'email_notification' => 'nullable|boolean',
            'sms_notification' => 'nullable|boolean',
            'booking_confirmation_email' => 'nullable|boolean',
            'booking_confirmation_sms' => 'nullable|boolean',
        ]);

        $settings = Setting::find(1);

        if (!$settings) {
            return response()->json(['error' => 'Settings not found.'], 404);
        }

        $settings->update($validated);

        return response()->json(['message' => 'Settings updated successfully.']);
    }

    /**
     * Get email-related settings from .env
     */
    public function getEmailSettings()
    {
        $keys = [
            'MAIL_MAILER',
            'MAIL_HOST',
            'MAIL_PORT',
            'MAIL_USERNAME',
            'MAIL_PASSWORD',
            'MAIL_ENCRYPTION',
            'MAIL_FROM_ADDRESS',
            'MAIL_FROM_NAME',
        ];

        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = env($key);
        }

        return response()->json($settings);
    }

    /**
     * Update email-related settings in .env
     */
    public function updateEmailSettings(Request $request)
    {
        $validated = $request->validate([
            'MAIL_MAILER' => 'required|string',
            'MAIL_HOST' => 'required|string',
            'MAIL_PORT' => 'required|numeric',
            'MAIL_USERNAME' => 'required|string',
            'MAIL_PASSWORD' => 'required|string',
            'MAIL_ENCRYPTION' => 'nullable|string',
            'MAIL_FROM_ADDRESS' => 'required|email',
            'MAIL_FROM_NAME' => 'required|string',
        ]);

        $this->updateEnvKeys($validated);

        Artisan::call('config:clear');
        Artisan::call('config:cache');

        return response()->json(['message' => 'Email settings updated successfully.']);
    }

    /**
     * Get PKFare-related settings from .env
     */
    public function getPkfareSettings()
    {
        $keys = [
            'PKFARE_API_BASE_URL',
            'PKFARE_PARTNER_ID',
            'PKFARE_PARTNER_KEY',
        ];

        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = env($key);
        }

        return response()->json($settings);
    }

    /**
     * Update PKFare-related settings in .env
     */
    public function updatePkfareSettings(Request $request)
    {
        $validated = $request->validate([
            'PKFARE_API_BASE_URL' => 'required|string',
            'PKFARE_PARTNER_ID' => 'required|string',
            'PKFARE_PARTNER_KEY' => 'required|string',
        ]);

        $this->updateEnvKeys($validated);

        Artisan::call('config:clear');
        Artisan::call('config:cache');

        return response()->json(['message' => 'PKFare settings updated successfully.']);
    }

    /**
     * Update .env file with new key-value pairs
     */
    protected function updateEnvKeys(array $data)
    {
        $envPath = base_path('.env');
        $envContent = file_exists($envPath) ? file_get_contents($envPath) : '';

        foreach ($data as $key => $value) {
            $pattern = "/^{$key}=.*$/m";
            $line = "{$key}=\"{$value}\"";
            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, $line, $envContent);
            } else {
                $envContent .= (substr($envContent, -1) === "\n" ? '' : "\n") . $line . "\n";
            }
        }

        file_put_contents($envPath, $envContent);
    }
}
