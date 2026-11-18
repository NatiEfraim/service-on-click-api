<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Finds a person's details from an ID number (תעודת זהות) for forms whose field carries lookup_source = "person" (the clearance grant):
 * the users table first (the mobile import and the logins fill it), then Vatican by the personal number found there (Vatican is asked
 * by personal number only; it does not look people up by ID number), to split the name into first and last as it holds them.
 * The result keys match form_fields.autofill_key: personal_number, first_name, last_name, rank. Rank is not held by any source
 * today and is always null, so that field stays open for the requester.
 */
class PersonLookupService
{
    public const SOURCE_LOCAL = 'local';
    public const SOURCE_VATICAN = 'vatican';

    public function __construct(private readonly UserService $users)
    {
    }

    /**
     * @return array{found: bool, available: bool, source: ?string, person: ?array{personal_number: ?string, first_name: ?string, last_name: ?string, rank: ?string}}
     *         available = false when the only source that could know the person could not be reached
     */
    public function byPersonalId(string $personalId): array
    {
        $normalized = UserService::normalizePersonalId($personalId);

        if ($normalized === null) {
            return $this->none();
        }

        $local = User::query()->where('personal_id', $normalized)->where('is_deleted', false)->orderBy('id')->first();

        if ($local === null) {
            return $this->none();
        }

        [$firstName, $lastName] = $this->splitName($local->full_name);
        $personalNumber = blank($local->personal_number) ? null : (string) $local->personal_number;
        $source = self::SOURCE_LOCAL;

        // Vatican holds the two halves of the name; ask it when we have a personal number to ask with.
        if ($personalNumber !== null && $this->vaticanConfigured()) {
            try {
                $vatican = $this->users->lookupFromVatican($personalNumber);
            } catch (\Throwable $e) {
                Log::warning('PersonLookupService: Vatican lookup failed: ' . $e->getMessage());
                $vatican = null;
            }

            if ($vatican !== null) {
                $firstName = $vatican['first_name'] ?? $firstName;
                $lastName = $vatican['surname'] ?? $lastName;
                $source = self::SOURCE_VATICAN;
            }
        }

        return [
            'found' => true,
            'available' => true,
            'source' => $source,
            'person' => [
                'personal_number' => $personalNumber,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'rank' => null,
            ],
        ];
    }

    /** @return array{found: bool, available: bool, source: ?string, person: ?array} */
    private function none(): array
    {
        return ['found' => false, 'available' => true, 'source' => null, 'person' => null];
    }

    private function vaticanConfigured(): bool
    {
        return filled(config('services.vatican.url')) && filled(config('services.vatican.token'));
    }

    /** A full name split at its last space: everything before it is the first name(s), the last word the family name. @return array{0: ?string, 1: ?string} */
    private function splitName(?string $fullName): array
    {
        $fullName = trim((string) $fullName);

        if ($fullName === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/u', $fullName) ?: [$fullName];

        if (count($parts) === 1) {
            return [$parts[0], null];
        }

        $lastName = array_pop($parts);

        return [implode(' ', $parts), $lastName];
    }
}
