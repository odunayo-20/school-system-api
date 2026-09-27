<?php

namespace App\Http\Requests\Academic\Concerns;

use Illuminate\Validation\Rule;

/**
 * The shape of a term payload, shared by the create and amend requests.
 *
 * As with sessions there is deliberately NO status field. Exactly one term may be ACTIVE
 * across the whole school, and the active term must belong to the session that is itself
 * current; a client that could post status = ACTIVE directly would be able to create
 * exactly the contradictory state those rules exist to prevent, and would get an integrity
 * error back for it. The activate endpoint owns that transition.
 *
 * The date rules here are the ones decidable from the request alone. The rule that a term's
 * dates must fall inside its session's dates needs the session, so it lives in TermService,
 * which is reachable from code that never passed through a request.
 *
 * The methods are named termRules()/termMessages() rather than rules()/messages() so that a
 * request using this trait can override rules() to add its own term_number uniqueness check
 * without shadowing the trait's own implementation and recursing into it.
 */
trait ValidatesTerm
{
    /**
     * @return array<string, mixed>
     */
    protected function termRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'term_number' => ['required', 'integer', 'min:1', 'max:20'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function termMessages(): array
    {
        return [
            'name.required' => 'The term name is required, for example Second Term.',
            'term_number.required' => 'The term number is required. It is what orders the year, so it cannot be a label.',
            'term_number.integer' => 'The term number must be a whole number.',
            'term_number.min' => 'The term number must be 1 or greater.',
            'term_number.max' => 'The term number may not be greater than 20.',
            'start_date.required' => 'The term start date is required.',
            'start_date.date' => 'The term start date must be a valid date.',
            'end_date.required' => 'The term end date is required.',
            'end_date.date' => 'The term end date must be a valid date.',
            'end_date.after' => 'The term must end after it starts.',
        ];
    }

    /**
     * term_number is unique only WITHIN a session, so the rule is scoped to the parent
     * session rather than applied globally.
     *
     * @return list<mixed>
     */
    protected function termNumberUniqueness(int $sessionId, ?int $ignoreTermId = null): array
    {
        return [
            Rule::unique('terms', 'term_number')
                ->where('academic_session_id', $sessionId)
                ->ignore($ignoreTermId),
        ];
    }

    /**
     * The validated attributes in the shape the service expects.
     *
     * @return array<string, mixed>
     */
    public function termAttributes(): array
    {
        return $this->validated();
    }
}
