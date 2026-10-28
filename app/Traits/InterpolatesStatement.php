<?php

namespace App\Traits;

use App\Enums\StatementPlaceholderEnum;
use App\Models\SystemReference;
use Illuminate\Support\Facades\Auth;

trait InterpolatesStatement
{
    /** Interpolate a statement template's placeholders (e.g. {clean_time}) with system reference values. */
    public function interpolateStatement(?string $statement, int $unitId): ?string
    {
        if (is_null($statement)) {
            return null;
        }

        $placeholderValues = $this->getPlaceholderValues($unitId);

        $result = $statement;
        foreach ($placeholderValues as $placeholder => $value) {
            $result = str_replace($placeholder, $value, $result);
        }

        return $result;
    }

    /** Get all placeholder values for a given unit, as ['{placeholder}' => 'value'] pairs. */
    private function getPlaceholderValues(int $unitId): array
    {
        $values = [];

        foreach (StatementPlaceholderEnum::cases() as $placeholder) {
            $value = $this->getPlaceholderValue($placeholder, $unitId);
            if ($value !== null) {
                $values[$placeholder->getPlaceholder()] = (string) $value;
            }
        }

        return $values;
    }

    /** Get a single placeholder value from system references. */
    private function getPlaceholderValue(StatementPlaceholderEnum $placeholder, int $unitId): string|int|null
    {
        $systemReferenceId = $placeholder->getSystemReferenceId();
        $jsonKey = $placeholder->getJsonKey();
        $configFallback = $placeholder->getConfigFallback();

        $value = SystemReference::query()
            ->where('id', $systemReferenceId)
            ->where('unit_id', $unitId)
            ->value('value');

        if ($value) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && isset($decoded[$jsonKey])) {
                return $decoded[$jsonKey];
            }
        }

        // Fallback to config
        if ($configFallback) {
            return config($configFallback);
        }

        return null;
    }
}
