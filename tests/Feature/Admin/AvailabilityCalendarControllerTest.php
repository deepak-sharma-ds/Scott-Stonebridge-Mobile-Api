<?php

namespace Tests\Feature\Admin;

use App\Models\AvailabilityDate;
use App\Models\ScheduledMeeting;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_day_removes_all_slots_when_none_are_booked(): void
    {
        $user = User::factory()->create();
        $date = AvailabilityDate::create(['user_id' => $user->id, 'date' => '2026-08-01']);
        TimeSlot::create(['availability_date_id' => $date->id, 'start_time' => '09:00', 'end_time' => '10:00']);
        TimeSlot::create(['availability_date_id' => $date->id, 'start_time' => '10:00', 'end_time' => '11:00']);

        $response = $this->actingAs($user)
            ->deleteJson(route('admin.availability.calendar.day.delete', '2026-08-01'));

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('availability_dates', ['id' => $date->id]);
        $this->assertDatabaseCount('time_slots', 0);
    }

    public function test_delete_day_keeps_booked_slot_and_date_but_removes_unbooked_slots(): void
    {
        $user = User::factory()->create();
        $date = AvailabilityDate::create(['user_id' => $user->id, 'date' => '2026-08-02']);
        $bookedSlot = TimeSlot::create(['availability_date_id' => $date->id, 'start_time' => '09:00', 'end_time' => '10:00']);
        $freeSlot = TimeSlot::create(['availability_date_id' => $date->id, 'start_time' => '10:00', 'end_time' => '11:00']);

        ScheduledMeeting::create([
            'user_id' => $user->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '1234567890',
            'meeting_link' => 'https://example.com/meet',
            'event_id' => 'evt_1',
            'status' => 'confirmed',
            'datetime' => now()->addDay(),
            'availability_date_id' => $date->id,
            'time_slot_id' => $bookedSlot->id,
        ]);

        $response = $this->actingAs($user)
            ->deleteJson(route('admin.availability.calendar.day.delete', '2026-08-02'));

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('availability_dates', ['id' => $date->id]);
        $this->assertDatabaseHas('time_slots', ['id' => $bookedSlot->id]);
        $this->assertDatabaseMissing('time_slots', ['id' => $freeSlot->id]);
    }

    public function test_delete_day_returns_404_when_date_not_found(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->deleteJson(route('admin.availability.calendar.day.delete', '2026-08-03'));

        $response->assertNotFound()->assertJson(['success' => false, 'message' => 'Date not found']);
    }

    public function test_delete_day_returns_422_for_invalid_date(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->deleteJson('/admin/availability/calendar/day/not-a-date');

        $response->assertNotFound();
    }
}
