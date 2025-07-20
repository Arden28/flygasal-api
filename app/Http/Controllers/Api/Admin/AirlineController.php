<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Flights\Airline;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

// The AirlineController handles CRUD operations for Airline data.
// These operations are typically restricted to administrative users.
class AirlineController extends Controller
{
    /**
     * Constructor for AirlineController.
     * Applies 'manage-airlines' permission middleware to all methods.
     */
    public function __construct()
    {
        $this->middleware('permission:manage-airlines');
    }

    /**
     * Display a listing of the airlines.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $airlines = Airline::latest()->paginate(20);
        return response()->json([
            'message' => 'Airlines retrieved successfully.',
            'data' => $airlines,
        ]);
    }

    /**
     * Store a newly created airline in storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'iata_code' => 'required|string|size:2|unique:airlines,iata_code',
                'name' => 'required|string|max:255',
                'logo_url' => 'nullable|url|max:255',
            ]);

            $airline = Airline::create($validatedData);

            return response()->json([
                'message' => 'Airline created successfully.',
                'data' => $airline,
            ], 201);

        } catch (ValidationException $e) {
            Log::error('Airline creation validation failed: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Airline creation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to create airline. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified airline.
     *
     * @param Airline $airline
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Airline $airline)
    {
        return response()->json([
            'message' => 'Airline retrieved successfully.',
            'data' => $airline,
        ]);
    }

    /**
     * Update the specified airline in storage.
     *
     * @param Request $request
     * @param Airline $airline
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Airline $airline)
    {
        try {
            $validatedData = $request->validate([
                'iata_code' => 'sometimes|required|string|size:2|unique:airlines,iata_code,' . $airline->id,
                'name' => 'sometimes|required|string|max:255',
                'logo_url' => 'nullable|url|max:255',
            ]);

            $airline->update($validatedData);

            return response()->json([
                'message' => 'Airline updated successfully.',
                'data' => $airline,
            ]);

        } catch (ValidationException $e) {
            Log::error('Airline update validation failed: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Airline update failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to update airline. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified airline from storage.
     *
     * @param Airline $airline
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Airline $airline)
    {
        try {
            $airline->delete();
            return response()->json([
                'message' => 'Airline deleted successfully.',
            ], 204);
        } catch (\Exception $e) {
            Log::error('Airline deletion failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to delete airline. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
