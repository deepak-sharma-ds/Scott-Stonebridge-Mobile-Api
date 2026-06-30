<?php

namespace Tests\Feature\Admin;

use App\Models\AvailabilityDate;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $role = Role::findOrCreate('Admin', 'web');
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_non_admin_cannot_add_meeting(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.booking.store'), [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'availability_date' => now()->addDay()->toDateString(),
                'time_slot_id' => 1,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('scheduled_meetings', 0);
    }

    public function test_add_meeting_validation_fails_when_required_fields_missing(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->from(route('admin.scheduled-meetings'))
            ->post(route('admin.booking.store'), []);

        $response->assertRedirect(route('admin.scheduled-meetings'));
        $response->assertSessionHasErrors(['name', 'email', 'availability_date', 'time_slot_id']);
        $this->assertDatabaseCount('scheduled_meetings', 0);
    }

    public function test_add_meeting_with_valid_input_delegates_to_booking_service(): void
    {
        $availability = AvailabilityDate::create([
            'date' => now()->addDay()->toDateString(),
            'user_id' => $this->adminUser()->id,
        ]);
        $slot = TimeSlot::create([
            'availability_date_id' => $availability->id,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
        ]);

        // No Google token configured → BookingService returns a graceful error
        // (no external call), so the controller redirects back with api_error.
        $response = $this->actingAs($this->adminUser())
            ->from(route('admin.scheduled-meetings'))
            ->post(route('admin.booking.store'), [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'phone' => '+44 123456789',
                'availability_date' => $availability->date->toDateString(),
                'time_slot_id' => $slot->id,
            ]);

        $response->assertRedirect(route('admin.scheduled-meetings'));
        $response->assertSessionHasErrors('api_error');
        $this->assertDatabaseCount('scheduled_meetings', 0);
    }
}
