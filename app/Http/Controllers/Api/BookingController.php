<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flights\Booking;
use App\Models\Flights\BookingPassenger;
use App\Models\Flights\BookingSegment;
use App\Models\Flights\Transaction;
use App\Services\PKfareService;
use Carbon\Carbon;
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
            'status' => true,
            'message' => 'Bookings retrieved successfully.',
            'data' => $bookings,
        ]);
    }

    /**
     * Store a newly created booking in storage.
     * Calls PKfare API and saves to local DB.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            // 1. Validate incoming data
            $validatedData = $request->validate([
                'selectedFlight' => 'required|array',
                'solutionId' => 'required|string',
                'passengers' => 'required|array|min:1',
                'passengers.*.firstName' => 'required|string|max:255',
                'passengers.*.lastName' => 'required|string|max:255',
                'passengers.*.type' => 'required|string|in:ADT,CHD,INF',
                'passengers.*.dob' => 'required|date_format:Y-m-d',
                'passengers.*.gender' => 'required|string|in:Male,Female',
                'passengers.*.passportNumber' => 'nullable|string|max:255',
                'passengers.*.passportExpiry' => 'nullable|date_format:Y-m-d|after_or_equal:today',
                'passengers.*.nationality' => 'nullable|string|size:2',
                'contactName' => 'required|string|max:155',
                'contactEmail' => 'required|email|max:255',
                'contactPhone' => 'required|string|max:20',
                'totalPrice' => 'required|numeric|min:0',
                'currency' => 'required|string|size:3',
                'agent_fee' => 'nullable|numeric|min:0',
            ]);

            // 2. Auth check
            $user = $request->user();
            if (!$user) {
                DB::rollBack();
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            // 3. Prepare booking details
            $pkfareBookingDetails = [
                'selectedFlight' => $validatedData['selectedFlight'],
                'solutionId' => $validatedData['solutionId'],
                'passengers' => $validatedData['passengers'],
                'contactInfo' => [
                    'name'  => $validatedData['contactName'],
                    'email' => $validatedData['contactEmail'],
                    'phone' => $validatedData['contactPhone'],
                ],
            ];

            // 4. Call PKfare
            $pkfareResponse = (array) $this->pkfareService->createBooking($pkfareBookingDetails);
            Log::info('PKFare Response: ' . json_encode($pkfareResponse));

            $errorMessages = [
                'S001' => 'System error.',
                'B002' => 'Partner ID does not exist.',
                'B003' => 'Invalid signature. Please contact support.',
                'B035' => 'Too many requests. Please try again later.',
                'P001' => 'Invalid input data.',
                'P002' => 'Missing required fields.',
                'P006' => 'Invalid parameters.',
                '0307' => 'Seats are no longer available.',
                'B005' => 'Pricing expired. Please search again.',
                'B007' => 'Flight segment is no longer valid.',
                'B008' => 'Flight changed. Please reselect.',
                'B011' => 'Fare is unavailable.',
                'B017' => 'Price has changed.',
                'B029' => 'Duplicate reservation found.',
                'B068' => 'Flight segment mismatch.',
            ];

            // 5. Handle PKfare errors
            $errorCode = $pkfareResponse['errorCode'] ?? null;
            if ($errorCode !== '0') {
                DB::rollBack();
                $message = $errorMessages[$errorCode] ?? ($pkfareResponse['errorMsg'] ?? 'Booking failed.');
                Log::warning("PKfare booking failed: {$errorCode} - {$message}");
                return response()->json([
                    'success' => false,
                    'code'    => $errorCode,
                    'message' => $message,
                ], 400);
            }

            // 6. Extract data
            $data     = $pkfareResponse['data'] ?? [];
            $solution = $data['solution'] ?? [];

            $adtFare = (float)($solution['adtFare'] ?? 0);
            $adtTax  = (float)($solution['adtTax']  ?? 0);
            $chdFare = (float)($solution['chdFare'] ?? 0);
            $chdTax  = (float)($solution['chdTax']  ?? 0);
            $totalAmount = $adtFare + $adtTax + $chdFare + $chdTax;

            // 7. Save booking
            $booking = Booking::create([
                'user_id'         => $user->id,
                'order_num'       => $data['orderNum'] ?? null,
                'pnr'             => $data['pnr'] ?? null,
                'solution_id'     => $solution['solutionId'] ?? $validatedData['solutionId'],
                'fare_type'       => $solution['fareType'] ?? null,
                'currency'        => $solution['currency'] ?? $validatedData['currency'],
                'adt_fare'        => $adtFare,
                'adt_tax'         => $adtTax,
                'chd_fare'        => $chdFare,
                'chd_tax'         => $chdTax,
                'infants'         => (int)($solution['infants'] ?? 0),
                'adults'          => (int)($solution['adults'] ?? 0),
                'children'        => (int)($solution['children'] ?? 0),
                'plating_carrier' => $solution['platingCarrier'] ?? null,
                'baggage_info'    => $solution['baggageMap'] ?? null,
                'flights'         => $data['flights'] ?? null,
                'segments'        => $data['segments'] ?? null,
                'passengers'      => $validatedData['passengers'],
                'agent_fee'       => (float)($validatedData['agent_fee'] ?? 0),
                'total_amount'    => $totalAmount,
                'contact_name'    => $validatedData['contactName'],
                'contact_email'   => $validatedData['contactEmail'],
                'contact_phone'   => $validatedData['contactPhone'],
                'status'          => 'pending',
                'payment_status'  => 'unpaid',
                'booking_date'    => now(),
            ]);

            // 8. Save passengers
            foreach ($validatedData['passengers'] as $i => $p) {
                BookingPassenger::create([
                    'booking_id'      => $booking->id,
                    'passenger_index' => $i + 1,
                    'psg_type'        => $p['type'],
                    'sex'             => $p['gender'] === 'Male' ? 'M' : 'F',
                    'birthday'        => $p['dob'],
                    'first_name'      => strtoupper($p['firstName']),
                    'last_name'       => strtoupper($p['lastName']),
                    'nationality'     => strtoupper($p['nationality'] ?? ''),
                    'card_type'       => !empty($p['passportNumber']) ? 'P' : null,
                    'card_num'        => $p['passportNumber'] ?? null,
                    'card_expired_date' => $p['passportExpiry'] ?? null,
                ]);
            }

            // 9. Save segments
            if (!empty($data['segments'])) {
                foreach ($data['segments'] as $idx => $seg) {
                    $departureDateTime = !empty($seg['strDepartureDate']) && !empty($seg['strDepartureTime'])
                        ? Carbon::parse($seg['strDepartureDate'] . ' ' . $seg['strDepartureTime'])
                        : null;

                    $arrivalDateTime = !empty($seg['strArrivalDate']) && !empty($seg['strArrivalTime'])
                        ? Carbon::parse($seg['strArrivalDate'] . ' ' . $seg['strArrivalTime'])
                        : null;

                    BookingSegment::updateOrCreate(
                        ['booking_id' => $booking->id, 'segment_no' => $idx + 1],
                        [
                            'airline'            => $seg['airline'] ?? null,
                            'equipment'          => $seg['equipment'] ?? null,
                            'departure_terminal' => $seg['departureTerminal'] ?? null,
                            'arrival_terminal'   => $seg['arrivalTerminal'] ?? null,
                            'departure_date'     => $departureDateTime,
                            'arrival_date'       => $arrivalDateTime,
                            'departure'          => $seg['departure'] ?? null,
                            'arrival'            => $seg['arrival'] ?? null,
                            'flight_num'         => $seg['flightNum'] ?? null,
                            'cabin_class'        => $seg['cabinClass'] ?? null,
                            'booking_code'       => $seg['bookingCode'] ?? null,
                        ]
                    );
                }
            }

            DB::commit();
            Log::info("Booking stored successfully: {$booking->order_num}");

            return response()->json([
                'message'   => 'Booking created successfully.',
                'booking'   => $booking->load(['passengers', 'segments']),
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            Log::error('Booking validation failed: ' . json_encode($e->errors()));
            return response()->json(['message' => 'Validation Error', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Booking creation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to create booking.', 'error' => $e->getMessage()], 500);
        }
    }


    /**
     * Display the specified booking.
     *
     * @param $bookingId The booking instance retrieved by route model binding.
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($bookingId)
    {
        $booking = Booking::where('order_num', $bookingId)->first();
        if(!$booking){
            return response()->json([
                'message' => 'Booking not found'
            ], 404);
        }

        // Authorization check: A user can only view their own bookings unless they are admin/agent.
        if (auth()->user()->hasRole('agent') && $booking->user_id !== auth()->id()) {
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
     * Display the specified booking.
     *
     * @param $bookingId The booking instance retrieved by route model binding.
     * @return \Illuminate\Http\JsonResponse
     */
    public function orderDetails($bookingId)
    {
        $booking = Booking::where('order_num', $bookingId)->first();
        if(!$booking){
            return response()->json([
                'message' => 'Booking not found'
            ], 404);
        }

        // Authorization check: A user can only view their own bookings unless they are admin/agent.
        if (auth()->user()->hasRole('agent') && $booking->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized to view this booking.'], 403);
        }

        // Eager load transactions related to the booking
        $pkfareResponse = $this->pkfareService->getBookingDetails($booking->order_num);

        $errorCode = $pkfareResponse['errorCode'] ?? null;


        // Error map (put at top or in a helper)
        $errorMessages = [
            'S001' => 'System error.',
            'S002' => 'Request timeout.',
            'P001' => 'Parameter is illegal.',
            'B002' => 'PartnerID does not exist.',
            'B003' => 'Illegal sign. Please check your signature.',
            'B048' => 'Request buyer is not matched with order.',
            'B037' => 'Order does not exist.',
        ];

        if ($errorCode !== '0') {
            $message = $errorMessages[$errorCode] ?? ($pkfareResponse['errorMsg'] ?? 'Falied to fecth booking details.');

            return response()->json([
                'success' => false,
                'code' => $errorCode,
                'message' => $message,
            ], 400); // Bad request or adjust to suit
        }

        return response()->json([
            'code' => $pkfareResponse['errorCode'],
            'message' => 'Booking retrieved successfully.',
            'booking' => $pkfareResponse['data'],
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

        // 1. Validate incoming request data for booking
        $validatedData = $request->validate([
            'orderNum' => 'required|string|exists:bookings,order_num', // Ensure orderNum matches an existing booking in DB
            'pnr' => 'required|string|unique:bookings,pnr', // Ensure PNR is required, string, and does not already exist in DB
        ]);

        // 2. Authorization check: A user can only cancel their own bookings unless they are admin/agent.
        if (auth()->user()->hasRole('admin') && $booking->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized to cancel this booking.'], 403);
        }

        // 3. Prevent cancellation if booking is already cancelled or ticketed (depending on business rules)
        if (in_array($booking->status, ['cancelled', 'ticketed', 'completed'])) {
            return response()->json(['message' => 'Booking cannot be cancelled in its current status.'], 400);
        }

        DB::beginTransaction();
        $requestData = [
            'orderNum' => $validatedData['orderNum'],
            'virtualPnr' => $validatedData['pnr']
        ];
        try {
            // 4. Call PKfareService to cancel the booking.
            $pkfareResponse = $this->pkfareService->cancelBooking($requestData);

            // 5. Check API response
            $errorCode = $pkfareResponse['errorCode'] ?? null;


            // Error map (put at top or in a helper)
            $errorMessages = [
                'S001' => 'System error.',
                'P001' => 'Wrong parameter.',
                'B002' => 'Partner does not exist.',
                'B003' => 'Illegal sign. Please check your signature.',
                'B009' => 'Order status is invalid. Order status must be "to_be_paid".',
                'B010' => 'Order number does not exist.',
                'B037' => 'Order does not exist.',
                'B041' => 'The order has been cancelled.',
            ];

            if ($errorCode !== '0') {
                $message = $errorMessages[$errorCode] ?? ($pkfareResponse['errorMsg'] ?? 'Cancellation failed.');

                return response()->json([
                    'success' => false,
                    'code' => $errorCode,
                    'message' => $message,
                ], 400); // Bad request or adjust to suit
            }

            // 6. Update local booking status
            $booking->update([
                'status' => 'cancelled',
            ]);

            // // 7. Record a cancellation transaction (e.g., for refund processing)
            // Transaction::create([
            //     'booking_id' => $booking->id,
            //     'amount' => $booking->total_amount, // Or the refund amount if different
            //     'currency' => $booking->currency,
            //     'type' => 'refund',
            //     'status' => 'pending', // Refund status will be updated by payment gateway callback
            //     'payment_gateway_reference' => null,
            //     'transaction_date' => now(),
            // ]);

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

