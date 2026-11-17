<?php

namespace App\Http\Resources;
use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormFieldEnum;
use App\Enums\StatusBookingEnum;
use App\Enums\SystemReferenceEnum;
use App\Models\BookingValue;
use App\Services\AttachmentService;
use App\Services\BookingValueFormatterService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;





class ShowBookingResource extends JsonResource
{



    private readonly BookingValueFormatterService $bookingValueFormatterService;
    private array $selectedFieldTypeIds;
    public function __construct(
        $resource,
        ?BookingValueFormatterService $bookingValueFormatterService = null,
    )
    {
        parent::__construct($resource);
        $this->bookingValueFormatterService = $bookingValueFormatterService ?? app(BookingValueFormatterService::class);
        $this->selectedFieldTypeIds = [
            FieldTypeEnum::SELECT->value,
            FieldTypeEnum::RADIO->value,
            FieldTypeEnum::CHECKBOX->value,
            FieldTypeEnum::MULTI_SELECT->value,
        ];
    }

    public function toArray(Request $request): array
    {
        $cancellationData = $this->getCancellationData();

        return $this->removeNullValues([
            'uuid' => $this->uuid,
            'serial_number' => $this->serial_number,
            'booking_type' => $this->getBookingTypeData(),
            'status' => $this->getStatusData(),
            'user' => $this->getUserData(),
            'created_at' => $this->formatDate($this->created_at),
            'updated_at' => $this->formatDate($this->updated_at),
            'booking_values' => $this->bookingValueFormatterService->format($this->resource),
            'attachments' => app(AttachmentService::class)->describe($this->resource),
            'comments' => $this->getCommentsData(),
            'vice_approver' => $this->getViceApproverData(),
            'is_cancelable' => $cancellationData['is_cancelable'],
            'cancellation_advance_days' => $cancellationData['cancellation_advance_days'],
        ]);

    }

    private function removeNullValues(array $data): array
    {
        return array_filter($data, fn($value) => !is_null($value));
    }
    private function getBookingTypeData(): array
    {
        return $this->removeNullValues([
            'id' => $this->bookingType?->id,
            'name' => $this->bookingType?->name,
            'display_name' => $this->bookingType?->display_name,
            'icon' => $this->bookingType?->icon,
        ]);
    }

    private function getUserData(): array
    {
        return $this->removeNullValues([
            'uuid' => $this->user?->uuid,
            'full_name' => $this->user?->full_name,
            'personal_id' => $this->user?->personal_id,
            'phone' => $this->user?->phone,
        ]);
    }


    private function getStatusData(): array
    {
        return $this->removeNullValues([
            'stage' => $this->getStatusStage(),
            'name' => $this->status?->name,
            'id' => $this->status?->id,
            'display_name' => $this->status?->display_name,
            'additional_message' => $this->getStatusAdditionalMessage(),
        ]);
    }

    private function getStatusStage(): ?string
    {
        return $this->status
            ? StatusBookingEnum::from($this->status->id)?->getStatusStage()
            : null;
    }

    private function getStatusAdditionalMessage(): ?string
    {
        return $this->status
            ? StatusBookingEnum::from($this->status->id)?->getAdditionalMessage((int) $this->booking_type_id)
            : null;
    }

    private function formatDate(mixed $date): ?string
    {
        if (!$date) {
            return null;
        }

        return Carbon::parse($date)->format('d/m/Y');
    }

    private function getCommentsData(): array
    {
        return $this->comments
                ?->sortByDesc('created_at')
            ->map(function ($comment) {
                return $this->removeNullValues([
                    'id' => $comment->id,
                    'comment' => $comment->comment,
                    'created_at' => $comment->created_at,
                    'user' => [
                        'uuid' => $comment->user?->uuid,
                        'full_name' => $comment->user?->full_name,
                        'personal_id' => $comment->user?->personal_id,
                    ],
                ]);
            })
            ->values()
            ->all() ?? [];
    }
    
