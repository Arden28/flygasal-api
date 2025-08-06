<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log; // For logging API errors
use InvalidArgumentException;

// This service class encapsulates all interactions with the PKfare API.
// It handles API requests, error handling, and response parsing.
class PKfareService
{
    protected Client $client;
    protected string $baseUrl;
    protected string $apiKey;
    protected string $apiSecret;
    protected string $apiSignature;

    /**
     * Constructor for PKfareService.
     * Initializes the Guzzle HTTP client with base URI and headers.
     */
    public function __construct()
    {
        // Retrieve PKfare API credentials from environment variables.
        // Ensure these are set in your .env file.
        $this->baseUrl = config('app.pkfare_api_base_url', 'https://api.pkfare.com');
        $this->apiKey = config('app.pkfare_api_key');
        $this->apiSecret = config('app.pkfare_api_secret');
        $this->apiSignature = config('app.pkfare_api_signature');

        // Basic validation for API keys
        if (empty($this->apiKey) || empty($this->apiSecret)) {
            Log::error('PKfare API keys are not set in the environment variables.');
            // You might want to throw an exception here in a production environment
            // throw new \Exception('PKfare API keys not configured.');
        }

        // Initialize Guzzle HTTP client
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                // PKfare usually requires authentication headers.
                // This is a placeholder; you'll need to consult PKfare's API documentation
                // for the exact authentication mechanism (e.g., custom headers, OAuth, HMAC).
                // For demonstration, let's assume a simple API Key header for now,
                // but this will likely need to be replaced with their specific method.
                'X-PKFARE-API-Key' => $this->apiKey,
                // 'Authorization' => 'Bearer ' . $this->generateAccessToken(), // Example for OAuth
            ],
            'timeout' => 30, // Request timeout in seconds
        ]);
    }

    /**
     * Helper method to make a GET request to the PKfare API.
     *
     * @param string $endpoint The API endpoint (e.g., '/flights/search')
     * @param array $query Query parameters for the request
     * @return array The JSON decoded response
     * @throws \Exception If the API request fails
     */
    public function get(string $endpoint, array $query = []): array
    {
        try {
            $response = $this->client->get($endpoint, ['query' => $query]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $this->handleRequestException($e, $endpoint);
        }
    }

    /**
     * Helper method to make a POST request to the PKfare API.
     *
     * @param string $endpoint The API endpoint (e.g., '/flights/booking')
     * @param array $data Request body data
     * @return array The JSON decoded response
     * @throws \Exception If the API request fails
     */
    public function post(string $endpoint, array $data = []): array
    {
        try {
            $response = $this->client->post($endpoint, ['json' => $data]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $this->handleRequestException($e, $endpoint);
        }
    }

    /**
     * Handles Guzzle Request Exceptions, logs them, and throws a generic exception.
     *
     * @param RequestException $e The exception caught
     * @param string $endpoint The endpoint that was called
     * @throws \Exception
     */
    protected function handleRequestException(RequestException $e, string $endpoint): void
    {
        $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 'N/A';
        $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';

        Log::error("PKfare API request failed for endpoint: {$endpoint}", [
            'status_code' => $statusCode,
            'message' => $e->getMessage(),
            'response_body' => $responseBody,
            'trace' => $e->getTraceAsString(),
        ]);

        // Re-throw a more generic exception to avoid exposing internal API details.
        throw new \Exception("PKfare API request failed: " . $e->getMessage());
    }

    /**
     * Searches for flights based on provided criteria.
     *
     * @param array $criteria An associative array of search parameters (e.g., origin, destination, departureDate, returnDate, adults, children, infants)
     * @return array The flight search results
     * @throws \Exception
     */
    public function searchFlights(array $criteria): array
    {
        // Prepare the authentication block
        $partnerId = $this->apiKey;
        $partnerKey = $this->apiSecret;
        $sign = md5($partnerId . $partnerKey);
        $tripType = $criteria['tripType'] ?? 'Oneway';

        $payload = [
            'authentication' => [
                'partnerId' => $partnerId,
                'sign' => $sign,
            ],
            'search' => [
                'adults' => $criteria['adults'] ?? 1,
                'children' => $criteria['children'] ?? 0,
                'infants' => $criteria['infants'] ?? 0,
                'nonstop' => $criteria['nonstop'] ?? 0,
                'airline' => $criteria['airline'] ?? '',
                'solutions' => $criteria['solutions'] ?? 0,
                'tag' => '',
                'returnTagPrice' => 'Y',
                'searchAirLegs' => [],
            ],
        ];

        // Add first leg
        $payload['search']['searchAirLegs'][] = [
            'cabinClass' => $criteria['cabinClass'] ?? '',
            'departureDate' => $criteria['departureDate'],
            'destination' => $criteria['destination'],
            'origin' => $criteria['origin'],
            'airline' => $criteria['airline'] ?? '',
        ];

        // Optional return leg
        if (!empty($criteria['returnDate'])) {
            $payload['search']['searchAirLegs'][] = [
                'cabinClass' => $criteria['cabinClass'] ?? '',
                'departureDate' => $criteria['returnDate'],
                'destination' => $criteria['origin'],     // Reverse destination
                'origin' => $criteria['destination'],     // Reverse origin
                'airline' => $criteria['airline'] ?? '',
            ];
        }

        return $this->post('/json/shoppingV8', $payload);
    }

    /**
     * Retrieves precise pricing for a selected flight using dynamic criteria.
     *
     * @param array $criteria An associative array with journeys, passengers, solutionId, etc.
     * @return array The precise pricing response
     * @throws \Exception
     */
    public function getPrecisePricing(array $criteria): array
    {
        // Build authentication
        $payload = [
            'authentication' => [
                'partnerId' => $this->apiKey,
                'sign' => md5($this->apiKey . $this->apiSecret),
            ],
            'pricing' => [
                'adults' => $criteria['adults'] ?? 1,
                'children' => $criteria['children'] ?? 0,
                'infants' => $criteria['infants'] ?? 0,
                'solutionId' => 'direct pricing',
                'solutionKey' => $criteria['solutionKey'] ?? '',
                'cabin' => '',
                'tag' => $criteria['tag'] ?? null,
                'journeys' => [], // Will be populated below
            ],
        ];


        $journeys = $criteria['journeys'] ?? [];

        if (!empty($journeys) && isset($journeys[0]['flightNum'])) {
            // Single flat journey, wrap it
            $journeys = [ $journeys ];
        }

        // Transform journey segments (must be associative with keys like journey_0, journey_1)
        foreach ($journeys as $index => $segments) {
            $key = 'journey_' . $index;

            $payload['pricing']['journeys'][$key] = array_map(function ($segment) {
                return [
                    'airline' => $segment['airline'] ?? '',
                    'flightNum' => $segment['flightNum'] ?? '',
                    'arrival' => $segment['arrival'] ?? '',
                    'arrivalDate' => $segment['arrivalDate'] ?? '',
                    'arrivalTime' => $segment['arrivalTime'] ?? '',
                    'departure' => $segment['departure'] ?? '',
                    'departureDate' => $segment['departureDate'] ?? '',
                    'departureTime' => $segment['departureTime'] ?? '',
                    'bookingCode' => $segment['bookingCode'] ?? '',
                ];
            }, $segments);
        }

        // Log::debug('Received journeys:', $payload);

        return $this->post('/json/precisePricing_V10', $payload);
    }


    /**
     * Extract solutionKey and journeys (flight IDs) from selected solution.
     */
    public function extractPricingInfoFromSolutions(array $solutions, string $solutionKey): array
    {
        foreach ($solutions as $solution) {
            if ($solution['solutionKey'] === $solutionKey) {
                return [
                    'solutionKey' => $solutionKey,
                    'journeys' => $solution['journeys'],
                ];
            }
        }

        throw new \Exception("Solution with key {$solutionKey} not found.");
    }

    /**
     * Ancillary Pricing
     *
     * @param array $bookingDetails An associative array containing all necessary booking information
     * (e.g., selected flight, passenger details, contact info).
     * @return array The booking confirmation details
     * @throws \Exception
     */
    public function ancillaryPricing($criteria){
        // Build payload as expected by PKFare's AncillaryPricing API
        $payload = [
            'authentication' => [
                'partnerId' => $this->apiKey,
                'sign' => md5($this->apiKey . $this->apiSecret),
            ],
            'pricing' => [
                'adults' => $criteria['adults'] ?? 1,
                'children' => $criteria['children'] ?? 0,
                "ancillary" => [
                    2
                ],
                'solutionId' => $criteria['solutionId'] ?? null,
                'journeys' => [], // Will be populated below
                // 'infants' => $criteria['infants'] ?? 0,
                // 'cabin' => $criteria['cabinType'] ?? '',
                // 'tag' => $criteria['tag'] ?? 'direct pricing',
            ]
        ];


        $journeys = $criteria['journeys'] ?? [];

        if (!empty($journeys) && isset($journeys[0]['flightNum'])) {
            // Single flat journey, wrap it
            $journeys = [ $journeys ];
        }

        // Transform journey segments (must be associative with keys like journey_0, journey_1)
        foreach ($journeys as $index => $segments) {
            $key = 'journey_' . $index;

            $payload['pricing']['journeys'][$key] = array_map(function ($segment) {
                return [
                    'airline' => $segment['airline'] ?? '',
                    'flightNum' => $segment['flightNum'] ?? '',
                    'arrival' => $segment['arrival'] ?? '',
                    'arrivalDate' => $segment['arrivalDate'] ?? '',
                    'arrivalTime' => $segment['arrivalTime'] ?? '',
                    'departure' => $segment['departure'] ?? '',
                    'departureDate' => $segment['departureDate'] ?? '',
                    'departureTime' => $segment['departureTime'] ?? '',
                    'bookingCode' => $segment['bookingCode'] ?? '',
                ];
            }, $segments);
        }

        // Log::debug('Received ancillary:', $payload);


        // Log::info('Payload Ancillary', $payload);

        return $this->post('/json/ancillaryPricingV6', $payload);
    }

    /**
     * Creates a flight booking.
     *
     * @param array $bookingDetails An associative array containing all necessary booking information
     * (e.g., selected flight, passenger details, contact info).
     * @return array The booking confirmation details
     * @throws \Exception
     */

    public function createBooking(array $bookingDetails): array
    {
        // Build payload as expected by PKFare's booking API
        $payload = [
            'authentication' => [
                'partnerId' => $this->apiKey,
                'sign' => md5($this->apiKey . $this->apiSecret),
            ],
            'booking' => [
                'passengers' => array_map(function ($passenger, $index) {
                    return [
                        'passengerIndex' => $index + 1,
                        'birthday' => $passenger['dob'],
                        'firstName' => $passenger['firstName'],
                        'lastName' => $passenger['lastName'],
                        'nationality' => $passenger['nationality'] ?? 'US', // default if missing
                        'psgType' => $passenger['type'],
                        'sex' => strtoupper(substr($passenger['gender'], 0, 1)), // 'M' or 'F'
                        'ffpNumber' => $passenger['ffpNumber'] ?? null,
                        'ffpAirline' => $passenger['ffpAirline'] ?? null,
                        'ktn' => $passenger['ktn'] ?? null,
                        'redress' => $passenger['redress'] ?? null,
                        'associatedPassengerIndex' => $passenger['associatedPassengerIndex'] ?? null,
                    ];
                }, $bookingDetails['passengers'], array_keys($bookingDetails['passengers'])),

                'solution' => [
                    'solutionId' => $bookingDetails['solutionId'],
                    'adtFare' => $bookingDetails['selectedFlight']['adtFare'] ?? null,
                    'adtTax' => $bookingDetails['selectedFlight']['adtTax'] ?? null,
                    'chdFare' => $bookingDetails['selectedFlight']['chdFare'] ?? null,
                    'chdTax' => $bookingDetails['selectedFlight']['chdTax'] ?? null,
                    'infFare' => $bookingDetails['selectedFlight']['infFare'] ?? null,
                    'infTax' => $bookingDetails['selectedFlight']['infTax'] ?? null,
                    'journeys' => $bookingDetails['selectedFlight']['journeys'] ?? [],
                ],

                'contact' => [
                    'name' => $bookingDetails['contactInfo']['name'],
                    'email' => $bookingDetails['contactInfo']['email'],
                    'telCode' => $bookingDetails['contactInfo']['telCode'] ?? '+1',
                    'mobile' => $bookingDetails['contactInfo']['phone'],
                    'buyerEmail' => $bookingDetails['contactInfo']['buyerEmail'] ?? null,
                    'buyerTelCode' => $bookingDetails['contactInfo']['buyerTelCode'] ?? null,
                    'buyerMobile' => $bookingDetails['contactInfo']['buyerMobile'] ?? null,
                ],

                'ancillary' => $bookingDetails['ancillary'] ?? [], // Optional baggage/seat selection
            ]
        ];

        Log::info('Payload: ', $payload);
        return $this->post('/json/preciseBooking_V7', $payload);
    }


    /**
     * Retrieves details of an existing booking.
     *
     * @param string $bookingReference The booking reference number (PNR) from PKfare
     * @return array The booking details
     * @throws \Exception
     */
    public function getBookingDetails(string $bookingReference): array
    {
        // The actual endpoint for retrieving booking details might be something like '/air/booking/{reference}'
        // or a POST request with the reference in the body.
        // Consult PKfare documentation.
        return $this->get('/air/booking/' . $bookingReference);
    }

    /**
     * Cancels an existing booking.
     *
     * @param string $bookingReference The booking reference number (PNR) from PKfare
     * @return array The cancellation confirmation
     * @throws \Exception
     */
    public function cancelBooking(string $bookingReference): array
    {
        // The actual endpoint for cancellation might be something like '/air/booking/{reference}/cancel'
        // or a POST request to a cancellation endpoint.
        // Consult PKfare documentation.
        return $this->post('/air/booking/' . $bookingReference . '/cancel');
    }

    // TODO: Add more PKfare specific methods as needed (e.g., ticket issuance, payment status, etc.)
}
