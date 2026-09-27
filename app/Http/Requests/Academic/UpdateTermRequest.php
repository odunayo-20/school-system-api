<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Academic\Concerns\ValidatesTerm;
use App\Models\Term;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend a term's name, number and dates. Status is not accepted: see ValidatesTerm.
 *
 * The service additionally refuses any change to a COMPLETED term, and refuses to move a
 * term to a different session, so this request only ever amends a term within its own year.
 */
class UpdateTermRequest extends FormRequest
{
    use ValidatesTerm;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Term $term */
        $term = $this->route('term');

        $rules = $this->termRules();

        // Scoped to the term's own session and ignoring itself, so re-saving a term with
        // its own number does not collide while a second term in the same session still
        // cannot reuse the number.
        $rules['term_number'] = array_merge(
            $rules['term_number'],
            $this->termNumberUniqueness($term->academic_session_id, $term->getKey()),
        );

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->termMessages(), [
            'term_number.unique' => 'This academic session already has another term with this number.',
        ]);
    }
}
