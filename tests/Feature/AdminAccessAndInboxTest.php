<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminAccessAndInboxTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('admin-panel', ['admin-panel'])->plainTextToken;
    }

    public function test_super_admin_assigns_only_known_roles_and_role_permissions_are_effective(): void
    {
        $root = User::create(['name' => 'Root', 'email' => 'root@example.test', 'password' => 'strong-password-123', 'is_admin' => true]);
        $reception = User::create(['name' => 'Reception', 'email' => 'reception-role@example.test', 'password' => 'strong-password-123']);
        $rootToken = $this->token($root);
        $roleId = DB::table('admin_roles')->where('name', 'reception')->value('id');
        $this->withToken($rootToken)->putJson('/api/admin/users/'.$reception->id.'/roles', ['role_ids' => [$roleId]])->assertOk();
        $this->assertTrue($reception->fresh()->canAccessAdmin());
        $this->assertTrue($reception->fresh()->hasAdminPermission('appointments.manage'));
        $this->assertFalse($reception->fresh()->hasAdminPermission('finance.manage'));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $reception->id]);

        $this->withToken($rootToken)->putJson('/api/admin/users/'.$reception->id.'/roles', ['role_ids' => [999999]])->assertUnprocessable();
        $receptionToken = $this->token($reception->fresh());
        app('auth')->forgetGuards();
        $this->withToken($receptionToken)->getJson('/api/admin/me')->assertOk()->assertJsonPath('user.id', $reception->id);
        $this->withToken($receptionToken)->patchJson('/api/admin/roles/'.$roleId, ['permissions' => ['finance.manage']])->assertForbidden();
    }

    public function test_last_super_admin_cannot_be_disabled_and_account_disable_revokes_tokens(): void
    {
        $root = User::create(['name' => 'Root', 'email' => 'root-disable@example.test', 'password' => 'strong-password-123', 'is_admin' => true]);
        $client = User::create(['name' => 'Client', 'email' => 'client-disable@example.test', 'password' => 'strong-password-123']);
        $rootToken = $this->token($root);
        $this->withToken($rootToken)->patchJson('/api/admin/users/'.$root->id, ['is_active' => false])->assertStatus(409);
        $this->withToken($rootToken)->patchJson('/api/admin/users/'.$client->id, ['is_active' => false])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $client->id]);
        $this->assertFalse((bool)$client->fresh()->is_active);
    }

    public function test_admin_inbox_is_permission_scoped_and_supports_read_state_and_unread_count(): void
    {
        $reception = User::create(['name' => 'Reception', 'email' => 'inbox-reception@example.test', 'password' => 'strong-password-123',
            'admin_permissions' => ['appointments.manage']]);
        $finance = User::create(['name' => 'Finance', 'email' => 'inbox-finance@example.test', 'password' => 'strong-password-123',
            'admin_permissions' => ['finance.view']]);
        $notice = DB::table('admin_notifications')->insertGetId(['permission' => 'appointments.manage', 'event' => 'booking.follow_up',
            'title' => 'Reception follow-up', 'body' => 'Booking requires reception follow-up.', 'resource_type' => 'booking',
            'resource_id' => 77, 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($this->token($reception))->getJson('/api/admin/notifications?unread=1')->assertOk()
            ->assertJsonPath('unread_count', 1)->assertJsonPath('data.data.0.id', $notice);
        $financeToken = $this->token($finance);
        app('auth')->forgetGuards();
        $this->withToken($financeToken)->getJson('/api/admin/me')->assertOk()->assertJsonPath('user.id', $finance->id);
        $this->withToken($financeToken)->getJson('/api/admin/notifications/'.$notice)->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken($this->token($reception))->postJson('/api/admin/notifications/'.$notice.'/read')->assertNoContent();
        $this->withToken($this->token($reception))->getJson('/api/admin/notifications?unread=1')->assertJsonPath('unread_count', 0);
        $this->assertDatabaseHas('admin_notification_reads', ['admin_notification_id' => $notice, 'user_id' => $reception->id]);
    }

    public function test_cms_public_routes_hide_drafts_and_only_publish_validated_entries(): void
    {
        $root = User::create(['name' => 'Root', 'email' => 'root-cms@example.test', 'password' => 'strong-password-123', 'is_admin' => true]);
        $draft = $this->withToken($this->token($root))->postJson('/api/admin/content/pages', [
            'title' => 'Draft page', 'slug' => 'draft-page', 'status' => 'draft', 'seo_title' => 'Draft SEO',
        ])->assertCreated()->json('data');
        $this->getJson('/api/content/pages')->assertOk()->assertJsonMissing(['slug' => 'draft-page']);
        $this->withToken($this->token($root))->putJson('/api/admin/content/pages/'.$draft['id'], ['status' => 'published'])
            ->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.seo_title', 'Draft SEO');
        $this->getJson('/api/content/pages/draft-page')->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_content_manager_can_upload_list_and_safely_delete_unreferenced_media(): void
    {
        Storage::fake('local');
        $admin = User::create(['name' => 'Content manager', 'email' => 'media-manager@example.test', 'password' => 'strong-password-123', 'admin_permissions' => ['content.manage']]);
        $token = $this->token($admin);
        $uploaded = $this->withToken($token)->post('/api/admin/gallery/upload', ['image' => UploadedFile::fake()->image('clinic.png')])->assertCreated()->json('data');
        $this->withToken($token)->getJson('/api/admin/content-media')->assertOk()->assertJsonPath('data.data.0.id', $uploaded['id']);
        $row = DB::table('cms_private_media')->find($uploaded['id']);
        $this->withToken($token)->deleteJson('/api/admin/content-media/'.$uploaded['id'])->assertNoContent();
        Storage::disk('local')->assertMissing($row->path);
        $this->assertDatabaseMissing('cms_private_media', ['id' => $uploaded['id']]);
    }

    public function test_counselor_public_visibility_filters_profiles_and_booking_eligibility(): void
    {
        $private = \App\Modules\Counselor\Models\Counselor::create(['name' => 'Hidden counselor', 'slug' => 'hidden-counselor', 'is_active' => true, 'is_public' => false]);
        $service = DB::table('booking_services')->insertGetId(['title' => 'Therapy', 'duration_minutes' => 60, 'price_irr' => 1000000, 'deposit_irr' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('counselor_booking_services')->insert(['counselor_id' => $private->id, 'booking_service_id' => $service, 'commission_type' => 'percentage', 'commission_value' => 20]);
        $this->getJson('/api/counselors')->assertOk()->assertJsonMissing(['slug' => 'hidden-counselor']);
        $this->getJson('/api/counselors/hidden-counselor')->assertNotFound();
        $this->getJson('/api/booking/availability?counselor_id='.$private->id.'&service_id='.$service.'&date=2026-10-20')->assertUnprocessable();
    }

    public function test_role_permission_can_manage_counselor_profile_visibility(): void
    {
        $manager = User::create(['name' => 'Counselor manager', 'email' => 'counselor-manager@example.test', 'password' => 'strong-password-123', 'admin_permissions' => ['counselors.manage']]);
        $response = $this->withToken($this->token($manager))->postJson('/api/admin/counselors', ['name' => 'Public counselor', 'slug' => 'public-counselor', 'is_public' => false])->assertCreated();
        $id = $response->json('data.id');
        $this->assertDatabaseHas('counselors', ['id' => $id, 'is_public' => false]);
        app('auth')->forgetGuards();
        $this->withoutHeader('Authorization')->getJson('/api/counselors/public-counselor')->assertNotFound();
        $this->withToken($this->token($manager))->putJson('/api/admin/counselors/'.$id, ['is_public' => true])->assertOk();
        app('auth')->forgetGuards();
        $this->withoutHeader('Authorization')->getJson('/api/counselors/public-counselor')->assertOk()->assertJsonPath('data.is_public', true);
        $this->assertDatabaseHas('booking_audit_events', ['action' => 'counselor.profile_updated']);
    }
}
