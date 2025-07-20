<?php

namespace Tests\Units\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Flights\Booking;
use App\Services\PKfareService;
use Mockery;
use Spatie\Permission\Models\Role;

// This test suite covers booking-related API endpoints:
// Listing, creating, viewing, and cancelling bookings.
class BookingTest extends TestCase
{
    use RefreshDatabase, WithFaker; // Use WithFaker for generating fake data

    protected $pkfareServiceMock;
    protected $adminUser;
    protected $customerUser;
    protected $adminToken;
    protected $customerToken;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mock the PKfareService
        $this->pkfareServiceMock = Mockery::mock(PKfareService::class);
        $this->app->instance(PKfareService::class, $this->pkfareServiceMock);

        // Seed roles and permissions
        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

        // Create an admin user and get token
        $this->adminUser = User::where('email', 'admin@example.com')->first();
        if (!$this->adminUser) {
            $this->adminUser = User::factory()->create(['email' => 'admin@example.com']);
            $this->adminUser->assignRole('admin');
        }
        $this->adminToken = $this->adminUser->createToken('admin_token')->plainTextToken;

        // Create a customer user and get token
        $this->customerUser = User::where('email', 'customer@example.com')->first();
        if (!$this->customerUser) {
            $this->customerUser = User::factory()->create(['email' => 'customer@example.com']);
            $this->customerUser->assignRole('customer');
        }
        $this->customerToken = $this->customerUser->createToken('customer_token')->plainTextToken;
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function authenticated_customer_can_create_a_booking()
    {
        // Define a mock response from PKfareService for successful booking creation
        $mockPkfareBookingResponse = [
            'bookingReference' => 'PKF123456',
            'status' => 'confirmed',
            'message' => 'Booking successful',
            // ... other PKfare specific response data
        ];

        // Expect the createBooking method to be called on the mock and return our mock response
        $this->pkfareServiceMock->shouldReceive('createBooking')
                               ->once()
                               ->andReturn($mockPkfareBookingResponse);

        $bookingData = [
            'selectedFlight' => [
                'fareSourceCode' => 'SOME_FARE_CODE',
                'segments' => [
                    ['origin' => 'NBO', 'destination' => 'DXB', 'departureTime' => '2025-08-01T10:00:00'],
                ],
                'price' => ['total' => 500.00, 'currency' => 'USD'],
            ],
            'passengers' => [
                [
                    'firstName' => $this->faker->firstName,
                    'lastName' => $this->faker->lastName,
                    'type' => 'ADT',
                    'dob' => '1990-01-01',
                    'gender' => 'Male',
                ],
            ],
            'contactEmail' => $this->faker->unique()->safeEmail,
            'contactPhone' => $this->faker->phoneNumber,
            'totalPrice' => 500.00,
            'currency' => 'USD',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->customerToken,
        ])->postJson('/api/bookings', $bookingData);

        $response->assertStatus(201) // Assert HTTP status code is 201 (Created)
                 ->assertJson([
                     'message' => 'Booking created successfully.',
                     'booking' => [
                         'user_id' => $this->customerUser->id,
                         'pkfare_booking_reference' => 'PKF123456',
                         'status' => 'confirmed',
                         'total_price' => 500.00,
                         'currency' => 'USD',
                         'contact_email' => $bookingData['contactEmail'],
                     ],
                 ]);

