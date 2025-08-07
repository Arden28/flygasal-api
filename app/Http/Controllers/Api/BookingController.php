<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flights\Booking;
use App\Models\Flights\Transaction;
use App\Services\PKfareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// The BookingController handles the creation, viewing, and cancellation of flight bookings.
// It interacts with the PKfareService for external API calls and
// stores booking and transaction data in the local database.
class BookingController extends Controller
{
    protected PKfareService $pkfareService;

    /**
     * Constructor for BookingController.
     * Injects the PKfareService dependency.
     */
    public function __construct(PKfareService $pkfareService)
    {
        $this->pkfareService = $pkfareService;

        // Apply middleware for authorization
        // Only authenticated users can access these methods.
        // 'create-booking' permission for store, 'view-bookings' for index/show, 'cancel-booking' for destroy.
        // $this->middleware('permission:create-booking', ['only' => ['store']]);
        // $this->middleware('permission:view-bookings', ['only' => ['index', 'show']]);
        // $this->middleware('permission:cancel-booking', ['only' => ['cancel']]);
    }

    /**
     * Display a listing of the user's bookings.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // For customers, show only their own bookings.
        // For admins/agents, potentially show all or filtered bookings based on permissions.
        if ($request->user()->hasRole('agent')) {
            $bookings = $request->user()->bookings()->with('transactions')->latest()->paginate(10);
        } else {
            // Admins/Agents can view all bookings
            $bookings = Booking::with('transactions')->latest()->paginate(10);
        }

        return response()->json([
            'message' => 'Bookings retrieved successfully.',
            'data' => $bookings,
        ]);
    }

    /**
     * Store a newly created booking in storage.
     * This involves calling the PKfare API and saving to the local DB.
     *
     * @param Request $request The incoming HTTP request with booking details.
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Use a database transaction to ensure atomicity.
        // If PKfare API call fails or local save fails, everything is rolled back.
        DB::beginTransaction();
        try {
            // 1. Validate incoming request data for booking
            $validatedData = $request->validate([
                'selectedFlight' => 'required|array', // The flight object returned from PKfare search
                // 'selectedFlight.fareSourceCode' => 'nullable|string', // A critical identifier for PKfare
                'solutionId' => 'required|string', // A critical identifier for PKfare
                // ... other necessary flight details from selectedFlight
                'passengers' => 'required|array|min:1',
                'passengers.*.firstName' => 'required|string|max:255',
                'passengers.*.lastName' => 'required|string|max:255',
                'passengers.*.type' => 'required|string|in:ADT,CHD,INF', // Adult, Child, Infant
                'passengers.*.dob' => 'required|date_format:Y-m-d',
                'passengers.*.gender' => 'required|string|in:Male,Female',
                'passengers.*.passportNumber' => 'nullable|string|max:255',
                'passengers.*.passportExpiry' => 'nullable|date_format:Y-m-d|after:today',
                'contactName' => 'required|string|max:155',
                'contactEmail' => 'required|email|max:255',
                'contactPhone' => 'required|string|max:20',
                'totalPrice' => 'required|numeric|min:0',
                'currency' => 'required|string|size:3',
                'agent_fee' => 'nullable'
            ]);

            $selectedFlight = $validatedData['selectedFlight'];
            // Log::info('Selected Flight: ', $validatedData['selectedFlight']);

            // 2. Prepare booking details for PKfareService
            $pkfareBookingDetails = [
                'selectedFlight' => $selectedFlight,
                'solutionId' => $validatedData['solutionId'],
                'passengers' => $validatedData['passengers'],
                'contactInfo' => [
                    'name' => $validatedData['contactName'],
                    'email' => $validatedData['contactEmail'],
                    'phone' => $validatedData['contactPhone'],
                ],
                // PKfare might require specific payment details here.
                // This is a placeholder for actual payment integration.
                // 'paymentMethod' => 'credit_card',
                // 'paymentToken' => $validatedData['paymentToken'],
            ];

            // 3. Call PKfare API
            // This is a critical step where the actual booking is made with the airline via PKfare.
            $pkfareResponse = $this->pkfareService->createBooking($pkfareBookingDetails);

            Log::info('PKFare Response: ', $pkfareResponse);

            // Error map (put at top or in a helper)
            $errorMessages = [
                'S001' => 'System error.',
                'B002' => 'Partner ID does not exist.',
                'B003' => 'Invalid signature. Please contact support.',
                'B035' => 'Too many requests. Please try again later.',
                'P001' => 'Invalid input data.',
                'P002' => 'Missing required fields.',
                'P006' => 'Invalid parameters. Please review your data.',
                '0307' => 'Seats are no longer available.',
                'B005' => 'Pricing expired. Please search again.',
                'B007' => 'Flight segment is no longer valid.',
                'B008' => 'Flight changed. Please reselect.',
                'B011' => 'Fare is unavailable. Try another flight.',
                'B017' => 'Price has changed. Please confirm again.',
                'B029' => 'Duplicate reservation found. Please use the previous order or cancel it.',
                'B068' => 'Flight segment mismatch. Please reselect your flights.',
                // Add more as needed...
            ];

            // 4. Check API response
            $errorCode = $pkfareResponse['errorCode'] ?? null;

            if ($errorCode !== '0') {
                $message = $errorMessages[$errorCode] ?? ($pkfareResponse['errorMsg'] ?? 'Booking failed.');

                return response()->json([
                    'success' => false,
                    'code' => $errorCode,
                    'message' => $message,
                ], 400); // Bad request or adjust to suit
            }

            // 5. Save booking details to local database
            $totalAmount = $pkfareResponse['data']['solution']['adtFare'] + $pkfareResponse['data']['solution']['adtTax'] + $pkfareResponse['data']['solution']['chdFare'] + $pkfareResponse['data']['solution']['chdTax'];

            $bookingData = [
                'order_num'        => $pkfareResponse['data']['orderNum'],
                'pnr'              => $pkfareResponse['data']['pnr'],
                'solution_id'        => $pkfareResponse['data']['solution']['solutionId'],
                'fare_type'        => $pkfareResponse['data']['solution']['fareType'],
                'currency'         => $pkfareResponse['data']['solution']['currency'],
                'adt_fare'         => $pkfareResponse['data']['solution']['adtFare'],
                'adt_tax'          => $pkfareResponse['data']['solution']['adtTax'],
                'chd_fare'         => $pkfareResponse['data']['solution']['chdFare'],
                'chd_tax'          => $pkfareResponse['data']['solution']['chdTax'],
                'infants'          => $pkfareResponse['data']['solution']['infants'],
                'adults'           => $pkfareResponse['data']['solution']['adults'],
                'children'         => $pkfareResponse['data']['solution']['children'],
                'plating_carrier'  => $pkfareResponse['data']['solution']['platingCarrier'],
                'baggage_info'     => json_encode($pkfareResponse['data']['solution']['baggageMap']),
                'flights'          => json_encode($pkfareResponse['data']['flights']),
                'segments'         => json_encode($pkfareResponse['data']['segments']),
                'passengers'       => $validatedData['passengers'],
                'agent_fee'     => $validatedData['agent_fee'] ?? 0,
                'total_amount'     => $totalAmount,
                'contact_name' => $validatedData['contactName'],
                'contact_email' => $validatedData['contactEmail'],
                'contact_phone' => $validatedData['contactPhone'],
                'booking_date' => now(),
            ];
            $booking = Booking::create($bookingData);

            DB::commit(); // Commit the database transaction

            Log::info("BookingDetails: {$booking->order_num}");

            return response()->json([
                'message' => 'Booking created successfully.',
                'errorMsg' => 'Booking created successfully',
                'errorCode' => 0,
                'booking' => $booking,
            ], 201); // Created

        } catch (ValidationException $e) {
            DB::rollBack(); // Rollback if validation fails
            Log::error('Booking creation validation failed: ' . json_encode($e->errors()));
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback if any other exception occurs
            Log::error('Booking creation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to create booking. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified booking.
     *
     * @param Booking $booking The booking instance retrieved by route model binding.
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Booking $booking)
    {
        // Authorization check: A user can only view their own bookings unless they are admin/agent.
        if (auth()->user()->hasRole('customer') && $booking->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized to view this booking.'], 403);
        }

        // Eager load transactions related to the booking
        $booking->load('transactions');

        return response()->json([
            'message' => 'Booking retrieved successfully.',
            'data' => $booking,
        ]);
    }

    /**
     * Cancel the specified booking.
     *
     * @param Request $request
     * @param Booking $booking The booking instance retrieved by route model binding.
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel(Request $request, Booking $booking)
    {
        // Authorization check: A user can only cancel their own bookings unless they are admin/agent.
        if (auth()->user()->hasRole('customer') && $booking->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized to cancel this booking.'], 403);
        }

        // Prevent cancellation if booking is already cancelled or ticketed (depending on business rules)
        if (in_array($booking->status, ['cancelled', 'ticketed', 'completed'])) {
            return response()->json(['message' => 'Booking cannot be cancelled in its current status.'], 400);
        }

        DB::beginTransaction();
        try {
            // 1. Call PKfareService to cancel the booking
            // PKfare might have specific requirements for cancellation (e.g., cancellation reasons).
            $pkfareResponse = $this->pkfareService->cancelBooking($booking->pkfare_booking_reference);

            // Check PKfare response for successful cancellation
            $pkfareCancellationStatus = $pkfareResponse['status'] ?? 'failed'; // Adjust key based on PKfare response

            if ($pkfareCancellationStatus !== 'cancelled' && $pkfareCancellationStatus !== 'CANCELED') { // Adjust based on actual PKfare success status
                 throw new \Exception('PKfare API reported an issue with cancellation: ' . ($pkfareResponse['message'] ?? 'Unknown error'));
            }

            // 2. Update local booking status
            $booking->update([
                'status' => 'cancelled',
            ]);

            // 3. Record a cancellation transaction (e.g., for refund processing)
            Transaction::create([
                'booking_id' => $booking->id,
                'amount' => $booking->total_price, // Or the refund amount if different
                'currency' => $booking->currency,
                'type' => 'refund',
                'status' => 'pending', // Refund status will be updated by payment gateway callback
                'payment_gateway_reference' => null,
                'transaction_date' => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Booking cancelled successfully.',
                'booking' => $booking,
                'pkfare_response' => $pkfareResponse, // For debugging
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking cancellation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Failed to cancel booking. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // TODO: Add methods for payment callback handling, ticket issuance updates, etc.
}

