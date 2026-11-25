<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\SystemDomainEnum;
use App\Models\FormField;
use App\Models\OptionField;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Enforces the id contract of the three systems (SystemDomainEnum) after every seeding run - writes nothing, only
 * verifies and throws.
 *
 * מפקדת מחנה shares its ids one-to-one with the mobile app (the b2r/r2b files carry raw status_id / field_id /
 * option_id / role_id and both importers resolve them by id); ביטחון מידע and תקשוב each own a reserved block per
 * table, so adding rows to any one system never renumbers another. Rows created at runtime (custom fields, option
 * editors) live above FormField/OptionField::WEB_CREATED_FLOOR and are allowed on any system.
 *
 * A violation aborts the seeding loudly instead of letting the next b2r import land values on the wrong row.
 */
class SystemDomainContractSeeder extends Seeder
{
    public function run(): void
    {
        $this->assertHandlerRowsPinned();

        foreach (SystemDomainEnum::cases() as $domain) {
            $typeIds = $domain->bookingTypeIds();

            if ($typeIds === []) {
                continue;
            }

            $this->assertFieldsInBlock($domain, $typeIds);
            $this->assertOptionsInBlock($domain, $typeIds);
            $this->assertSystemReferencesInBlock($domain, $typeIds);
        }

        $this->assertStatusPairsStayInTheirSystem();
        $this->assertRolePairsStayInTheirSystem();
    }

    /** The mobile-owned "גורם מטפל" rows sit at their exact mobile ids. */
    private function assertHandlerRowsPinned(): void
    {
        $expected = [
            106 => 'vice_approver_handler_in_unit_food_booking',
            107 => 'vice_approver_handler_transportation_booking',
        ];

        foreach ($expected as $id => $fieldName) {
            $found = FormField::query()->find($id)?->field_name;

            if ($found !== $fieldName) {
                $this->violation("form field {$id} must be '{$fieldName}', found " . ($found === null ? 'nothing' : "'{$found}'"));
            }
        }

        $optionIds = OptionField::query()->whereIn('form_field_id', array_keys($expected))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($optionIds !== range(351, 360)) {
            $this->violation('the options of fields 106/107 must be exactly ids 351-360, found [' . implode(', ', $optionIds) . ']');
        }
    }

    /** Every seeded field of the system's types inside its block (web-created customs from WEB_CREATED_FLOOR allowed). */
    private function assertFieldsInBlock(SystemDomainEnum $domain, array $typeIds): void
    {
        [$floor, $ceiling] = $domain->fieldRange();

        $strays = FormField::query()
            ->whereIn('booking_type_id', $typeIds)
            ->where(fn ($q) => $q->where('id', '<', $floor)->orWhere('id', '>', $ceiling))
            ->where('id', '<', FormField::WEB_CREATED_FLOOR)
            ->get(['id', 'booking_type_id', 'field_name']);

        foreach ($strays as $field) {
            $this->violation("field '{$field->field_name}' (type {$field->booking_type_id}) sits at id {$field->id}, outside the {$domain->value} block {$floor}-{$ceiling}");
        }
    }

    /** Every seeded option of the system's types inside its block (web-created ones from WEB_CREATED_FLOOR allowed). */
    private function assertOptionsInBlock(SystemDomainEnum $domain, array $typeIds): void
    {
        [$floor, $ceiling] = $domain->optionRange();

        $strays = OptionField::query()
            ->join('form_fields', 'form_fields.id', '=', 'option_fields.form_field_id')
            ->whereIn('form_fields.booking_type_id', $typeIds)
            ->where(fn ($q) => $q->where('option_fields.id', '<', $floor)->orWhere('option_fields.id', '>', $ceiling))
            ->where('option_fields.id', '<', OptionField::WEB_CREATED_FLOOR)
            ->get(['option_fields.id', 'option_fields.name', 'form_fields.booking_type_id']);

        foreach ($strays as $option) {
            $this->violation("option '{$option->name}' (type {$option->booking_type_id}) sits at id {$option->id}, outside the {$domain->value} block {$floor}-{$ceiling}");
        }
    }

    /** Every system reference of the system's types inside its block. */
    private function assertSystemReferencesInBlock(SystemDomainEnum $domain, array $typeIds): void
    {
        [$floor, $ceiling] = $domain->systemReferenceRange();

        $strays = DB::table('system_references')
            ->whereIn('booking_type_id', $typeIds)
            ->where(fn ($q) => $q->where('id', '<', $floor)->orWhere('id', '>', $ceiling))
            ->get(['id', 'name', 'booking_type_id']);

        foreach ($strays as $reference) {
            $this->violation("system reference '{$reference->name}' (type {$reference->booking_type_id}) sits at id {$reference->id}, outside the {$domain->value} block {$floor}-{$ceiling}");
        }
    }

    /** A status inside a web system's block may only be linked to that system's booking types (shared 1-100 link anywhere). */
    private function assertStatusPairsStayInTheirSystem(): void
    {
        foreach ([SystemDomainEnum::INFOSEC, SystemDomainEnum::ICT] as $domain) {
            [$floor, $ceiling] = $domain->statusRange();
            $typeIds = $domain->bookingTypeIds();

            $strays = DB::table('booking_type_has_statuses')
                ->whereBetween('status_id', [$floor, $ceiling])
                ->when($typeIds !== [], fn ($q) => $q->whereNotIn('booking_type_id', $typeIds))
                ->get(['status_id', 'booking_type_id']);

            foreach ($strays as $pair) {
                $this->violation("status {$pair->status_id} belongs to the {$domain->value} block {$floor}-{$ceiling} but is linked to booking type {$pair->booking_type_id}");
            }
        }
    }

    /** A role inside a web system's block may only be linked to that system's booking types (shared 1-100 link anywhere). */
    private function assertRolePairsStayInTheirSystem(): void
    {
        foreach ([SystemDomainEnum::INFOSEC, SystemDomainEnum::ICT] as $domain) {
            [$floor, $ceiling] = $domain->roleRange();
            $typeIds = $domain->bookingTypeIds();

            $strays = DB::table('booking_type_has_roles')
                ->whereBetween('role_id', [$floor, $ceiling])
                ->when($typeIds !== [], fn ($q) => $q->whereNotIn('booking_type_id', $typeIds))
                ->get(['role_id', 'booking_type_id']);

            foreach ($strays as $pair) {
                $this->violation("role {$pair->role_id} belongs to the {$domain->value} block {$floor}-{$ceiling} but is linked to booking type {$pair->booking_type_id}");
            }
        }
    }

    private function violation(string $message): never
    {
        throw new \RuntimeException(
            "SystemDomainContractSeeder: {$message} - each system owns its id blocks and the mobile files resolve the camp ids one-to-one; "
            . 'renumber per docs/frontend-system-domains-guide.md before seeding.'
        );
    }
}
