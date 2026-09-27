<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\Academic\Concerns\ValidatesTerm;
use App\Models\AcademicSession;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a term to an academic session.
 *
 * The session comes from the route, so term_number is checked for uniqueness within THAT
 * session and not across the school, and the session is never read from the body: a term
 * cannot be filed under a different session than the one being addressed.
 */
class StoreTermRequest extends FormRequest
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
        // Bound to the model by the controller's {academicSession} parameter, so the key
        // has to be spelled exactly as the route registers it. session comes from the URL
        // and never from the body, so a term cannot be filed under a different session than
        // the one being addressed.
        /** @var AcademicSession $session */
        $session = $this->route('academicSession');

        $rules = $this->termRules();
        $rules['term_number'] = array_merge($rules['term_number'], $this->termNumberUniqueness($session->getKey()));

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->termMessages(), [
            'term_number.unique' => 'This academic session already has a term with this number.',
        ]);
    }
}
