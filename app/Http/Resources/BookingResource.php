<?php

namespace App\Http\Resources;

use App\Enums\FieldTypeEnum;
use App\Enums\StatusBookingEnum;

use App\Services\BookingValueFormatterService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;



class BookingResource extends JsonResource
{

     private readonly BookingValueFormatterService $bookingValueFormatterService;
    private array $selectedFieldTypeIds;
    public function __construct(
        $resource,
     
    )
    {
        parent::__construct($resource);
        $this->bookingValueFormatterService =  app(BookingValueFormatterService::class);
        $this->selectedFieldTypeIds = [
            FieldTypeEnum::SELECT->value,
            FieldTypeEnum::RADIO->value,
            FieldTypeEnum::CHECKBOX->value,
            FieldTypeEnum::MULTI_SELECT->value,
        ];
    }

    public function toArray(Request $request): array
    {
        return $this->removeNullValues([
            'uuid' => $this->uuid,
            'serial_number' => $this->serial_number,
            'status' => $this->getStatusData(),
            'booking_type' => $this->getBookingType(),
            'booking_owner' => $this->getBookingOwner(),
            'created_at' => $this->formatDate($this->created_at),
            'updated_at' => $this->formatDate($this->updated_at),
            'vice_approver' => $this->getViceApprover(),
            'comments' => $this->getComments(),
            'next_actions' => $this->getNextStatuses(),
            'booking_values' => $this->bookingValueFormatterService->format($this->resource),
        ]);
    }

    private function removeNullValues(array $data): array
    {
        return array_filter($data, fn($value) => !is_null($value));
    }


    private function getStatusData(): array
    {
        return [

            'name' => $this->status?->name,
            'id' => $this->status?->id,
            'display_name' => $this->status?->display_name,
        ];
    }

    /** The status_id values the auth user can move this booking to now. */
    private function getNextStatuses(): array
    {
        $user = Auth::user();

        if (!$user) {
            return [];
        }

        $bookingTypeId = (int) $this->booking_type_id;

        $roleFlags = StatusBookingEnum::resolveRoleFlags(
            permissionNames: $user->workflowPermissionNames(),
            bookingTypeId: $bookingTypeId,
        );

        return StatusBookingEnum::tryFrom((int) $this->status_id)
            ?->getNextStatuses($roleFlags, $bookingTypeId)
            ?? [];
    }

    private function formatDate(mixed $date): ?string
    {
        if (!$date) {
            return null;
        }

        return Carbon::parse($date)->format('d/m/Y');
    }

    private function getBookingType(): array
    {
        return [
            'id' => $this->bookingType->id,
            'display_name' => $this->bookingType->display_name,
            'icon' => $this->bookingType->icon,
        ];
    }

    private function getBookingOwner(): array
    {
        return [
            'uuid' => $this->user->uuid,
            'full_name' => $this->user->full_name,
            'personal_id' => $this->user->personal_id,
            'phone' => $this->user->phone,
        ];
    }


    private function getViceApprover(): ?array
    {
        $vice = $this->viceApprovers->first();
        return $vice && !$vice->is_deleted ? [
            'uuid' => $vice->user->uuid,
            'full_name' => $vice->user->full_name,
            'personal_id' => $vice->user->personal_id,
            'phone' => $vice->user->phone,
        ] : null;
    }

    private function getComments(): array
    {
        return $this->comments->map(function ($comment) {
            return [
                'id' => $comment->id,
                'comment' => $comment->comment,
                'created_at' => $comment->created_at,
                'user' => [
                    'uuid' => $comment->user->uuid,
                    'full_name' => $comment->user->full_name,
                    'personal_id' => $comment->user->personal_id,
                    'phone' => $comment->user->phone,
                ],
            ];
        })->all();
    }


}


