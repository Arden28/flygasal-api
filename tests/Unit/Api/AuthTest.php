<?php

namespace Tests\Units\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

// This test suite covers authentication-related API endpoints:
// User registration, login, and logout.
class AuthTest extends TestCase
{
    use RefreshDatabase; // Resets the database for each test

    /**
     * Set up the test environment.
     * This method is called before each test method.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions for testing
        $this->artisan('db:seed', ['--class' => 'DatabaseSeeder']);
    }

    /** @test */
    public function a_user_can_register_successfully()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(201) // Assert HTTP status code is 201 (Created)
                 ->assertJsonStructure([ // Assert JSON response structure
                     'message',
                     'user' => ['id', 'name', 'email'],
                     'access_token',
                     'token_type',
                 ])
                 ->assertJson([ // Assert specific values in the JSON response
                     'message' => 'User registered successfully.',
                     'user' => ['email' => 'test@example.com'],
                     'token_type' => 'Bearer',
                 ]);

        // Assert user exists in the database
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);

        // Assert the user has the 'customer' role
        $user = User::where('email', 'test@example.com')->first();
        $this->assertTrue($user->hasRole('customer'));
    }

    /** @test */
    public function registration_requires_valid_data()
    {
        $response = $this->postJson('/api/register', [
            'name' => '', // Missing name
            'email' => 'invalid-email', // Invalid email format
            'password' => 'short', // Too short password
            'password_confirmation' => 'mismatch', // Password mismatch
        ]);

        $response->assertStatus(422) // Assert HTTP status code is 422 (Unprocessable Entity)
                 ->assertJsonValidationErrors(['name', 'email', 'password']); // Assert specific validation errors
    }

    /** @test */
    public function a_user_can_log_in_successfully()
    {
        // Create a user first
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => bcrypt('password'),
        ]);

        // Assign customer role to the user for consistency with registration
        $customerRole = Role::where('name', 'customer')->first();
        if ($customerRole) {
            $user->assignRole($customerRole);
        }

        $response = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200) // Assert HTTP status code is 200 (OK)
                 ->assertJsonStructure([
                     'message',
                     'user' => ['id', 'name', 'email'],
                     'access_token',
                     'token_type',
                 ])
                 ->assertJson([
                     'message' => 'Logged in successfully.',
                     'user' => ['email' => 'login@example.com'],
                 ]);

        // Assert that a token was created for the user
        $this->assertNotNull($user->fresh()->tokens()->first());
    }

    /** @test */
    public function login_fails_with_invalid_credentials()
    {
        // Create a user but try to log in with wrong password
        User::factory()->create([
            'email' => 'wrongpass@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'wrongpass@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401) // Assert HTTP status code is 401 (Unauthorized)
                 ->assertJson([
                     'message' => 'Invalid credentials.',
                 ]);
    }

    /** @test */
    public function an_authenticated_user_can_log_out()
    {
        // Create and authenticate a user
        $user = User::factory()->create();
        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/logout');

        $response->assertStatus(200) // Assert HTTP status code is 200 (OK)
                 ->assertJson([
                     'message' => 'Logged out successfully.',
                 ]);

        // Assert that the token has been deleted from the database
        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => hash('sha256', $token), // Sanctum stores hashed tokens
        ]);
    }

    /** @test */
    public function logout_requires_authentication()
    {
        $response = $this->postJson('/api/logout'); // No token provided

        $response->assertStatus(401) // Assert HTTP status code is 401 (Unauthorized)
                 ->assertJson(['message' => 'Unauthenticated.']);
    }
}

