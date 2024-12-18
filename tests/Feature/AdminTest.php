<?php

namespace Tests\Feature;

use App\Models\Instance;
use App\Models\Tags;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminTest extends TestCase
{
    private $applicationUrl, $headerAdmin, $adminUser;

    public function setUp(): void
    {
        parent::setUp();

        $this->applicationUrl = env('APP_URL', 'http://localhost:8000');

        // Setup the admin user for authenticated user.
        $userAdmin = User::where('email', 'administrator@gmail.com')->first();
        $this->adminUser = $userAdmin;

        $this->headerAdmin = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        // Otomatis login untuk mendapatkan JWT token
        $this->authenticateUser();
    }

    private function authenticateUser(): void
    {
        // Lakukan permintaan login untuk mendapatkan token
        $response = $this->postJson("{$this->applicationUrl}/api/login", [
            'email' => $this->adminUser->email,
            'password' => 'inmydream205',
        ]);

        // Ambil token dari respons
        $this->headerAdmin['Authorization'] = 'Bearer ' . $response->json('accessToken') ?? null;
    }

    /**
     * Admin user can get list of user.
     */
    public function test_admin_can_get_list_user(): void
    {
        $response = $this->getJson("{$this->applicationUrl}/api/admin/users/list", $this->headerAdmin);

        $response->assertStatus(200);
    }

    /**
     * Admin user can get count total all users.
     */
    public function test_admin_can_get_count_total_all_users()
    {
        $response = $this->getJson("{$this->applicationUrl}/api/admin/users/countTotalUser", $this->headerAdmin);

        $response->assertJsonStructure([
            'total_user_count',
            'user_role_count',
            'admin_role_count'
        ])->assertStatus(200);
    }

    /**
     * Admin user can create user.
     */
    public function test_admin_can_create_user()
    {
        $instance = Instance::where('name', 'KemenkopUKM')->first();
        $instanceId = $instance->id;

        $userNewData = [
            'name' => 'John Doe',
            'email' => 'johndoe@gmail.com',
            'password' => 'johndoe123',
            'password_confirmation' => 'johndoe123',
            'role' => 'user',
            'instance_id' => $instanceId,
            'photo_profile' => UploadedFile::fake()->image('profile_john.png', 400, 450)
        ];

        $response = $this->postJson("{$this->applicationUrl}/api/admin/users/create_user", $userNewData, $this->headerAdmin);

        $response->assertJson([
            'message' => 'User created successfully.',
            'data' => [
                'name' => 'John Doe',
                'email' => 'johndoe@gmail.com',
                'role' => 'user',
                'instance' => [
                    'id' => $instanceId,
                    'name' => 'KemenkopUKM'
                ],
            ]
        ])->assertStatus(201);
    }

    /**
     * Admin user can get spesific user information
     */
    public function test_admin_can_get_spesific_user_information()
    {
        $getUserData = User::where('name', 'John Doe')->first();

        $response = $this->getJson("{$this->applicationUrl}/api/admin/users/info/{$getUserData->id}", $this->headerAdmin);

        if($response->){
            $response->assertJsonStructure([
                'data'
            ])->assertStatus(200);
        } else if () {
            
        }
    }

    /**
     * Admin user can update user information.
     */
    public function test_admin_can_update_user_information()
    {
        $userToBeUpdated = User::where('email', 'johndoe@gmail.com')->first();

        $userNewUpdatedData = [
            'name' => 'John Doe updated',
            'email' => 'johndoe2@gmail.com',
            'photo_profile' => UploadedFile::fake()->image('profile_john2.png', 400, 450)
        ];

        $response = $this->putJson("{$this->applicationUrl}/api/admin/users/update_user/{$userToBeUpdated->id}", $userNewUpdatedData, $this->headerAdmin);

        $response->assertJsonStructure([
            'message',
            'data'
        ])->assertStatus(200);
    }

    /**
     * Admin user can update spesific user password.
     */
    public function test_admin_can_update_user_password()
    {

    }

    /**
     * Admin user can delete spesific user.
     */
    public function test_admin_can_delete_user()
    {
        $userToBeDeleted = User::where('email', 'johndoe2@gmail.com')->first();

        $response = $this->deleteJson("{$this->applicationUrl}/api/admin/users/delete_user/{$userToBeDeleted->id}", $this->headerAdmin);

        $response->assertStatus(200);
    }


}
