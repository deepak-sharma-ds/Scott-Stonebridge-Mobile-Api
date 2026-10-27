<?php

namespace Tests\Feature\Admin;

use App\Models\AvailabilityDate;
use App\Models\ScheduledMeeting;
use App\Models\TimeSlot;
use App\Models\User;
use Carbon\Carbon;
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

    public function test_resync_command_executes_successfully(): void
    {
        $this->artisan('app:resync-calendar-dst')
            ->expectsOutput('No eligible scheduled meetings found to resync.')
            ->assertExitCode(0);
    }

    public function test_resync_command_filters_only_meetings_inserted_after_oct_25(): void
    {
        // Meeting created before cutoff
        $m1 = new ScheduledMeeting([
            'name' => 'Before Cutoff',
            'email' => 'before@example.com',
            'phone' => '1234567890',
            'meeting_link' => 'https://meet.google.com/abc-defg-hij',
            'datetime' => '2026-10-24 10:00:00',
            'event_id' => 'event_before_123',
            'status' => 'confirmed',
        ]);
        $m1->created_at = '2026-10-24 10:00:00';
        $m1->save();

        // Meeting created after cutoff
        $m2 = new ScheduledMeeting([
            'name' => 'After Cutoff',
            'email' => 'after@example.com',
            'phone' => '1234567890',
            'meeting_link' => 'https://meet.google.com/xyz-uvwx-rst',
            'datetime' => '2026-10-27 11:00:00',
            'event_id' => 'event_after_456',
            'status' => 'confirmed',
        ]);
        $m2->created_at = '2026-10-26 09:00:00';
        $m2->save();

        $this->artisan('app:resync-calendar-dst', ['--dry-run' => true])
            ->expectsOutputToContain('Found 1 meeting(s) to resync')
            ->expectsOutputToContain('After Cutoff')
            ->doesntExpectOutputToContain('Before Cutoff')
            ->assertExitCode(0);
    }

    public function test_london_timezone_dst_offsets_are_accurate(): void
    {
        $winter = Carbon::parse('2026-10-27 11:00:00', 'Europe/London');
        $this->assertSame('+00:00', $winter->format('P'));
        $this->assertSame('2026-10-27T11:00:00+00:00', $winter->toRfc3339String());

        $summer = Carbon::parse('2026-07-15 11:00:00', 'Europe/London');
        $this->assertSame('+01:00', $summer->format('P'));
        $this->assertSame('2026-07-15T11:00:00+01:00', $summer->toRfc3339String());
    }
}
