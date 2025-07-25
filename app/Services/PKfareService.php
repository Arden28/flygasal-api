<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log; // For logging API errors

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
     * Creates a flight booking.
     *
     * @param array $bookingDetails An associative array containing all necessary booking information
     * (e.g., selected flight, passenger details, contact info).
     * @return array The booking confirmation details
     * @throws \Exception
     */
    public function createBooking(array $bookingDetails): array
    {
        // This payload structure is highly dependent on PKfare's booking API.
        // Example structure:
        $payload = [
            'selectedFlight' => $bookingDetails['selectedFlight'], // This would be the flight data obtained from search
            'passengers' => $bookingDetails['passengers'], // Array of passenger objects
            'contactInfo' => $bookingDetails['contactInfo'], // Contact details for the booking
            // ... other necessary fields like payment info, remarks, etc.
        ];

        // The actual endpoint for booking might be something like '/air/booking' or '/flights/book'
        // Consult PKfare documentation for the exact endpoint and request body.
        return $this->post('/air/booking', $payload);
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
