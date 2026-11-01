<?php

namespace App\Http\Requests;

use App\Enums\HttpStatusCodeEnum;
use App\Enums\StatementShowTimeEnum;
use Illuminate\Validation\Rule;
use App\Models\BookingType;
use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class UpdateStatementRequest extends FormRequest
{

    private const STATEMENT_REGEX = '~^[\p{Hebrew}A-Za-z0-9\s,\.:!@%\*\$\(\)\{\}\[\]\/\|\\\\\-\'"]+$~u';

    public function __construct()
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    
    public function rules(): array
    {
        try {

            $unitId = $this->route('unitId');
            $unit = Unit::find($unitId);
            if (is_null($unit)) {
                throw ValidationException::withMessages(['message' => 'בקשת רשת אינה תקינה']);
            }

            $bookingTypeId = $this->route('bookingTypeId');
            $bookingType = BookingType::find($bookingTypeId);
            if (is_null($bookingType)) {
                throw ValidationException::withMessages(['message' => 'בקשת רשת אינה תקינה']);
            }

            return [
                'statement' => [
                    'nullable',
                    'string',
                    function ($attribute, $value, $fail) {
                        if (!$this->validateStatementValue($value)) {
                            $fail('אחד או יותר מהנתונים שנשלחו אינו תקין.');
                        }
                    },
                ],
                'is_active' => ['required', 'boolean'],
                // StatementShowTimeEnum: 0 = right after the form opens, 1 = when the user submits. Omitted keeps the current value.
                'show_time' => ['sometimes', 'integer', Rule::enum(StatementShowTimeEnum::class)],
            ];
        } catch (ValidationException $ve) {
            throw $ve;
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            app(\App\Services\StorageService::class)->logToS3();
            throw ValidationException::withMessages(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()])->status(Response::HTTP_INTERNAL_SERVER_ERROR);

        }
    }


    private function validateStatementValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return preg_match(self::STATEMENT_REGEX, $value) === 1;
    }


}
