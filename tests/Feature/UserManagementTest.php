<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('username', 'brite-admin')->firstOrFail();
    }

    private function hr(): User
    {
        return User::where('username', 'brite-hr')->firstOrFail();
    }

    public function test_only_users_with_manage_users_can_open_the_page(): void
    {
        $this->actingAs($this->hr())->get('/users')->assertForbidden();

        $this->actingAs($this->admin())->get('/users')
            ->assertOk()
            ->assertSee('User Management')
            ->assertSee('brite-hr')
            ->assertSee('brite-tech');
    }

    public function test_status_column_reflects_presence_disabled_and_deleted(): void
    {
        $tech = User::where('username', 'brite-tech')->firstOrFail();
        $tech->forceFill(['last_seen_at' => now()->subSeconds(30)])->save();
        $this->assertSame('online', $tech->presenceStatus());

        $tech->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
        $this->assertSame('offline', $tech->presenceStatus());

        $tech->forceFill(['disabled_at' => now()])->save();
        $this->assertSame('disabled', $tech->presenceStatus());

        $tech->delete();
        $this->assertSame('deleted', $tech->fresh()->presenceStatus());
    }

    public function test_presence_endpoint_returns_live_status_for_requested_ids(): void
    {
        $hr = $this->hr();
        $hr->forceFill(['last_seen_at' => now()])->save();

        $this->actingAs($this->admin())
            ->getJson('/users/presence?ids='.$hr->id.',999')
            ->assertOk()
            ->assertJsonPath("users.{$hr->id}.status", 'online')
            ->assertJsonMissingPath('users.999');
    }

    public function test_browsing_updates_last_seen_and_login_records_history(): void
    {
        $hr = $this->hr();
        $this->assertNull($hr->last_seen_at);

        $this->post('/login', ['username' => 'brite-hr', 'password' => 'password']);
        $hr->refresh();
        $this->assertNotNull($hr->last_login_at);
        $this->assertNotNull($hr->last_seen_at);

        $this->assertSame('online', $hr->presenceStatus());
    }

    public function test_admin_can_edit_an_account(): void
    {
        $tech = User::where('username', 'brite-tech')->firstOrFail();

        $this->actingAs($this->admin())->get("/users/{$tech->id}/edit")->assertOk()->assertSee('brite-tech');

        $this->actingAs($this->admin())->put("/users/{$tech->id}", [
            'name' => 'Tech Person',
            'username' => 'Brite-Techie',
            'email' => 'techie@brite-tsi.com',
            'role' => 'admin',
            'password' => 'newsecret1',
        ])->assertRedirect(route('users.index'));

        $tech->refresh();
        $this->assertSame('brite-techie', $tech->username);
        $this->assertSame('techie@brite-tsi.com', $tech->email);
        $this->assertTrue($tech->hasRole('admin'));
        $this->assertSame('Tech', $tech->employee->first_name);
        $this->assertTrue(\Hash::check('newsecret1', $tech->password));
    }

    public function test_disabled_account_cannot_sign_in_and_is_signed_out(): void
    {
        $tech = User::where('username', 'brite-tech')->firstOrFail();

        $this->actingAs($this->admin())
            ->patch("/users/{$tech->id}/disabled")
            ->assertSessionHas('status');
        $this->assertNotNull($tech->fresh()->disabled_at);

        // Correct password, still refused.
        $this->post('/logout');
        $this->post('/login', ['username' => 'brite-tech', 'password' => 'password'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();

        // An already-open session is ended on its next request.
        $this->actingAs($tech->fresh())->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        // Re-enable.
        $this->actingAs($this->admin())->patch("/users/{$tech->id}/disabled");
        $this->assertNull($tech->fresh()->disabled_at);
    }

    public function test_delete_requires_password_soft_deletes_and_can_be_restored(): void
    {
        $tech = User::where('username', 'brite-tech')->firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)->delete("/users/{$tech->id}", ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
        $this->assertNull($tech->fresh()->deleted_at);

        $this->actingAs($admin)->delete("/users/{$tech->id}", ['password' => 'password'])
            ->assertRedirect(route('users.index'));
        $this->assertSoftDeleted('users', ['id' => $tech->id]);

        // Deleted accounts cannot sign in.
        $this->post('/logout');
        $this->post('/login', ['username' => 'brite-tech', 'password' => 'password']);
        $this->assertGuest();

        // Listed under the Deleted filter, hidden from the default list.
        $this->actingAs($admin)->get('/users')->assertDontSee('brite-tech');
        $this->actingAs($admin)->get('/users?status=deleted')->assertSee('brite-tech');

        $this->actingAs($admin)->post("/users/{$tech->id}/restore")->assertRedirect(route('users.index'));
        $this->assertNull($tech->fresh()->deleted_at);
    }

    public function test_superadmin_has_no_self_service_and_admin_does(): void
    {
        $admin = $this->admin(); // superadmin: management only
        $this->actingAs($admin)->get('/attendance')->assertForbidden();
        $this->actingAs($admin)->get('/my-checkpoints')->assertForbidden();
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertDontSee('Clock In / Out');

        $hr = $this->hr(); // admin role keeps attendance
        $this->actingAs($hr)->get('/attendance')->assertOk();
        $this->actingAs($hr)->get('/dashboard')->assertOk()->assertSee('Clock In / Out');
    }

    public function test_roles_page_edits_permissions_and_keeps_superadmin_lock(): void
    {
        $admin = $this->admin();
        $this->actingAs($this->hr())->get('/users/roles')->assertForbidden();
        $this->actingAs($admin)->get('/users/roles')->assertOk()->assertSee('manage checkpoint settings');

        // Give employees "view team reports", take everything from superadmin except the lock.
        $this->actingAs($admin)->put('/users/roles', ['grants' => [
            'employee' => ['clock attendance' => '1', 'view team reports' => '1'],
            'superadmin' => [],
            'admin' => ['clock attendance' => '1'],
            'developer' => ['manage users' => '1'],
        ]])->assertRedirect(route('roles.index'));

        $this->assertTrue(Role::findByName('employee')->hasPermissionTo('view team reports'));
        $super = Role::findByName('superadmin');
        $this->assertTrue($super->hasPermissionTo('manage users'));
        $this->assertTrue($super->hasPermissionTo('manage roles'));
        $this->assertFalse($super->hasPermissionTo('run payroll'));

        $this->actingAs($admin)->post('/users/roles/reset')->assertRedirect(route('roles.index'));
        $this->assertTrue(Role::findByName('superadmin')->fresh()->hasPermissionTo('run payroll'));
    }

    public function test_add_employee_creates_employee_accounts_only(): void
    {
        $admin = $this->admin();
        $html = $this->actingAs($admin)->get('/employees/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('<select id="role"', $html);
        $this->assertStringContainsString('New employees always get the Employee role', $html);

        // Even a superadmin posting "developer" from the employee form gets an employee account.
        $this->actingAs($admin)->post('/employees', [
            'first_name' => 'Dev', 'last_name' => 'Two', 'email' => 'dev2@brite-tsi.com',
            'employee_type' => 'admin', 'role' => 'developer', 'monthly_salary' => 20000, 'status' => 'active',
        ])->assertRedirect();
        $this->assertTrue(User::where('email', 'dev2@brite-tsi.com')->first()->hasRole('employee'));

        // Developer is still assignable from User Management by the superadmin.
        $u = User::where('email', 'dev2@brite-tsi.com')->first();
        $this->actingAs($admin)->put("/users/{$u->id}", ['name' => $u->name, 'username' => $u->username, 'email' => $u->email, 'role' => 'developer'])
            ->assertRedirect(route('users.index'));
        $this->assertTrue($u->fresh()->hasRole('developer'));
    }

    public function test_admin_role_cannot_hand_out_roles_above_its_own(): void
    {
        $this->actingAs($this->hr())->post('/employees', [
            'first_name' => 'Sneaky', 'last_name' => 'Person', 'email' => 'sneaky2@brite-tsi.com',
            'employee_type' => 'admin', 'role' => 'superadmin', 'monthly_salary' => 20000, 'status' => 'active',
        ])->assertRedirect();
        $this->assertTrue(User::where('email', 'sneaky2@brite-tsi.com')->first()->hasRole('employee'));

        $dev = User::where('username', 'brite-dev')->firstOrFail();
        $tech = User::where('username', 'brite-tech')->firstOrFail();
        $this->actingAs($dev)->put("/users/{$tech->id}", [
            'name' => $tech->name, 'username' => $tech->username, 'email' => $tech->email, 'role' => 'superadmin',
        ])->assertSessionHasErrors('role');
        $this->actingAs($dev)->put("/users/{$tech->id}", [
            'name' => $tech->name, 'username' => $tech->username, 'email' => $tech->email, 'role' => 'admin',
        ])->assertRedirect(route('users.index'));
        $this->assertTrue($tech->fresh()->hasRole('admin'));
    }

    public function test_admin_cannot_disable_or_delete_themselves_or_the_last_superadmin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch("/users/{$admin->id}/disabled")->assertSessionHasErrors('account');
        $this->actingAs($admin)->delete("/users/{$admin->id}", ['password' => 'password'])->assertSessionHasErrors('account');
        $this->assertNull($admin->fresh()->disabled_at);
        $this->assertNull($admin->fresh()->deleted_at);

        $this->actingAs($admin)->put("/users/{$admin->id}", [
            'name' => $admin->name, 'username' => $admin->username, 'email' => $admin->email, 'role' => 'employee',
        ])->assertSessionHasErrors('role');
        $this->assertTrue($admin->fresh()->hasRole('superadmin'));
    }
}
