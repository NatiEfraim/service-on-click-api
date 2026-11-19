<?php

namespace App\Http\Resources;


use App\Traits\ResolvesBarberSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BarberOrderBookingResource extends JsonResource
{
    use ResolvesBarberSchedule;

    public function toArray(Request $request): array
    {
        $date = Carbon::parse($this->date);

        $bookingModel = $this->booking;
        $status = $bookingModel?->status;

        return [
            'id' => $this->id,
            'date' => $this->date,
            'day_name' => $this->hebrewDayName($date),
            'start_time' => optional(Carbon::parse($this->start_time ?? null))->format('H:i'),
            'end_time' => optional(Carbon::parse($this->end_time ?? null))->format('H:i'),
            'haircut_time' => $this->barberHaircutTime(),

            'user' => [
                'name' => $this->user?->full_name,
                'personal_id' => $this->user?->personal_id,
                'phone' => $this->user?->phone,
            ],

            'booking' => [
                'id' => $bookingModel?->id,
                'uuid' => $bookingModel?->uuid,
                'serial_number' => $bookingModel?->serial_number,

                'status' => $status ? [
                    'id' => $status->id,
                    'name' => $status->name,
                    'display_name' => $status->display_name,
                ] : null,
            ],
        ];
    }


    private function hebrewDayName(Carbon $date): string
    {
        return match ($date->dayOfWeek) {
            Carbon::SUNDAY => 'יום ראשון',
            Carbon::MONDAY => 'יום שני',
            Carbon::TUESDAY => 'יום שלישי',
            Carbon::WEDNESDAY => 'יום רביעי',
            Carbon::THURSDAY => 'יום חמישי',
            Carbon::FRIDAY => 'יום שישי',
            Carbon::SATURDAY => 'יום שבת',
        };
    }
}
