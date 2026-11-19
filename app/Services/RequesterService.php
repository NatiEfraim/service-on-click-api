<?php

namespace App\Services;

use App\Domain\Workflow\Workflow;
use App\Enums\ActionTypeEnum;
use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormSectionEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\RequesterFormResource;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\BookingTypeHasRole;
use App\Models\BookingValue;
use App\Models\Comment;
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
    /** @var ?array<int, array<int, RoleEnum>> booking type id => its requester roles (from booking_type_has_roles) */
    private ?array $requesterRolesByType = null;

    public function __construct(
        private readonly StorageService $storageService,
        private readonly PersonLookupService $personLookup,
        private readonly AuditService $audit,
    ) {
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
     * Returns the root form fields (with options and children) for one booking type: the requester's section only - the
     * handler fields of a route type are filled during the handling, never on submission.
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
            ->section(FormSectionEnum::REQUESTER)
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
    // GET /requester/person-lookup
    // -------------------------------------------------------------------------

    /**
     * The details of a person by ID number, for a form that has a lookup field; the attempt is audited.
     * @return array{found: bool, available: bool, person: ?array<string, ?string>}
     */
    public function lookupPerson(User $user, int $bookingTypeId, string $personalId): array
    {
        $result = $this->personLookup->byPersonalId($personalId);

        $this->audit->record([
            'action' => 'person.lookup',
            'entity_type' => 'person',
            'entity_id' => $personalId,
            'unit_id' => $user->unit_id,
            'booking_type_id' => $bookingTypeId,
            'after' => ['found' => $result['found'], 'available' => $result['available'], 'source' => $result['source']],
        ], $user);

        return ['found' => $result['found'], 'available' => $result['available'], 'person' => $result['person']];
    }

    /** Whether the type's form (in the user's unit) has a field that looks a person up. */
    public function hasPersonLookup(int $bookingTypeId, ?int $unitId): bool
    {
        return FormField::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', $unitId)
            ->where('lookup_source', 'person')
            ->where('is_active', true)
            ->exists();
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
        $unitId = (int) $user->unit_id;

        return DB::transaction(function () use ($request, $bookingTypeId, $user, $unitId): Booking {
            $initialStatus = app(Workflow::class)->initialStatus($bookingTypeId);
            $webOnly = BookingTypeEnum::isWebOnlyId($bookingTypeId);

            $fields = FormField::where('booking_type_id', $bookingTypeId)
                ->where('unit_id', $unitId)
                ->get()
                ->keyBy('id');

            // The server does not trust the client for the fields a person lookup fills: look the person up again.
            [$values, $lookup] = $this->applyPersonLookup($fields, $request->input('values', []));

            $booking = Booking::create([
                'unit_id' => $unitId,
                'booking_type_id' => $bookingTypeId,
                'user_id' => $user->id,
                'status_id' => $initialStatus->value,
                'status_source' => 'web',
                'status_changed_at' => now(),
                'is_exported' => false,
                // A web-only type has nothing to send to the mobile app; every other type waits for the next status file as before.
                'is_status_exported' => $webOnly,
            ]);

            $this->storeValues($booking, $values, $fields, $request->input('option_values', []));
            $this->storeFiles($booking, $request->file('files', []));
            $this->storeComment($booking, $request->input('comment'));

            // The first row of the timeline (the "submitted" step of a progress bar): the creation, by the requester.
            ApplicationLog::query()->create([
                'booking_id' => $booking->id,
                'action_type_id' => ActionTypeEnum::STORE_BOOKING->value,
                'status_id' => $initialStatus->value,
                'source' => 'web',
                'correlation_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'is_exported' => false,
            ]);

            $this->audit->record([
                'action' => 'booking.create',
                'entity_type' => 'booking',
                'entity_id' => $booking->serial_number,
                'booking_serial' => $booking->serial_number,
                'unit_id' => $unitId,
                'booking_type_id' => $bookingTypeId,
                'after' => ['status_id' => $initialStatus->value, 'lookup' => $lookup],
                'source' => 'web',
            ], $user);

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
        $doneIds = app(Workflow::class)->completedStatusIds();

        $bookings = Booking::where('user_id', $userId)
            ->where('unit_id', $unitId)
            ->get(['status_id']);

        $open = $bookings->whereNotIn('status_id', $terminalIds)->count();
        // Completed (5), or the successful end of a type's own route (the clearance was granted).
        $done = $bookings->whereIn('status_id', $doneIds)->count();

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
     * The booking types the user may submit: the unit offers the type, and the user holds "{requester role}_{id}" for one of the
     * type's requester roles (user_{id} on every type but the ones with a requester role of their own, e.g. department_security_officer_14
     * on the clearance grant). Management roles may open every topic type whose requester is the plain user without holding user_{id};
     * the super admin every topic type, the ones with a dedicated requester role included. The domain handler of a type (infosec_officer_N,
     * ict_handler_N) may open that type when its requester is the plain user (15-25; not the clearance grant, which only its קב"ט opens).
     * @return array<int> booking_type ids the user may submit
     */
    public function availableBookingTypeIds(User $user): array
    {
        $unitIds = UnitHasBookingType::where('unit_id', $user->unit_id)->pluck('booking_type_id')->map(fn ($id): int => (int) $id)->all();
        $permissionNames = $user->getAllPermissions()->pluck('name')->all();
        $requesterRolesByType = $this->requesterRolesByType();

        $managementRoles = ['super_admin', 'admin', 'approver', 'vice_approver', 'transportation_control_officer'];
        $hasManagementRole = $user->hasAnyRole($managementRoles);
        $isSuperAdmin = $user->hasRole(RoleEnum::SUPER_ADMIN->toSpatieRoleName());

        $topicTypeIds = ($hasManagementRole || $isSuperAdmin)
            ? GroupBookingType::whereNotNull('topic')->with('bookingTypes:id')->get()->flatMap(fn ($group) => $group->bookingTypes->pluck('id'))->map(fn ($id): int => (int) $id)->all()
            : [];

        // The domain handler of an infosec / ICT type (infosec_officer_15, ict_handler_20) is a person of the unit like any other: they may open
        // the requests of the type they handle, although their permission is a handling one and they hold no user_{id} on it.
        $handledTypeIds = [];

        foreach ($permissionNames as $permissionName) {
            $parsed = PermissionEnum::parsePermissionName($permissionName);

            if ($parsed !== null && in_array($parsed['permission'], [PermissionEnum::INFOSEC_OFFICER, PermissionEnum::ICT_HANDLER], true)) {
                $handledTypeIds[] = $parsed['booking_type_id'];
            }
        }

        $permitted = [];

        foreach ($unitIds as $bookingTypeId) {
            $requesterRoles = $requesterRolesByType[$bookingTypeId] ?? [RoleEnum::USER];

            $holdsRequesterPermission = in_array(RoleEnum::USER, $requesterRoles, true) && in_array($bookingTypeId, $handledTypeIds, true);

            foreach ($requesterRoles as $role) {
                if (in_array($role->toPermissionEnum()->keyForId($bookingTypeId), $permissionNames, true)) {
                    $holdsRequesterPermission = true;
                    break;
                }
            }

            $plainRequester = in_array(RoleEnum::USER, $requesterRoles, true);
            $isTopicType = in_array($bookingTypeId, $topicTypeIds, true);

            if ($holdsRequesterPermission || ($isTopicType && ($isSuperAdmin || ($hasManagementRole && $plainRequester)))) {
                $permitted[] = $bookingTypeId;
            }
        }

        return $permitted;
    }

    /** @return array<int, array<int, RoleEnum>> booking type id => the requester roles the type has */
    private function requesterRolesByType(): array
    {
        if ($this->requesterRolesByType !== null) {
            return $this->requesterRolesByType;
        }

        $byType = [];

        foreach (BookingTypeHasRole::query()->get(['booking_type_id', 'role_id']) as $link) {
            $role = RoleEnum::tryFrom((int) $link->role_id);

            if ($role !== null && $role->isRequesterRole()) {
                $byType[(int) $link->booking_type_id][] = $role;
            }
        }

        return $this->requesterRolesByType = $byType;
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
     * When the form has a person lookup field, looks the submitted ID number up again and overwrites every autofill field with what
     * the source says; a person who is not found keeps what the requester typed.
     * @param \Illuminate\Support\Collection<int, FormField> $fields keyed by id
     * @param array<string, mixed> $values form_field_id => value
     * @return array{0: array<string, mixed>, 1: ?string} the values, and the lookup outcome (null when the form has no lookup field)
     */
    private function applyPersonLookup($fields, array $values): array
    {
        $lookupField = $fields->first(fn (FormField $field): bool => $field->lookup_source === 'person' && (bool) $field->is_active);

        if ($lookupField === null) {
            return [$values, null];
        }

        $personalId = trim((string) ($values[$lookupField->id] ?? $values[(string) $lookupField->id] ?? ''));

        if ($personalId === '') {
            return [$values, null];
        }

        $result = $this->personLookup->byPersonalId($personalId);

        if (!$result['found']) {
            return [$values, $result['available'] ? 'not_found' : 'unavailable'];
        }

        foreach ($fields as $field) {
            $key = $field->autofill_key;

            if ($key === null || !(bool) $field->is_active) {
                continue;
            }

            $looked = $result['person'][$key] ?? null;

            if ($looked !== null && $looked !== '') {
                $values[$field->id] = $looked;
            }
        }

        return [$values, 'found'];
    }

    /**
     * Stores booking_values rows from the submitted values map: the requester's root fields only.
     * @param array<string, mixed> $values form_field_id => value
     * @param \Illuminate\Support\Collection<int, FormField> $fields the type's fields keyed by id
     * @param array<int|string, string|null> $typed a chosen "type in a value" choice's value, by its field id
     */
    private function storeValues(Booking $booking, array $values, $fields, array $typed = []): void
    {
        foreach ($values as $fieldId => $value) {
            $field = $fields->get((int) $fieldId);
            if (!$field) {
                continue;
            }

            // The handler section is written during the route, never by the submission (the request refuses it too).
            if ($field->isHandlerField()) {
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
                // A scalar, as every other writer stores it (the import's encodeValue shape) - never wrapped in a list.
                'value' => $value === null ? null : (is_scalar($value) ? (string) $value : $value),
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

    /** Saves the requester's comment on the new booking when the text is not empty (as the mobile side's createBookingComment does). */
    private function storeComment(Booking $booking, mixed $comment): void
    {
        $comment = is_string($comment) ? trim($comment) : '';

        if ($comment === '') {
            return;
        }

        Comment::create([
            'booking_id' => $booking->id,
            'user_id' => Auth::id(),
            'comment' => $comment,
        ]);
    }
}
