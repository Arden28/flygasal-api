<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role; // Import Role model

// The UserController (Admin) handles CRUD operations for user accounts
// and also allows assigning/revoking roles to users.
// These operations are strictly for administrative users.
class UserController extends Controller
{
    /**
     * Constructor for UserController.
     * Applies 'manage-users' permission middleware to all methods.
     */
    public function __construct()
    {
        $this->middleware('permission:manage-users');
    }

    /**
     * Display a listing of the users.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Eager load roles for each user to avoid N+1 query problem
        $users = User::with('roles')->latest()->paginate(10);
        return response()->json([
            'message' => 'Users retrieved successfully.',
            'data' => $users,
        ]);
    }

    /**
     * Store a newly created user in storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email',
                'password' => 'required|string|min:8', // No 'confirmed' needed for admin creation
                'roles' => 'nullable|array', // Array of role names
                'roles.*' => 'string|exists:roles,name', // Each role name must exist in the roles table
            ]);

            $user = User::create([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'password' => Hash::make($validatedData['password']),
            ]);

            // Assign roles if provided
            if (isset($validatedData['roles'])) {
                $user->syncRoles($validatedData['roles']); // Syncs roles, detaching any not in the array
            } else {
                // Assign default customer role if no roles are specified
                $customerRole = Role::where('name', 'customer')->first();
                if ($customerRole) {
                    $user->assignRole($customerRole);
                }
            }

            $user->load('roles'); // Reload user with roles for response

            return response()->json([
                'message' => 'User created successfully.',
                'data' => $user,
            ], 201);

        } catch (ValidationException $e) {
            Log::error('User creation validation failed: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('User creation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to create user. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified user.
     *
     * @param User $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(User $user)
    {
        $user->load('roles'); // Eager load roles
        return response()->json([
            'message' => 'User retrieved successfully.',
            'data' => $user,
        ]);
    }

    /**
     * Update the specified user in storage.
     *
     * @param Request $request
     * @param User $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, User $user)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $user->id,
                'password' => 'nullable|string|min:8', // Password can be updated, or left null
                'roles' => 'nullable|array',
                'roles.*' => 'string|exists:roles,name',
            ]);

            // Update user details
            $user->fill($request->only(['name', 'email']));
            if (isset($validatedData['password'])) {
                $user->password = Hash::make($validatedData['password']);
            }
            $user->save();

            // Sync roles if provided
            if (isset($validatedData['roles'])) {
                $user->syncRoles($validatedData['roles']);
            }

            $user->load('roles'); // Reload user with updated roles for response

            return response()->json([
                'message' => 'User updated successfully.',
                'data' => $user,
            ]);

        } catch (ValidationException $e) {
            Log::error('User update validation failed: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('User update failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to update user. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified user from storage.
     *
     * @param User $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(User $user)
    {
        // Prevent deleting the currently authenticated user
        if (auth()->id() === $user->id) {
            return response()->json([
                'message' => 'Cannot delete your own user account.',
            ], 403); // 403 Forbidden
        }

        try {
            $user->delete();
            return response()->json([
                'message' => 'User deleted successfully.',
            ], 204);
        } catch (\Exception $e) {
            Log::error('User deletion failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to delete user. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
