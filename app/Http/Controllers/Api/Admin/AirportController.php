<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Flights\Airport;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

// The AirportController handles CRUD operations for Airport data.
// These operations are typically restricted to administrative users.
class AirportController extends Controller
{
    /**
     * Constructor for AirportController.
     * Applies 'manage-airports' permission middleware to all methods.
     */
    public function __construct()
    {
        $this->middleware('permission:manage-airports');
    }

    /**
     * Display a listing of the airports.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $airports = Airport::latest()->paginate(20); // Paginate results for efficiency
        return response()->json([
            'message' => 'Airports retrieved successfully.',
            'data' => $airports,
        ]);
    }

    /**
     * Store a newly created airport in storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'iata_code' => 'required|string|size:3|unique:airports,iata_code',
                'name' => 'required|string|max:255',
                'city' => 'required|string|max:255',
                'country' => 'required|string|max:255',
                'country_code' => 'required|string|size:2',
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'timezone' => 'nullable|string|max:255',
            ]);

            $airport = Airport::create($validatedData);

            return response()->json([
                'message' => 'Airport created successfully.',
                'data' => $airport,
            ], 201); // 201 Created

        } catch (ValidationException $e) {
            Log::error('Airport creation validation failed: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Airport creation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to create airport. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified airport.
     *
     * @param Airport $airport
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Airport $airport)
    {
        return response()->json([
            'message' => 'Airport retrieved successfully.',
            'data' => $airport,
        ]);
    }

    /**
     * Update the specified airport in storage.
     *
     * @param Request $request
     * @param Airport $airport
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Airport $airport)
    {
        try {
            $validatedData = $request->validate([
                'iata_code' => 'sometimes|required|string|size:3|unique:airports,iata_code,' . $airport->id,
                'name' => 'sometimes|required|string|max:255',
                'city' => 'sometimes|required|string|max:255',
                'country' => 'sometimes|required|string|max:255',
                'country_code' => 'sometimes|required|string|size:2',
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'timezone' => 'nullable|string|max:255',
            ]);

            $airport->update($validatedData);

            return response()->json([
                'message' => 'Airport updated successfully.',
                'data' => $airport,
            ]);

        } catch (ValidationException $e) {
            Log::error('Airport update validation failed: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Airport update failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to update airport. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified airport from storage.
     *
     * @param Airport $airport
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Airport $airport)
    {
        try {
            $airport->delete();
            return response()->json([
                'message' => 'Airport deleted successfully.',
            ], 204); // 204 No Content
        } catch (\Exception $e) {
            Log::error('Airport deletion failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to delete airport. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
