<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('stock');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
	
	public function test_user_email_must_be_unique_via_controller(): void
	{
		User::create([
			'name' => 'Existing User',
			'email' => 'duplicate@example.com',
			'password' => Hash::make('password'),
		]);

		$response = $this->postJson('/api/auth/register', [
			'name' => 'Another User',
			'email' => 'duplicate@example.com',
			'password' => 'anotherpassword',
		]);

		$response->assertStatus(422) // 422 Unprocessable Entity
				 ->assertJsonValidationErrors(['email']); // 斷言 email 字段有驗證錯誤
	}
};