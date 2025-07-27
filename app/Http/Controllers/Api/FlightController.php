<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PKfareService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// The FlightController handles all flight-related API requests,
// primarily focusing on searching for flights using the PKfareService.
class FlightController extends Controller
{
    protected PKfareService $pkfareService;

    /**
     * Constructor for FlightController.
     * Injects the PKfareService dependency.
     */
    public function __construct(PKfareService $pkfareService)
    {
        $this->pkfareService = $pkfareService;
    }

    /**
     * Search for flights based on user criteria.
     *
     * @param Request $request The incoming HTTP request containing search parameters.
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        try {
            // 1. Validate incoming request data
            $validatedData = $request->validate([
                'tripType' => 'nullable|string', //required
                'origin' => 'required|string|size:3', // IATA code, e.g., 'NBO'
                'destination' => 'required|string|size:3', // IATA code, e.g., 'JFK'
                'departureDate' => 'required|date_format:Y-m-d|after_or_equal:today', // YYYY-MM-DD
                'returnDate' => 'nullable|date_format:Y-m-d|after:departureDate|required_if:tripType,RoundTrip', // Required for round trip
                'adults' => 'required|integer|min:1',
                'children' => 'nullable|integer|min:0',
                'infants' => 'nullable|integer|min:0',
                'cabinType' => 'nullable|string' //in:Economy,Business,First,PremiumEconomy',
                // Add more validation rules as per PKfare API requirements (e.g., specific passenger ages)
            ]);
            // Log::info($validatedData['cabinType']);
            // 2. Prepare criteria for PKfareService
            $criteria = [
                'tripType' => $validatedData['tripType'] ?? 'Oneway',
                'origin' => strtoupper($validatedData['origin']), // Ensure IATA codes are uppercase
                'destination' => strtoupper($validatedData['destination']),
                'departureDate' => $validatedData['departureDate'],
                'returnDate' => $validatedData['returnDate'] ?? null,
                'adults' => $validatedData['adults'],
                'children' => $validatedData['children'] ?? 0,
                'infants' => $validatedData['infants'] ?? 0,
                'cabinClass' => $validatedData['cabinType'] ?? 'Economy',
            ];

            // 3. Call PKfareService to search for flights
            $flights = $this->pkfareService->searchFlights($criteria);

            // 4. Return successful response with flight data
            return response()->json([
                'message' => 'Flights retrieved successfully.',
                'errorMsg' => $flights['errorMsg'] ?? null,
                'errorCode' => $flights['errorCode'] ?? null,
                'data' => $flights['data'] ?? $flights,
            ]);

        } catch (ValidationException $e) {
            // Handle validation errors
            Log::error('Flight search validation failed: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422); // Unprocessable Entity
        } catch (Exception $e) {
            // Handle other exceptions (e.g., PKfare API errors)
            Log::error('Flight search failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to search flights. Please try again later.',
                'error' => $e->getMessage(), // For debugging, remove or simplify in production
            ], 500); // Internal Server Error
        }
    }

    /**
     * Perform precise pricing for a selected solution from search results.
     *
     * @param Request $request The incoming HTTP request containing search parameters.
     * @return \Illuminate\Http\JsonResponse
     */
    public function precisePricing(Request $request)
    {
        try {
            // 1. Validate incoming request data
            $validatedData = $request->validate([
                'solutionId' => 'required|string',
                'solutionKey' => 'nullable|string',
                'journeys' => 'nullable|array',
                'adults' => 'required|integer|min:1',
                'children' => 'nullable|integer|min:0',
                'infants' => 'nullable|integer|min:0',
                'cabinType' => 'nullable|string',
                'tag' => 'nullable|string',
            ]);

            // 2. Prepare criteria for PKfareService
            $criteria = [
                'solutionId' => $validatedData['solutionId'],
                'solutionKey' => $validatedData['solutionKey'],
                'journeys' => $validatedData['journeys'] ?? [],
                'adults' => $validatedData['adults'],
                'children' => $validatedData['children'] ?? 0,
                'infants' => $validatedData['infants'] ?? 0,
                'cabin' => $validatedData['cabinType'] ?? 'Economy',
                'tag' => $validatedData['tag'] ?? ''
            ];

            // 3. Call PKfareService to precise pricing
            $precisePricing = $this->pkfareService->getPrecisePricing($criteria);

            // 4. Return successful response with flight data
            return response()->json([
                'message' => 'Precise pricing retrieved successfully.',
                'errorMsg' => $precisePricing['errorMsg'] ?? null,
                'errorCode' => $precisePricing['errorCode'] ?? null,
                'data' => $precisePricing['data'] ?? $precisePricing,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Precise pricing failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'Failed to get precise pricing.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // TODO: Add methods for retrieving specific flight details if PKfare offers such an endpoint
    // public function show($flightId) { ... }
}

