<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_registered(): void
    {
        $userData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => env('TEST_USER_PASSWORD', 'password123'),
        ];

        $response = $this->postJson('/api/v1/auth/register', $userData);

        $response->assertStatus(201)
                 ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertTrue(Hash::check($userData['password'], $user->password));
    }

    public function test_user_email_must_be_unique(): void
    {
        User::create([
            'name' => 'Existing User',
            'email' => 'duplicate@example.com',
            'password' => Hash::make('password'),
        ]);

        $userData = [
            'name' => 'Another User',
            'email' => 'duplicate@example.com',
            'password' => env('TEST_USER_PASSWORD', 'password123'),
        ];

        $response = $this->postJson('/api/v1/auth/register', $userData);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email'])
                 ->assertJsonFragment(['email' => ['The email has already been taken.']]);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_user_can_login_successfully(): void
    {
        $password = env('TEST_USER_PASSWORD', 'password123');
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make($password),
        ]);

        $credentials = [
            'email' => 'login@example.com',
            'password' => $password,
        ];

        $response = $this->postJson('/api/v1/auth/login', $credentials);

        $response->assertStatus(200)
                 ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);

        $this->assertNotNull(JWTAuth::setToken($response->json('access_token'))->authenticate());
    }

    public function test_user_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('correct_password'),
        ]);

        $credentials = [
            'email' => 'login@example.com',
            'password' => 'wrong_password',
        ];

        $response = $this->postJson('/api/v1/auth/login', $credentials);

        $response->assertStatus(401)
                 ->assertJson([
                     'error' => 'Unauthorized',
                     'message' => 'Invalid credentials.',
                 ]);
    }

    public function test_authenticated_user_can_access_me_endpoint(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson('/api/v1/auth/me');

        $response->assertStatus(200)
                 ->assertJson(['id' => $user->id, 'email' => $user->email]);
    }

    public function test_unauthenticated_user_cannot_access_me_endpoint(): void
    {
        $response = $this->postJson('/api/v1/auth/me');

        $response->assertStatus(401)
                 ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Successfully logged out']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson('/api/v1/auth/me');

        $response->assertStatus(401)
                 ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_user_can_refresh_token(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson('/api/v1/auth/refresh');

        $response->assertStatus(200)
                 ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);

        $this->assertNotEquals($token, $response->json('access_token'));
    }
}