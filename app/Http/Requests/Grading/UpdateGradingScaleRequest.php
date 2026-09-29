<?php

namespace App\Http\Requests\Grading;

use App\Http\Requests\Grading\Concerns\ValidatesGradingScaleRecord;
use App\Models\GradingScale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Amend a grading scale: its name, code, sort order, status, and its FULL set of percentage
 * bands - PUT is a whole-record write, so the bands are always resubmitted in full and
 * replaced as one unit, matching GradingService::update()'s own reasoning. class_level_id is
 * NOT accepted here at all - see GradingScale's own docblock for why the class level a scale
 * applies to has no path to change once created.
 */
class UpdateGradingScaleRequest extends FormRequest
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
        /** @var GradingScale $scale */
        $scale = $this->route('gradingScale');

        return array_merge(
            $this->identityRules($scale->class_level_id, $scale->getKey()),
            ['status' => $this->activeStatusRule($scale)],
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
        return $this->safe()->only(['name', 'code', 'sort_order', 'status', 'items']);
    }
}
