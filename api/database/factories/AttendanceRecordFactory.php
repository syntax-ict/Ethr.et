<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AttendanceRecord> */
class AttendanceRecordFactory extends Factory
{
    public function definition(): array
    {
        $checkIn = now()->setTime(8, fake()->numberBetween(25, 45));

        return [
            'public_id' => (string) Str::ulid(),
            'date' => now()->format('Y-m-d'),
            'check_in' => $checkIn,
            'check_out' => $checkIn->copy()->addHours(8)->addMinutes(fake()->numberBetween(0, 30)),
            'source' => AttendanceSource::WEB,
            'confidence_score' => 75,
            'status' => AttendanceStatus::PRESENT,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function late(): static
    {
        return $this->state([
            'check_in' => now()->setTime(9, 15),
            'status' => AttendanceStatus::LATE,
        ]);
    }

    public function absent(): static
    {
        return $this->state([
            'check_in' => null,
            'check_out' => null,
            'status' => AttendanceStatus::ABSENT,
        ]);
    }

    public function biometric(): static
    {
        return $this->state([
            'source' => AttendanceSource::BIOMETRIC,
            'confidence_score' => 100,
        ]);
    }

    public function mobile(): static
    {
        return $this->state([
            'source' => AttendanceSource::MOBILE,
            'confidence_score' => 90,
            'latitude' => fake()->latitude(3.0, 15.0),
            'longitude' => fake()->longitude(33.0, 48.0),
            'geofence_verified' => true,
        ]);
    }

    public function checkInOnly(): static
    {
        return $this->state([
            'check_out' => null,
            'status' => AttendanceStatus::PENDING,
        ]);
    }
}
