<?php

namespace App\Http\Requests\Grading;

use App\Http\Requests\Grading\Concerns\ValidatesGradingScaleRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Configure a new grading scale for a class level, with its full set of percentage bands.
 *
 * No `status` field: a new scale is always ACTIVE - see ValidatesGradingScaleRecord's own
 * reasoning.
 */
class StoreGradingScaleRequest extends FormRequest
{
    use ValidatesGradingScaleRecord;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            $this->classLevelRule(),
            $this->identityRules($this->input('class_level_id')),
            $this->itemRules(),
        );
    }

    public function withValidator(Validator $validator): void
    {
        $this->addItemCoherenceValidation($validator);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->gradingScaleMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public function gradingScaleAttributes(): array
    {
        return $this->safe()->only(['class_level_id', 'name', 'code', 'sort_order', 'items']);
    }
}
