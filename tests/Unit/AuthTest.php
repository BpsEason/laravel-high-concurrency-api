<?php

namespace Tests\Feature; // 更改為 Feature 命名空間，因為這是 API 功能測試

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
            'password' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $userData);

        $response->assertStatus(201) // 成功註冊返回 201 Created
                 ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_user_email_must_be_unique(): void
    {
        // 先創建一個已經存在的用戶
        User::create([
            'name' => 'Existing User',
            'email' => 'duplicate@example.com',
            'password' => Hash::make('password'),
        ]);

        // 嘗試用相同的 email 註冊
        $userData = [
            'name' => 'Another User',
            'email' => 'duplicate@example.com',
            'password' => 'anotherpassword',
        ];

        $response = $this->postJson('/api/v1/auth/register', $userData);

        $response->assertStatus(422) // 驗證失敗返回 422 Unprocessable Entity
                 ->assertJsonValidationErrors(['email']); // 斷言 email 字段有驗證錯誤

        // 確保沒有新增用戶
        $this->assertDatabaseCount('users', 1);
    }

    public function test_user_can_login_successfully(): void
    {
        $password = 'password123';
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

        // 驗證返回的 token 是否有效
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
            'password' => 'wrong_password', // 錯誤密碼
        ];

        $response = $this->postJson('/api/v1/auth/login', $credentials);

        $response->assertStatus(401) // 未授權返回 401 Unauthorized
                 ->assertJson(['error' => 'Unauthorized', 'message' => 'Invalid credentials.']);
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

        // 嘗試用已失效的 token 訪問 me 介面，應失敗
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

        // 新舊 token 不應該相同
        $this->assertNotEquals($token, $response->json('access_token'));
    }
}