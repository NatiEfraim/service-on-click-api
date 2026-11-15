<?php

namespace App\Services;

use App\Enums\FieldTypeEnum;
use App\Enums\PermissionEnum;
use App\Enums\StatusBookingEnum;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\RequesterFormResource;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\BookingValue;
use App\Models\File;
use App\Models\FormField;
use App\Models\GroupBookingType;
use App\Models\OptionField;
use App\Models\Image;
use App\Models\UnitHasBookingType;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RequesterService
{
    public function __construct(private readonly StorageService $storageService)
    {
    }

    // -------------------------------------------------------------------------
    // GET /requester/catalog
    // -------------------------------------------------------------------------

    /**
     * Returns the booking types the authenticated user may submit,
     * split into topic-groups (infosec / ICT) and plain domains (all others).
     */
    public function getCatalog(): array
    {
        /** @var User $user */
        $user = Auth::user();

        $availableIds = $this->availableBookingTypeIds($user);

        // Booking types that belong to a topic-group (infosec / ICT).
        $topicGroups = GroupBookingType::whereNotNull('topic')
            ->with(['bookingTypes'])
            ->get();

        $topicTypeIds = $topicGroups->flatMap(fn ($g) => $g->bookingTypes->pluck('id'))->unique()->all();

        // Build the topic tree (filtered to available ids).
        $topics = $this->buildTopicTree($topicGroups, $availableIds);

        // Everything else is a plain domain.
        $domainIds = array_diff($availableIds, $topicTypeIds);
        $domains = BookingType::whereIn('id', $domainIds)->orderBy('position')->get();

        return [
            'topics' => $topics,
            'domains' => $domains->map(fn ($bt) => [
                'id' => $bt->id,
                'name' => $bt->name,
                'display_name' => $bt->display_name,
                'icon' => $bt->icon,
                'position' => $bt->position,
            ])->values(),
        ];
    }

    // -------------------------------------------------------------------------
    // GET /requester/form/{bookingTypeId}
    // -------------------------------------------------------------------------

    /**
     * Returns the root form fields (with options and children) for one booking type.
     * 403 is enforced at the route level via StoreBookingRequest::authorize().
     */
    public function getFormDetails(int $bookingTypeId): ?AnonymousResourceCollection
    {
        /** @var User $user */
        $user = Auth::user();

        $fields = FormField::where('booking_type_id', $bookingTypeId)
            ->where('unit_id', $user->unit_id)
            ->whereNull('form_field_id')
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
            ->with([
                'fieldType',
                'optionField.fieldType',
                'childFormFields.fieldType',
                'childFormFields.optionField.fieldType',
            ])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return RequesterFormResource::collection($fields);
    }

    // -------------------------------------------------------------------------
    // POST /bookings/{bookingTypeId}
    // -------------------------------------------------------------------------

    /**
     * Creates a new booking from a web-portal submission.
     * Returns the created Booking with its type and status loaded, or throws.
     */
    public function createBooking(StoreBookingRequest $request, int $bookingTypeId): Booking
    {
        /** @var User $user */
        $user = Auth::user();

        return DB::transaction(function () use ($request, $bookingTypeId, $user): Booking {
            $booking = Booking::create([
                'unit_id' => $user->unit_id,
                'booking_type_id' => $bookingTypeId,
                'user_id' => $user->id,
                'status_id' => StatusBookingEnum::RECEIVED->value,
                'status_source' => 'web',
                'status_changed_at' => now(),
                'is_exported' => false,
                'is_status_exported' => false,
            ]);

            $this->storeValues($booking, $request->input('values', []), $bookingTypeId, (int) $user->unit_id, $request->input('option_values', []));
            $this->storeFiles($booking, $request->file('files', []));

            return $booking->load(['bookingType', 'status']);
        });
    }

    // -------------------------------------------------------------------------
    // GET /requester/counters
    // -------------------------------------------------------------------------

    /**
     * Returns four counters for the authenticated user's bookings.
     */
    public function getCounters(): array
    {
        $userId = Auth::id();
        $unitId = (int) Auth::user()->unit_id;

        $terminalIds = StatusBookingEnum::terminalIds();

        $bookings = Booking::where('user_id', $userId)
            ->where('unit_id', $unitId)
            ->get(['status_id']);

        $open = $bookings->whereNotIn('status_id', $terminalIds)->count();
        $done = $bookings->where('status_id', StatusBookingEnum::COMPLETED->value)->count();

        // "action" = waiting for the user (PENDING_FILE = 8).
        $actionIds = [StatusBookingEnum::PENDING_FILE->value];
        $action = $bookings->whereIn('status_id', $actionIds)->count();

        // "handling" = open but NOT waiting for the user.
        $handling = $bookings->whereNotIn('status_id', $terminalIds)
            ->whereNotIn('status_id', $actionIds)
            ->count();

        return compact('open', 'action', 'handling', 'done');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<int> booking_type ids the user may submit
     */
    public function availableBookingTypeIds(User $user): array
    {
        $unitIds = UnitHasBookingType::where('unit_id', $user->unit_id)->pluck('booking_type_id');

        $permittedIds = $user->getAllPermissions()
            ->pluck('name')
            ->filter(fn ($p) => str_starts_with($p, PermissionEnum::USER->value))
            ->map(fn ($p) => (int) substr($p, strlen(PermissionEnum::USER->value)))
            ->values();

        // Management roles may open infosec / ICT types (topic groups, ids 14–19)
        // without holding a user_<id> permission.
        $managementRoles = ['super_admin', 'admin', 'approver', 'vice_approver', 'transportation_control_officer'];
        if ($user->hasAnyRole($managementRoles)) {
            $topicTypeIds = GroupBookingType::whereNotNull('topic')
                ->with('bookingTypes:id')
                ->get()
                ->flatMap(fn ($group) => $group->bookingTypes->pluck('id'));
            $permittedIds = $permittedIds->merge($topicTypeIds)->unique()->values();
        }

        return $permittedIds->intersect($unitIds)->values()->all();
    }

    /**
     * Groups topic-based booking types under their topics.
     * @return array<int, mixed>
     */
    private function buildTopicTree(
        \Illuminate\Database\Eloquent\Collection $topicGroups,
        array $availableIds,
    ): array {
        // topic string => ['id','name','display_name','icon','position','groups'[]]
        $tree = [];

        foreach ($topicGroups as $group) {
            $topic = $group->topic;

            if (!isset($tree[$topic])) {
                $tree[$topic] = [
                    'name' => $topic,
                    'display_name' => $this->topicDisplayName($topic),
                    'icon' => $group->icon,
                    'position' => $group->position,
                    'groups' => [],
                ];
            }

            $groupTypes = $group->bookingTypes
                ->filter(fn ($bt) => in_array($bt->id, $availableIds, true))
                ->map(fn ($bt) => [
                    'id' => $bt->id,
                    'name' => $bt->name,
                    'display_name' => $bt->display_name,
                    'description' => $bt->description,
                    'icon' => $bt->icon,
                    'position' => $bt->position,
                ])
                ->values();

            if ($groupTypes->isNotEmpty()) {
                $tree[$topic]['groups'][] = [
                    'id' => $group->id,
                    'name' => $group->name,
                    'display_name' => $group->display_name,
                    'position' => $group->position,
                    'booking_types' => $groupTypes,
                ];
            }
        }

        return array_values(array_filter($tree, fn ($t) => !empty($t['groups'])));
    }

    private function topicDisplayName(string $topic): string
    {
        return match ($topic) {
            'infosec' => 'ביטחון מידע',
            'ict' => 'תקשוב',
            default => $topic,
        };
    }

    /**
     * Stores booking_values rows from the submitted values map.
     * @param array<string, mixed> $values form_field_id => value
     * @param array<int|string, string|null> $typed a chosen "type in a value" choice's value, by its field id
     */
    private function storeValues(Booking $booking, array $values, int $bookingTypeId, int $unitId, array $typed = []): void
    {
        $fields = FormField::where('booking_type_id', $bookingTypeId)
            ->where('unit_id', $unitId)
            ->get()
            ->keyBy('id');

        foreach ($values as $fieldId => $value) {
            $field = $fields->get((int) $fieldId);
            if (!$field) {
                continue;
            }

            // Upload fields (FILE/IMAGE) are handled separately.
            if (FieldTypeEnum::isUploadFieldTypeId((int) $field->field_type_id)) {
                continue;
            }

            $optionFieldId = null;
            if ((int) $field->field_type_id === FieldTypeEnum::SELECT->value) {
                $optionFieldId = $value !== null ? (int) $value : null;
                // the value typed with a "type in a value" choice is kept next to the chosen choice
                $typedValue = trim((string) ($typed[$fieldId] ?? ''));
                $option = $optionFieldId ? OptionField::find($optionFieldId) : null;
                $isInput = $option && RequesterFormResource::asksForValue($option);
                $value = $isInput && $typedValue !== '' ? $typedValue : null;
            }

            BookingValue::create([
                'booking_id' => $booking->id,
                'form_field_id' => $field->id,
                'option_field_id' => $optionFieldId,
                'value' => $value !== null ? [$value] : null,
            ]);
        }
    }

    /**
     * Uploads files to the bucket and links them to the booking.
     * @param array<int, \Illuminate\Http\UploadedFile> $files
     */
    private function storeFiles(Booking $booking, array $files): void
    {
        $disk = config('filesystems.storage_service');
        $folder = 'portal-uploads/' . $booking->uuid;

        foreach ($files as $uploadedFile) {
            $extension = strtolower($uploadedFile->getClientOriginalExtension());
            $originalName = Str::slug(pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME));
            $fileName = ($originalName ?: Str::uuid()) . '.' . $extension;

            try {
                $objectKey = $this->storageService->putLocalFileToBucket(
                    $uploadedFile->getRealPath(),
                    $fileName,
                    $folder
                );
            } catch (\Throwable $e) {
                Log::error('RequesterService::storeFiles upload failed: ' . $e->getMessage());
                continue;
            }

            $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);

            if ($isImage) {
                $image = Image::create([
                    'name' => Str::uuid(),
                    'display_name' => $uploadedFile->getClientOriginalName(),
                    'path' => $objectKey,
                    'file_name' => basename($objectKey),
                ]);
                DB::table('booking_has_images')->insert([
                    'booking_id' => $booking->id,
                    'image_id' => $image->id,
                    'form_field_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $file = File::create([
                    'name' => Str::uuid(),
                    'display_name' => $uploadedFile->getClientOriginalName(),
                    'path' => $objectKey,
                    'file_name' => basename($objectKey),
                ]);
                DB::table('booking_has_files')->insert([
                    'booking_id' => $booking->id,
                    'file_id' => $file->id,
                    'form_field_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
