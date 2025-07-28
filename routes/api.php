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

        // Role & Permission Management
        Route::apiResource('roles', RoleController::class);
        Route::get('permissions', [RoleController::class, 'permissions']); // Custom route to get all permissions
    });
});
