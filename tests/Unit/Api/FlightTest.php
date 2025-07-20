<?php

namespace Tests\Units\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Services\PKfareService;
use Mockery; // For mocking the PKfareService

// This test suite covers the flight search API endpoint.
// It mocks the external PKfareService to ensure tests are fast and reliable.
class FlightTest extends TestCase
{
    use RefreshDatabase; // Resets the database for each test

    protected $pkfareServiceMock;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mock the PKfareService to prevent actual external API calls
        $this->pkfareServiceMock = Mockery::mock(PKfareService::class);
        // Bind the mock to the service container so our controller uses it
        $this->app->instance(PKfareService::class, $this->pkfareServiceMock);

        // Seed roles and permissions for testing
        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        Mockery::close(); // Close Mockery to prevent memory leaks
        parent::tearDown();
    }

    /** @test */
    public function authenticated_user_can_search_flights_successfully()
    {
        // Create and authenticate a user
        $user = User::factory()->create();
        $token = $user->createToken('test_token')->plainTextToken;

        // Define a mock response from PKfareService
        $mockPkfareResponse = [
            'status' => 'success',
            'data' => [
                'flights' => [
                    ['id' => 'FL123', 'airline' => 'KQ', 'price' => 250.00],
                    ['id' => 'FL456', 'airline' => 'LH', 'price' => 300.00],
                ],
            ],
        ];

        // Expect the searchFlights method to be called on the mock and return our mock response
        $this->pkfareServiceMock->shouldReceive('searchFlights')
                               ->once()
                               ->andReturn($mockPkfareResponse);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/flights/search', [
            'tripType' => 'OneWay',
            'origin' => 'NBO',
            'destination' => 'DXB',
            'departureDate' => now()->addDays(7)->format('Y-m-d'),
            'adults' => 1,
        ]);

        $response->assertStatus(200) // Assert HTTP status code is 200 (OK)
                 ->assertJson([
                     'message' => 'Flights retrieved successfully.',
                     'data' => $mockPkfareResponse, // Assert the returned data matches our mock
                 ]);
    }

    /** @test */
    public function flight_search_requires_authentication()
    {
        $response = $this->postJson('/api/flights/search', [
            'tripType' => 'OneWay',
            'origin' => 'NBO',
            'destination' => 'DXB',
            'departureDate' => now()->addDays(7)->format('Y-m-d'),
            'adults' => 1,
        ]);

        $response->assertStatus(401) // Assert HTTP status code is 401 (Unauthorized)
                 ->assertJson(['message' => 'Unauthenticated.']);
    }

    /** @test */
    public function flight_search_requires_valid_input()
    {
        // Create and authenticate a user
        $user = User::factory()->create();
        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/flights/search', [
            'tripType' => 'InvalidType', // Invalid trip type
            'origin' => 'NBOO', // Invalid IATA code length
            'destination' => 'DX', // Invalid IATA code length
            'departureDate' => 'invalid-date', // Invalid date format
            'adults' => 0, // Less than minimum adults
            'returnDate' => now()->subDays(1)->format('Y-m-d'), // Return date before departure
        ]);

        $response->assertStatus(422) // Assert HTTP status code is 422 (Unprocessable Entity)
                 ->assertJsonValidationErrors([
                     'tripType',
                     'origin',
                     'destination',
                     'departureDate',
                     'adults',
                     'returnDate',
                 ]);
    }

    /** @test */
    public function flight_search_handles_pkfare_service_errors()
    {
        // Create and authenticate a user
        $user = User::factory()->create();
        $token = $user->createToken('test_token')->plainTextToken;

        // Expect the searchFlights method to be called and throw an exception
        $this->pkfareServiceMock->shouldReceive('searchFlights')
                               ->once()
                               ->andThrow(new \Exception('PKfare API is currently unavailable.'));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/flights/search', [
            'tripType' => 'OneWay',
            'origin' => 'NBO',
            'destination' => 'DXB',
            'departureDate' => now()->addDays(7)->format('Y-m-d'),
            'adults' => 1,
        ]);

        $response->assertStatus(500) // Assert HTTP status code is 500 (Internal Server Error)
                 ->assertJson([
                     'message' => 'Failed to search flights. Please try again later.',
                     'error' => 'PKfare API is currently unavailable.',
                 ]);
    }
}

