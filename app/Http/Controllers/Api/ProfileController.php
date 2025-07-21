<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class ProfileController extends Controller
{

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
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        if (!$id) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        $user = User::find($id);

        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'nullable|string|email|max:255',
                'password' => 'nullable|string|min:8', // Password can be updated, or left null
                'roles' => 'nullable|array',
                'roles.*' => 'string|exists:roles,name',
            ]);

            Log::info($validatedData);

            // Update user details
            $user->update([
                'name' => $validatedData['name'] ?? $user->name,
                'email' => $validatedData['email'] ?? $user->email,
                'password' => isset($validatedData['password']) ? Hash::make($validatedData['password']) : $user->password,
            ]);

            // Sync roles if provided
            if (isset($validatedData['roles'])) {
                $user->syncRoles($validatedData['roles']);
            }

            $user->with(['roles']); // Reload user with updated roles for response

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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
            Log::error('User deletion failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to delete user. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
