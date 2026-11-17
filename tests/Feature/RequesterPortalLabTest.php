<?php

namespace Tests\Feature;

use App\Enums\FieldTypeEnum;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The portal lab: every booking type the requester catalog offers is submitted end to end from the web portal
 * (POST /bookings/{bookingTypeId} with values, option values, files and a comment), then read back by its uuid
 * (GET /bookings/{bookingUuid}) - and every submitted value must come back in the JSON.
 *
 * Field kinds are asserted by what the portal promises: exact round-trip for text/textarea/tel/email, numeric for
 * numbers, d/m/Y for dates, H:i for times, the chosen option (with its typed value) for selects, truthy for the
 * declaration checkboxes; the legacy mobile-form kinds (radio / multi select / multi date) are asserted to come back
 * as a row, and their raw shape is recorded in the lab report the test writes.
 */
class RequesterPortalLabTest extends ManagementTestCase
{
    private const COMMENT = 'בדיקת מעבדה של הפורטל';

    /** A tiny real PNG (1x1), so the upload passes the mimes rule without the GD extension. */
    private function fakePng(string $name): UploadedFile
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function fakePdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
    }

    /** The whole catalog as one flat list: [booking_type_id => ['label' => ..., 'origin' => topic name or 'domain']]. */
    private function catalogTypes(): array
    {
        $catalog = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/requester/catalog')
            ->assertOk()
            ->json('data');

        $types = [];

        foreach ($catalog['topics'] ?? [] as $topic) {
            foreach ($topic['groups'] ?? [] as $group) {
                foreach ($group['booking_types'] ?? [] as $type) {
                    $types[(int) $type['id']] = ['label' => $type['display_name'], 'origin' => $topic['name'] . '/' . $group['name']];
                }
            }
        }

        foreach ($catalog['domains'] ?? [] as $type) {
            $types[(int) $type['id']] = ['label' => $type['display_name'], 'origin' => 'domain'];
        }

        return $types;
    }

    /** The deepest first choice of an option tree, preferring one that does not ask for a typed value. */
    private function leafOption(array $options): ?array
    {
        $leaves = [];

        $walk = function (array $nodes) use (&$walk, &$leaves): void {
            foreach ($nodes as $node) {
                if (!empty($node['children'])) {
                    $walk($node['children']);
                } else {
                    $leaves[] = $node;
                }
            }
        };

        $walk($options);

        foreach ($leaves as $leaf) {
            if (empty($leaf['is_input'])) {
                return $leaf;
            }
        }

        return $leaves[0] ?? null;
    }

    /** A value for a "type in a value" choice, by the value's field type. */
    private function typedValueFor(?array $fieldType): string
    {
        return match ((int) ($fieldType['id'] ?? 0)) {
            FieldTypeEnum::NUMBER->value => '42',
            FieldTypeEnum::DATE->value => Carbon::now()->next(Carbon::MONDAY)->format('Y-m-d'),
            FieldTypeEnum::TIME->value => '08:30',
            default => 'ערך שהוקלד במעבדה',
        };
    }

    /**
     * Builds the POST payload for one form (root fields only, as the portal renders them) and the list of
     * expectations to check on the GET. A required choice field without root options cannot be satisfied from the
     * web (its choices hang under child form fields, which only the mobile store knows): it lands in the blocked
     * list instead. @return array{0: array, 1: array, 2: array, 3: array} [values, option_values, expectations, blocked_by]
     */
    private function payloadFor(array $fields, int $bookingTypeId): array
    {
        $values = [];
        $optionValues = [];
        $expectations = [];
        $blockedBy = [];

        $monday = Carbon::now()->next(Carbon::MONDAY);

        foreach ($fields as $field) {
            $fieldId = (int) $field['id'];
            $typeId = (int) ($field['field_type']['id'] ?? 0);
            $name = $field['field_name'];

            if (FieldTypeEnum::isUploadFieldTypeId($typeId)) {
                continue; // bytes go through files[], never through values
            }

            $expect = ['field_id' => $fieldId, 'name' => $name, 'type_id' => $typeId];

            switch ($typeId) {
                case FieldTypeEnum::SELECT->value:
                    $leaf = $this->leafOption($field['options'] ?? []);

                    if ($leaf === null) {
                        if (!empty($field['is_required'])) {
                            $blockedBy[] = $name;
                        }
                        continue 2;
                    }

                    $values[$fieldId] = $leaf['id'];
                    $expect['mode'] = 'select';
                    $expect['expected'] = (int) $leaf['id'];

                    if (!empty($leaf['is_input'])) {
                        $optionValues[$fieldId] = $this->typedValueFor($leaf['field_type'] ?? null);
                        $expect['mode'] = 'typed-select';
                        $expect['typed'] = $optionValues[$fieldId];
                    }
                    break;

                case FieldTypeEnum::NUMBER->value:
                case FieldTypeEnum::NUMBER_ASTERISK->value:
                case FieldTypeEnum::RANGE->value:
                    $values[$fieldId] = '42';
                    $expect['mode'] = 'equals';
                    $expect['expected'] = 42;
                    break;

                case FieldTypeEnum::DATE->value:
                    $values[$fieldId] = $monday->format('Y-m-d');
                    $expect['mode'] = 'exact';
                    $expect['expected'] = $monday->format('d/m/Y');
                    break;

                case FieldTypeEnum::TIME->value:
                    $values[$fieldId] = '08:30';
                    $expect['mode'] = 'exact';
                    $expect['expected'] = '08:30';
                    break;

                case FieldTypeEnum::CHECKBOX->value:
                    if (!empty($field['options'])) {
                        $leaf = $this->leafOption($field['options']);
                        $values[$fieldId] = [$leaf['id']];
                        $expect['mode'] = 'exists';
                    } else {
                        $values[$fieldId] = '1'; // a declaration checkbox: approved
                        $expect['mode'] = 'truthy';
                    }
                    break;

                case FieldTypeEnum::RADIO->value:
                case FieldTypeEnum::MULTI_SELECT->value:
                    $leaf = $this->leafOption($field['options'] ?? []);

                    if ($leaf === null) {
                        if (!empty($field['is_required'])) {
                            $blockedBy[] = $name;
                        }
                        continue 2;
                    }

                    $values[$fieldId] = $typeId === FieldTypeEnum::RADIO->value ? $leaf['id'] : [$leaf['id']];
                    $expect['mode'] = 'exists';
                    break;

                case FieldTypeEnum::MULTI_DATE->value:
                    $values[$fieldId] = [$monday->format('Y-m-d'), $monday->copy()->addDay()->format('Y-m-d')];
                    $expect['mode'] = 'exists';
                    break;

                case FieldTypeEnum::TEL->value:
                    $values[$fieldId] = '0521234567';
                    $expect['mode'] = 'exact';
                    $expect['expected'] = '0521234567';
                    break;

                case FieldTypeEnum::EMAIL->value:
                    $values[$fieldId] = 'lab@example.test';
                    $expect['mode'] = 'exact';
                    $expect['expected'] = 'lab@example.test';
                    break;

                default: // text, text box, text area and anything else typed free
                    $values[$fieldId] = 'בדיקת מעבדה ' . $name;
                    $expect['mode'] = 'exact';
                    $expect['expected'] = 'בדיקת מעבדה ' . $name;
            }

            $expectations[] = $expect;
        }

        return [$values, $optionValues, $expectations, $blockedBy];
    }

    /** The booking_values row of one form field on the GET body, flat or nested, else null. */
    private function rowOf(array $bookingValues, int $fieldId): ?array
    {
        foreach ($bookingValues as $row) {
            if ((int) ($row['form_field_id'] ?? 0) === $fieldId || (int) ($row['id'] ?? 0) === $fieldId && isset($row['field_name'])) {
                return $row;
            }
        }

        return null;
    }

    public function test_every_catalog_type_submits_from_the_portal_and_returns_every_value(): void
    {
        Storage::fake('minio');
        Storage::disk('minio')->buildTemporaryUrlsUsing(fn (string $path) => 'https://minio.test/signed/' . $path);

        $types = $this->catalogTypes();
        $this->assertNotEmpty($types, 'the catalog offers types to submit');

        $report = [];

        foreach ($types as $bookingTypeId => $meta) {
            $label = "type {$bookingTypeId} ({$meta['label']})";

            $this->app['auth']->forgetGuards();
            $fields = $this->actingAsPersonalNumber(self::PLAIN_USER)
                ->getJson("/api/requester/form/{$bookingTypeId}")
                ->assertOk()
                ->json('data');

            [$values, $optionValues, $expectations, $blockedBy] = $this->payloadFor($fields ?? [], $bookingTypeId);

            $this->app['auth']->forgetGuards();
            $created = $this->actingAsPersonalNumber(self::PLAIN_USER)
                ->post("/api/bookings/{$bookingTypeId}", [
                    'values' => $values,
                    'option_values' => $optionValues,
                    'files' => [$this->fakePng('תמונה מצורפת.png'), $this->fakePdf('מסמך מצורף.pdf')],
                    'comment' => self::COMMENT,
                ], ['Accept' => 'application/json']);

            if ($blockedBy !== []) {
                // A category field whose choices hang under child form fields: the web store cannot satisfy it today.
                $this->assertSame(422, $created->status(), "{$label}: the unfillable required choice blocks the submission");
                $report[] = ['booking_type_id' => $bookingTypeId, 'label' => $meta['label'], 'origin' => $meta['origin'], 'blocked_by' => $blockedBy, 'fields' => $fields];
                continue;
            }

            $this->assertSame(201, $created->status(), "{$label}: the portal submission is accepted - " . $created->content());
            $uuid = $created->json('data.uuid');
            $this->assertNotEmpty($uuid, "{$label}: the created booking carries its uuid");

            $this->app['auth']->forgetGuards();
            $shown = $this->actingAsPersonalNumber(self::PLAIN_USER)
                ->getJson("/api/bookings/{$uuid}")
                ->assertOk()
                ->json('data');

            // The envelope every form shares.
            $this->assertSame($uuid, $shown['uuid'], $label);
            $this->assertNotEmpty($shown['serial_number'] ?? null, "{$label}: a serial number was given");
            $this->assertSame($bookingTypeId, (int) $shown['booking_type']['id'], $label);
            $this->assertSame(1, (int) $shown['status']['id'], "{$label}: a new submission stands at received");
            $this->assertSame(
                \App\Models\User::where('personal_number', self::PLAIN_USER)->value('uuid'),
                $shown['user']['uuid'] ?? null,
                "{$label}: the requester block names the submitting user"
            );

            // Every submitted value came back.
            $bookingValues = $shown['booking_values'] ?? [];
            $rowsReport = [];

            foreach ($expectations as $expect) {
                $row = $this->rowOf($bookingValues, $expect['field_id']);
                $this->assertNotNull($row, "{$label}: field {$expect['name']} came back in booking_values");

                $where = "{$label}: field {$expect['name']}";

                switch ($expect['mode']) {
                    case 'exact':
                        $this->assertSame($expect['expected'], $row['value'] ?? null, $where);
                        break;
                    case 'equals':
                        $this->assertEquals($expect['expected'], $row['value'] ?? null, $where);
                        break;
                    case 'truthy':
                        $this->assertNotEmpty($row['value'] ?? null, $where);
                        break;
                    case 'select':
                    case 'typed-select':
                        $option = $row['option_fields'] ?? null;
                        $this->assertIsArray($option, "{$where} carries its chosen option");
                        $this->assertSame($expect['expected'], (int) ($option['option_id'] ?? 0), $where);
                        if ($expect['mode'] === 'typed-select') {
                            $this->assertEquals($expect['typed'], $option['value'] ?? null, "{$where}: the typed value");
                        }
                        break;
                    default: // exists
                        $this->assertTrue(
                            array_key_exists('value', $row) || array_key_exists('option_fields', $row),
                            "{$where} carries a value"
                        );
                }

                $rowsReport[] = ['sent' => $values[$expect['field_id']] ?? null, 'expect' => $expect, 'row' => $row];
            }

            // Both uploads are described, signed and marked available.
            $attachments = collect($shown['attachments'] ?? []);
            $this->assertCount(2, $attachments, "{$label}: both uploaded files are described");
            $this->assertEqualsCanonicalizing(['file', 'image'], $attachments->pluck('kind')->all(), $label);

            foreach ($attachments as $attachment) {
                $this->assertTrue((bool) $attachment['available'], "{$label}: the upload is available");
                $this->assertTrue((bool) $attachment['inline'], "{$label}: images and PDFs show inline");
                $this->assertStringStartsWith('https://minio.test/signed/portal-uploads/' . $uuid . '/', (string) $attachment['url'], $label);
            }

            // The comment travelled with the submission.
            $this->assertContains(self::COMMENT, array_column($shown['comments'] ?? [], 'comment'), $label);

            $report[] = [
                'booking_type_id' => $bookingTypeId,
                'label' => $meta['label'],
                'origin' => $meta['origin'],
                'fields' => $fields,
                'lab' => $rowsReport,
                'attachments' => $attachments->all(),
            ];
        }

        @mkdir(storage_path('framework/testing'), 0777, true);
        file_put_contents(
            storage_path('framework/testing/portal-lab-report.json'),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /** Whoever is not the owner (and not a manager of the unit) gets the same vague 404 the portal promises. */
    public function test_a_lab_booking_stays_private_to_its_owner(): void
    {
        Storage::fake('minio');

        $types = $this->catalogTypes();
        $bookingTypeId = array_key_first($types);

        $this->app['auth']->forgetGuards();
        $fields = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson("/api/requester/form/{$bookingTypeId}")
            ->assertOk()
            ->json('data');

        [$values, $optionValues] = $this->payloadFor($fields ?? [], $bookingTypeId);

        $this->app['auth']->forgetGuards();
        $uuid = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->post("/api/bookings/{$bookingTypeId}", ['values' => $values, 'option_values' => $optionValues], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.uuid');

        $this->app['auth']->forgetGuards();
        $this->actingAsPersonalNumber(self::OTHER_PLAIN_USER)
            ->getJson("/api/bookings/{$uuid}")
            ->assertNotFound();
    }
}
