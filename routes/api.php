<?php

use App\Http\Controllers\Api\Admin\AirlineController;
use App\Http\Controllers\Api\Admin\AirportController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\FlightController;
use App\Http\Controllers\Api\ProfileController;
use App\Models\Flights\Airport;
use App\Models\Settings\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes (no authentication required)
// These routes can be accessed by anyone.
Route::get('/status', function () {
    return response()->json(['message' => 'API is up and running!', 'version' => '1.0.0']);
});

// Authentication Routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Country Routes
Route::get('/proxy/countries', function () {
    $response = Http::get('https://apicountries.com/countries');
    return $response->json();
});

// Airport Routes
Route::get('/proxy/airports', function (Request $request) {

    return Airport::where('name', 'LIKE', "%{$request->q}%")
        ->orWhere('iata', 'LIKE', "%{$request->q}%")
        ->orWhere('city', 'LIKE', "%{$request->q}%")
        ->limit(10)
        ->get();
});


// Authenticated routes (requires Sanctum token)
// Routes within this group will require a valid API token for access.
Route::middleware('auth:sanctum')->group(function () {

    // Get authenticated user's details
    Route::get('/user', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'role' => $user->getRoleNames()->first(), // 'admin'
        ]);
    });

    // User Management
    Route::apiResource('profile', ProfileController::class)->except(['index', 'store']);

    Route::post('/logout', [AuthController::class, 'logout']);

    // Flight Search Routes
    // Requires authentication to search for flights.
    Route::post('/flights/search', [FlightController::class, 'search']);
    Route::post('/flights/precise-pricing', [FlightController::class, 'precisePricing']);

    // Booking Management Routes
    // Resource routes for bookings: index, store, show, destroy (using 'cancel' method)
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']); // Custom route for cancellation

    // Admin Routes - Protected by 'manage-xxx' permissions
    Route::prefix('admin')->group(function () {
        // Airport Management
        Route::apiResource('airports', AirportController::class); // Provides index, store, show, update, destroy

        // Airline Management
        Route::apiResource('airlines', AirlineController::class);

        // User Management (Admin specific)
        Route::apiResource('users', UserController::class); // Using alias for clarity
        Route::post('/users/{id}/approve', [UserController::class, 'approve']);

        // General Settings
        Route::get('/settings', function () {
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
        });

        // Endpoint to get email-related settings from the .env file
        Route::get('/email-settings', function () {
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
        });

        // Endpoint to update email-related settings in the .env file
        Route::post('/email-settings', function (Request $request) {
            // Validate the incoming request data for required email settings
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

            // Get the path to the .env file
            $envPath = base_path('.env');
            // Read the current contents of the .env file (if it exists)
            $envContent = file_exists($envPath) ? file_get_contents($envPath) : '';

            // For each validated key, update its value or add it if not present
            foreach ($validated as $key => $value) {
            // Prepare the pattern to match the line (handles empty or existing values)
            $pattern = "/^{$key}=.*$/m";
            // Prepare the new line with the value (always quoted)
            $line = "{$key}=\"{$value}\"";
            if (preg_match($pattern, $envContent)) {
                // If the key exists, replace the line
                $envContent = preg_replace($pattern, $line, $envContent);
            } else {
                // If the key does not exist, append it to the end
                $envContent .= (substr($envContent, -1) === "\n" ? '' : "\n") . $line . "\n";
            }
            }

            // Write the updated content back to the .env file
            file_put_contents($envPath, $envContent);

            // Optionally reload Laravel config cache to apply new settings immediately
            Artisan::call('config:clear');
            Artisan::call('config:cache');

            // Return a success response
            return response()->json(['message' => 'Email settings updated successfully.']);
        });

            // Endpoint to get PKFare-related settings from the .env file
            Route::get('/pkfare-settings', function () {
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
            });

            // Endpoint to update PKFare-related settings in the .env file
            Route::post('/pkfare-settings', function (Request $request) {
                // Validate the incoming request data for required PKFare settings
                $validated = $request->validate([
                'PKFARE_API_BASE_URL' => 'required|string',
                'PKFARE_PARTNER_ID' => 'required|string',
                'PKFARE_PARTNER_KEY' => 'required|string',
                ]);

                // Get the path to the .env file
                $envPath = base_path('.env');
                // Read the current contents of the .env file (if it exists)
                $envContent = file_exists($envPath) ? file_get_contents($envPath) : '';

                // For each validated key, update its value or add it if not present
                foreach ($validated as $key => $value) {
                // Prepare the pattern to match the line (handles empty or existing values)
                $pattern = "/^{$key}=.*$/m";
                // Prepare the new line with the value (always quoted)
                $line = "{$key}=\"{$value}\"";
                if (preg_match($pattern, $envContent)) {
                    // If the key exists, replace the line
                    $envContent = preg_replace($pattern, $line, $envContent);
                } else {
                    // If the key does not exist, append it to the end
                    $envContent .= (substr($envContent, -1) === "\n" ? '' : "\n") . $line . "\n";
                }
                }

                // Write the updated content back to the .env file
                file_put_contents($envPath, $envContent);

                // Optionally reload Laravel config cache to apply new settings immediately
                Artisan::call('config:clear');
                Artisan::call('config:cache');

                // Return a success response
                return response()->json(['message' => 'PKFare settings updated successfully.']);
            });

        // Role & Permission Management
        Route::apiResource('roles', RoleController::class);
        Route::get('permissions', [RoleController::class, 'permissions']); // Custom route to get all permissions
    });
});
