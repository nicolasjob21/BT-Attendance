<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function superadmin(): User
    {
        return User::where('username', 'brite-admin')->firstOrFail();
    }

    public function test_add_employee_flags_the_account_for_a_password_change(): void
    {
        $this->actingAs(User::where('username', 'brite-hr')->firstOrFail())->post('/employees', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@brite-tsi.com',
            'monthly_salary' => 28000,
            'status' => 'active',
            'password' => 'temp-pass-123',
        ])->assertRedirect(route('employees.index'));

        $this->assertTrue(User::where('email', 'juan@brite-tsi.com')->firstOrFail()->must_change_password);
    }

    public function test_admin_resetting_a_password_from_user_management_flags_it_too(): void
    {
        $tech = User::where('username', 'brite-tech')->firstOrFail();

        $this->actingAs($this->superadmin())->put("/users/{$tech->id}", [
            'name' => $tech->name,
            'username' => $tech->username,
            'email' => $tech->email,
            'role' => 'employee',
            'password' => 'temp-pass-123',
        ])->assertRedirect(route('users.index'));

        $this->assertTrue($tech->fresh()->must_change_password);
    }

    public function test_flagged_user_sees_the_modal_and_cannot_submit_other_forms(): void
    {
        $tech = User::where('username', 'brite-tech')->firstOrFail();
        $tech->setTemporaryPassword('temp-pass-123');

        $this->actingAs($tech)->get('/dashboard')
            ->assertOk()
            ->assertSee('Set your own password')
            ->assertSee('temporary password');

        // Any other write is bounced until the password is changed.
        $this->actingAs($tech)->from('/profile')->patch('/profile', ['name' => 'Hacker', 'email' => $tech->email])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors('password', null, 'temporaryPassword');
        $this->assertSame($tech->name, $tech->fresh()->name);
    }

    public function test_changing_the_temporary_password_clears_the_flag(): void
    {
        $tech = User::where('username', 'brite-tech')->firstOrFail();
        $tech->setTemporaryPassword('temp-pass-123');

        // Wrong temporary password, or reusing it, is rejected.
        $this->actingAs($tech)->from('/dashboard')->put('/password/temporary', [
            'current_password' => 'wrong',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
        ])->assertSessionHasErrors('current_password', null, 'temporaryPassword');

        $this->actingAs($tech)->from('/dashboard')->put('/password/temporary', [
            'current_password' => 'temp-pass-123',
            'password' => 'temp-pass-123',
            'password_confirmation' => 'temp-pass-123',
        ])->assertSessionHasErrors('password', null, 'temporaryPassword');

        $this->actingAs($tech)->from('/dashboard')->put('/password/temporary', [
            'current_password' => 'temp-pass-123',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
        ])->assertRedirect('/dashboard')->assertSessionHasNoErrors();

        $tech->refresh();
        $this->assertFalse($tech->must_change_password);
        $this->assertTrue(Hash::check('my-own-password', $tech->password));

        $this->actingAs($tech)->get('/dashboard')->assertOk()->assertDontSee('Set your own password');
    }

    public function test_flagged_user_can_still_sign_out(): void
    {
        $tech = User::where('username', 'brite-tech')->firstOrFail();
        $tech->setTemporaryPassword('temp-pass-123');

        $this->actingAs($tech)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
