<?php

use App\Http\Controllers\Api\Admin\AirlineController;
use App\Http\Controllers\Api\Admin\AirportController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\FlightController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\SettingsController;
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

        // Check if user is authenticated
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Build user data array with all fields
        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number ?? 'N/A',
            'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->toDateTimeString() : 'Not verified',
            'phone_verified_at' => $user->phone_verified_at ? $user->phone_verified_at->toDateTimeString() : 'Not verified',
            'phone_country_code' => $user->phone_country_code ?? 'N/A',
            'is_active' => $user->is_active,
            'wallet_balance' => number_format($user->wallet_balance, 2, '.', ''),
            'agency_name' => $user->agency_name ?? 'N/A',
            'agency_license' => $user->agency_license ?? 'N/A',
            'agency_country' => $user->agency_country ?? 'N/A',
            'agency_city' => $user->agency_city ?? 'N/A',
            'agency_address' => $user->agency_address ?? 'N/A',
            'agency_logo' => $user->agency_logo ? asset($user->agency_logo) : 'N/A', // Convert to full URL if exists
            'agency_currency' => $user->agency_currency ?? 'USD',
            'agency_markup' => number_format($user->agency_markup, 2, '.', ''),
            'role' => $user->getRoleNames()->first() ?? 'No role assigned', // Single role or fallback
            'booking_count' => $user->bookings()->count() ?? 0,
            // 'roles' => $user->getRoleNames()->toArray(), // Array of all roles
        ];

        return response()->json($userData);
    })->middleware('auth');

    // User Management
    Route::apiResource('profile', ProfileController::class)->except(['index', 'store']);

    Route::post('/logout', [AuthController::class, 'logout']);

    // Flight Search Routes
    // Requires authentication to search for flights.
    Route::post('/flights/search', [FlightController::class, 'search']);
    Route::post('/flights/precise-pricing', [FlightController::class, 'precisePricing']);

    Route::post('/flights/ancillary-pricing', [FlightController::class, 'ancillaryPricing']);

    // Booking Management Routes
    // Resource routes for bookings: index, store, show, destroy (using 'cancel' method)
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/flights/bookings', [BookingController::class, 'store']);
    Route::get('/bookings/{booking}', [BookingController::class, 'orderDetails']);
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']); // Custom route for cancellation
    // Route::get('/bookings/{booking}', [BookingController::class, 'show']);

    // Admin Routes - Protected by 'manage-xxx' permissions
    Route::prefix('admin')->group(function () {
        // Airport Management
        Route::apiResource('airports', AirportController::class); // Provides index, store, show, update, destroy

        // Airline Management
        Route::apiResource('airlines', AirlineController::class);

        // User Management (Admin specific)
        Route::apiResource('users', UserController::class); // Using alias for clarity
        Route::post('/users/{id}/approve', [UserController::class, 'approve']);

        // Settings
        Route::get('/settings', [SettingsController::class, 'getGeneralSettings']);
        Route::post('/settings', [SettingsController::class, 'updateGeneralSettings']);

        Route::get('/email-settings', [SettingsController::class, 'getEmailSettings']);
        Route::post('/email-settings', [SettingsController::class, 'updateEmailSettings']);

        Route::get('/pkfare-settings', [SettingsController::class, 'getPkfareSettings']);
        Route::post('/pkfare-settings', [SettingsController::class, 'updatePkfareSettings']);

        // Role & Permission Management
        Route::apiResource('roles', RoleController::class);
        Route::get('permissions', [RoleController::class, 'permissions']); // Custom route to get all permissions
    });
});