        // Assert booking and transaction exist in the database
        $this->assertDatabaseHas('bookings', [
            'pkfare_booking_reference' => 'PKF123456',
            'user_id' => $this->customerUser->id,
            'status' => 'confirmed',
            'total_price' => 500.00,
        ]);
        $this->assertDatabaseHas('transactions', [
            'amount' => 500.00,
            'type' => 'payment',
            'status' => 'pending', // Initial status for transaction
        ]);
    }

    /** @test */
    public function booking_creation_requires_authentication()
    {
        $response = $this->postJson('/api/bookings', []); // No token

        $response->assertStatus(401)
                 ->assertJson(['message' => 'Unauthenticated.']);
    }

    /** @test */
    public function booking_creation_requires_create_booking_permission()
    {
        // Create a user without 'create-booking' permission
        $unauthorizedUser = User::factory()->create();
        $unauthorizedToken = $unauthorizedUser->createToken('unauth_token')->plainTextToken;

        $bookingData = [
            'selectedFlight' => ['fareSourceCode' => 'SOME_FARE_CODE', 'price' => ['total' => 100, 'currency' => 'USD']],
            'passengers' => [['firstName' => 'John', 'lastName' => 'Doe', 'type' => 'ADT', 'dob' => '1990-01-01', 'gender' => 'Male']],
            'contactEmail' => 'test@test.com',
            'contactPhone' => '1234567890',
            'totalPrice' => 100.00,
            'currency' => 'USD',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $unauthorizedToken,
        ])->postJson('/api/bookings', $bookingData);

        $response->assertStatus(403); // Forbidden
    }

    /** @test */
    public function booking_creation_handles_pkfare_service_failure()
    {
        // Expect PKfareService to throw an exception
        $this->pkfareServiceMock->shouldReceive('createBooking')
                               ->once()
                               ->andThrow(new \Exception('PKfare booking failed.'));

        $bookingData = [
            'selectedFlight' => ['fareSourceCode' => 'SOME_FARE_CODE', 'price' => ['total' => 100, 'currency' => 'USD']],
            'passengers' => [['firstName' => 'John', 'lastName' => 'Doe', 'type' => 'ADT', 'dob' => '1990-01-01', 'gender' => 'Male']],
            'contactEmail' => 'test@test.com',
            'contactPhone' => '1234567890',
            'totalPrice' => 100.00,
            'currency' => 'USD',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->customerToken,
        ])->postJson('/api/bookings', $bookingData);

        $response->assertStatus(500)
                 ->assertJson([
                     'message' => 'Failed to create booking. Please try again later.',
                     'error' => 'PKfare booking failed.',
                 ]);

        // Assert that no booking or transaction was saved locally due to rollback
        $this->assertDatabaseMissing('bookings', ['contact_email' => $bookingData['contactEmail']]);
        $this->assertDatabaseMissing('transactions', ['amount' => $bookingData['totalPrice']]);
    }

    /** @test */
    public function authenticated_customer_can_view_their_own_booking()
    {
        $booking = Booking::factory()->create(['user_id' => $this->customerUser->id]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->customerToken,
        ])->getJson('/api/bookings/' . $booking->id);

        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'Booking retrieved successfully.',
                     'data' => ['id' => $booking->id, 'user_id' => $this->customerUser->id],
                 ]);
    }

    /** @test */
    public function authenticated_customer_cannot_view_another_users_booking()
    {
        $anotherUser = User::factory()->create();
        $booking = Booking::factory()->create(['user_id' => $anotherUser->id]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->customerToken,
        ])->getJson('/api/bookings/' . $booking->id);

        $response->assertStatus(403) // Forbidden
                 ->assertJson(['message' => 'Unauthorized to view this booking.']);
    }

    /** @test */
    public function admin_can_view_any_booking()
    {
        $anotherUser = User::factory()->create();
        $booking = Booking::factory()->create(['user_id' => $anotherUser->id]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/bookings/' . $booking->id);

        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'Booking retrieved successfully.',
                     'data' => ['id' => $booking->id],
                 ]);
    }

    /** @test */
    public function authenticated_customer_can_cancel_their_own_booking()
    {
        // Create a booking that can be cancelled
        $booking = Booking::factory()->create([
            'user_id' => $this->customerUser->id,
            'status' => 'confirmed', // Cancellable status
            'pkfare_booking_reference' => 'PKF_CANCEL_ME',
            'total_price' => 150.00,
            'currency' => 'USD'
        ]);

        // Mock PKfare cancellation success
        $this->pkfareServiceMock->shouldReceive('cancelBooking')
                               ->once()
                               ->with('PKF_CANCEL_ME')
                               ->andReturn(['status' => 'cancelled', 'message' => 'Successfully cancelled']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->customerToken,
        ])->postJson('/api/bookings/' . $booking->id . '/cancel');

        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'Booking cancelled successfully.',
                     'booking' => ['status' => 'cancelled'],
                 ]);

        // Assert booking status updated and refund transaction created
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('transactions', [
            'booking_id' => $booking->id,
            'type' => 'refund',
            'status' => 'pending',
            'amount' => 150.00
        ]);
    }

    /** @test */
    public function authenticated_customer_cannot_cancel_another_users_booking()
    {
        $anotherUser = User::factory()->create();
        $booking = Booking::factory()->create(['user_id' => $anotherUser->id, 'status' => 'confirmed']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->customerToken,
        ])->postJson('/api/bookings/' . $booking->id . '/cancel');

        $response->assertStatus(403)
                 ->assertJson(['message' => 'Unauthorized to cancel this booking.']);
    }

    /** @test */
    public function booking_cancellation_handles_pkfare_service_failure()
    {
        $booking = Booking::factory()->create([
            'user_id' => $this->customerUser->id,
            'status' => 'confirmed',
            'pkfare_booking_reference' => 'PKF_FAIL_CANCEL',
        ]);

        // Mock PKfare cancellation failure
        $this->pkfareServiceMock->shouldReceive('cancelBooking')
                               ->once()
                               ->andThrow(new \Exception('PKfare cancellation failed.'));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->customerToken,
        ])->postJson('/api/bookings/' . $booking->id . '/cancel');

        $response->assertStatus(500)
                 ->assertJson([
                     'message' => 'Failed to cancel booking. Please try again later.',
                     'error' => 'PKfare cancellation failed.',
                 ]);

        // Assert booking status remains unchanged due to rollback
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseMissing('transactions', [ // No refund transaction created
            'booking_id' => $booking->id,
            'type' => 'refund'
        ]);
    }
}