    /** Whether the booking can still be cancelled (as UpdateBookingRequest checks), plus cancellation_advance_days. */
    private function getCancellationData(): array
    {
        $cancelableStatusIds = [
            StatusBookingEnum::RECEIVED->value,
            StatusBookingEnum::PROCESSING->value,
            StatusBookingEnum::VICE_APPROVER_APPROVED->value,
            StatusBookingEnum::VICE_APPROVER_REJECTED->value,
            StatusBookingEnum::PROCESSING_BY_CONTROL_OFFICER->value,
            StatusBookingEnum::CONTROL_OFFICER_APPROVED->value,
            StatusBookingEnum::CONTROL_OFFICER_REJECTED->value,
        ];

        if (!in_array((int) $this->status_id, $cancelableStatusIds, true)) {
            return ['is_cancelable' => false, 'cancellation_advance_days' => null];
        }

        $bookingTypeId = (int) $this->booking_type_id;

        $supplyDateFormFieldId = match ($bookingTypeId) {
            BookingTypeEnum::IN_UNIT_FOOD_BOOKING->getBookingTypeId()
            => FormFieldEnum::IN_UNIT_SUPPLY_DATE->getFormFieldId(),

            BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId()
            => FormFieldEnum::OUT_UNIT_SUPPLY_DATE->getFormFieldId(),

            default => null,
        };

        if (!$supplyDateFormFieldId) {
            return ['is_cancelable' => true, 'cancellation_advance_days' => null];
        }

        $cancellationAdvanceDaysEnum = match ($bookingTypeId) {
            BookingTypeEnum::IN_UNIT_FOOD_BOOKING->getBookingTypeId()
            => SystemReferenceEnum::IN_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS,

            BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId()
            => SystemReferenceEnum::OUT_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS,

            default => null,
        };

        if (!$cancellationAdvanceDaysEnum) {
            return ['is_cancelable' => true, 'cancellation_advance_days' => null];
        }

        $supplyDateValue = BookingValue::where('booking_id', $this->id)
            ->where('form_field_id', $supplyDateFormFieldId)
            ->value('value');

        $supplyDate = $supplyDateValue ? FieldTypeEnum::parseDateValue($supplyDateValue) : null;

        if (!$supplyDate) {
            return ['is_cancelable' => true, 'cancellation_advance_days' => null];
        }

        $referenceValue = SystemReferenceEnum::getSystemReferenceValue(
            bookingTypeId: $bookingTypeId,
            systemReferenceId: $cancellationAdvanceDaysEnum->getSystemReferenceId(),
        );

        $cancellationAdvanceDays = (int) ($referenceValue['cancellation_advance_days'] ?? 0);

        if ($cancellationAdvanceDays <= 0) {
            return ['is_cancelable' => true, 'cancellation_advance_days' => null];
        }

        $lastAllowedCancellationDateTime = $this->subtractBusinessDays(
            date: $supplyDate->copy()->startOfDay(),
            days: $cancellationAdvanceDays,
        )->endOfDay();

        return [
            'is_cancelable' => Carbon::now()->lessThanOrEqualTo($lastAllowedCancellationDateTime),
            'cancellation_advance_days' => $cancellationAdvanceDays,
        ];
    }

    /** Subtracts business days (skipping Fri/Sat). */
    private function subtractBusinessDays(Carbon $date, int $days): Carbon
    {
        $cursor = $date->copy();
        $subtracted = 0;

        while ($subtracted < $days) {
            $cursor->subDay();

            if (!$cursor->isFriday() && !$cursor->isSaturday()) {
                $subtracted++;
            }
        }

        return $cursor;
    }

    private function getViceApproverData(): ?array
    {
        $viceApprover = $this->viceApprovers
                ?->sortByDesc('id')
            ->first();

        if (!$viceApprover) {
            return null;
        }

        return $this->removeNullValues([
            'user' => [
                'uuid' => $viceApprover->user?->uuid,
                'full_name' => $viceApprover->user?->full_name,
                'personal_id' => $viceApprover->user?->personal_id,
                'phone' => $viceApprover->user?->phone,
            ],
        ]);
    }

}
