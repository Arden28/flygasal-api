<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role; // Import Role model for assigning default role

// The AuthController handles user authentication processes:
// registration, login, and logout using Laravel Sanctum for API token management.
class AuthController extends Controller
{
    /**
     * Register a new user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        try {
            // Validate incoming request data for user registration
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'phone_number' => 'required|string|max:15|unique:users',
                'password' => 'required|string|min:8|confirmed', // 'confirmed' checks for password_confirmation field
                'role' =>     'nullable|string|in:agent,user'
            ]);

            // Create the new user
            $user = User::create([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'phone_number' => $validatedData['phone_number'],
                'password' => Hash::make($validatedData['password']), // Hash the password
                'is_active' => false
            ]);

            // Assign the default 'customer' role to the new user
            // Ensure the 'customer' role exists (seeded via RolesAndPermissionsSeeder)
            $customerRole = Role::where('name', $validatedData['role'])->first();
            if ($customerRole) {
                $user->assignRole($customerRole);
            } else {
                // Log an error if the customer role is not found (should not happen if seeder ran)
                Log::error('Default "user" role not found during user registration.');
            }

            // Generate a new API token for the user using Laravel Sanctum
            $token = $user->createToken('auth_token')->plainTextToken;

            // Return success response with user data and token
            return response()->json([
                'message' => 'User registered successfully.',
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ], 201); // 201 Created

        } catch (ValidationException $e) {
            // Handle validation errors
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422); // 422 Unprocessable Entity
        } catch (Exception $e) {
            // Handle any other unexpected errors
            Log::error('User registration failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to register user. Please try again later.',
                'error' => $e->getMessage(), // For debugging, remove or simplify in production
            ], 500); // 500 Internal Server Error
        }
    }

    /**
     * Authenticate an existing user and issue an API token.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        try {
            // Validate incoming request data for login
            $validatedData = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
            ]);

            // Attempt to authenticate the user using email and password
            if (!Auth::attempt($validatedData)) {
                // If authentication fails, return an unauthorized response
                return response()->json([
                    'message' => 'Invalid credentials.',
                ], 401); // 401 Unauthorized
            }

            // Get the authenticated user
            $user = $request->user();

            // Revoke all existing tokens for this user to ensure only one active token per login
            // This is a common security practice, but can be adjusted based on needs (e.g., multiple device logins)
            $user->tokens()->delete();

            // Generate a new API token for the authenticated user
            $token = $user->createToken('auth_token')->plainTextToken;

            // Return success response with user data and new token
            return response()->json([
                'message' => 'Logged in successfully.',
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);

        } catch (ValidationException $e) {
            // Handle validation errors
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            // Handle any other unexpected errors
            Log::error('User login failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to log in. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Log out the authenticated user by revoking their current API token.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        // Delete the current API token used for the request
        $request->user()->currentAccessToken()->delete();

        // Return success message
        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
