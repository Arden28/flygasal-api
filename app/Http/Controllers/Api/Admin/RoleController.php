<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

// The RoleController handles CRUD operations for roles and
// allows assigning/revoking permissions to roles.
// These operations are strictly for administrative users.
class RoleController extends Controller
{
    /**
     * Constructor for RoleController.
     * Applies 'manage-roles' permission middleware to all methods.
     */
    public function __construct()
    {
        $this->middleware('permission:manage-roles');
    }

    /**
     * Display a listing of the roles.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Load roles with permissions
        $roles = Role::with('permissions')->latest()->get();

        // Transform roles to desired structure
        $formattedRoles = $roles->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'status' => $role->status,
                'permissions' => $role->permissions->pluck('name')->toArray(),
            ];
        });

        // Return transformed roles
        return response()->json([
            'message' => 'Roles retrieved successfully.',
            'data' => $formattedRoles,
        ]);
    }


    /**
     * Store a newly created role in storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:roles,name',
                'permissions' => 'nullable|array', // Array of permission names
                'permissions.*' => 'string|exists:permissions,name', // Each permission name must exist
            ]);

            $role = Role::create(['name' => $validatedData['name']]);

            // Assign permissions if provided
            if (isset($validatedData['permissions'])) {
                $role->givePermissionTo($validatedData['permissions']);
            }

            $role->load('permissions'); // Reload role with permissions for response

            return response()->json([
                'message' => 'Role created successfully.',
                'data' => $role,
            ], 201);

        } catch (ValidationException $e) {
            Log::error('Role creation validation failed: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Role creation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to create role. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified role.
     *
     * @param Role $role
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Role $role)
    {
        $role->load('permissions'); // Eager load permissions
        return response()->json([
            'message' => 'Role retrieved successfully.',
            'data' => $role,
        ]);
    }

    /**
     * Update the specified role in storage.
     *
     * @param Request $request
     * @param Role $role
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Role $role)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'sometimes|required|string|max:255|unique:roles,name,' . $role->id,
                'permissions' => 'nullable|array',
                'permissions.*' => 'string|exists:permissions,name',
            ]);

            // Update role name if provided
            if (isset($validatedData['name'])) {
                $role->name = $validatedData['name'];
                $role->save();
            }

            // Sync permissions if provided
            if (isset($validatedData['permissions'])) {
                $role->syncPermissions($validatedData['permissions']); // Syncs permissions, detaching any not in the array
            }

            $role->load('permissions'); // Reload role with updated permissions for response

            return response()->json([
                'message' => 'Role updated successfully.',
                'data' => $role,
            ]);

        } catch (ValidationException $e) {
            Log::error('Role update validation failed: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Role update failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to update role. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified role from storage.
     *
     * @param Role $role
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Role $role)
    {
        // Prevent deletion of critical roles like 'admin' or 'customer' if they have users assigned
        // You might want to add more robust checks here, e.g., if role has users assigned.
        if (in_array($role->name, ['admin', 'customer'])) {
             return response()->json([
                'message' => 'Cannot delete default system roles.',
            ], 403);
        }

        try {
            $role->delete();
            return response()->json([
                'message' => 'Role deleted successfully.',
            ], 204);
        } catch (\Exception $e) {
            Log::error('Role deletion failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to delete role. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of all available permissions.
     * Useful for frontend to display options when assigning permissions to roles.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function permissions()
    {
        $permissions = Permission::all(['id', 'name']);
        return response()->json([
            'message' => 'Permissions retrieved successfully.',
            'data' => $permissions,
        ]);
    }
}
