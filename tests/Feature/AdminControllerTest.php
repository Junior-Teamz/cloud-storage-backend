<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Instance;
use App\Models\Tags;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;


class AdminControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user with admin role
        $this->adminUser = User::factory()->create();
        $adminRole = Role::create(['name' => 'admin']);
        $this->adminUser->assignRole($adminRole);

        // Create the "Root" tag
        Tags::create(['name' => 'Root']);

        // Authenticate as admin
        $this->actingAs($this->adminUser);
    }

    /** @test */
    public function it_can_list_users()
    {
        $response = $this->getJson('/admin/users');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => ['id', 'name', 'email', 'role']
                     ]
                 ]);
    }

    /** @test */
    public function it_can_create_a_user()
    {
        $instance = Instance::factory()->create();

        $response = $this->postJson('/admin/users', [
            'name' => 'Test User',
            'email' => 'testuser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'user',
            'instance_id' => $instance->id,
        ]);

        $response->assertStatus(201)
                 ->assertJson([
                     'message' => 'User created successfully.',
                 ]);

        $this->assertDatabaseHas('users', [
            'email' => 'testuser@example.com',
        ]);
    }

    /** @test */
    public function it_can_update_a_user()
    {
        $user = User::factory()->create();
        $instance = Instance::factory()->create();

        $response = $this->putJson("/admin/users/{$user->id}", [
            'name' => 'Updated Name',
            'email' => 'updatedemail@example.com',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
            'instance_id' => $instance->id,
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'User updated successfully.',
                 ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'updatedemail@example.com',
        ]);
    }

    /** @test */
    public function it_can_delete_a_user()
    {
        $user = User::factory()->create();

        $response = $this->deleteJson("/admin/users/{$user->id}");

        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'User deleted successfully.',
                 ]);

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);
    }
}