<?php

namespace App\Http\Requests\Academic\Concerns;

use Illuminate\Validation\Rule;

/**
 * The shape of an academic session payload, shared by the create and amend requests so the
 * two cannot drift apart.
 *
 * Deliberately NO status field.
 *
 * A session's status is derived, never supplied. Exactly one session may be ACTIVE, and
 * making a session current necessarily completes the one before it, so status is only
 * reachable through the dedicated activate endpoint. If a client could also post
 * status = ACTIVE here it could create a second current session, and the database would
 * answer that with an integrity error the API would render as a 500 - a rule violation
 * reported as a server fault, for something the client had no way of knowing was wrong.
 * Removing the field removes that entire class of failure.
 *
 * The methods are named sessionRules()/sessionMessages() rather than rules()/messages() so
 * that a request using this trait can override rules() without shadowing the trait's own
 * implementation and recursing into it.
 */
trait ValidatesAcademicSession
{
    /**
     * Normalise the session name BEFORE it is validated.
     *
     * This has to happen here rather than in a model mutator. The name is uniquely indexed,
     * so if validation saw "2026-2027" and the model stored "2026/2027", a second session
     * named "2026/2027" would pass the uniqueness check and then collide on the index and
     * surface as a 500. Normalising first means the value that is checked and the value
     * that is stored are the same string.
     */
    public function prepareForValidation(): void
    {
        if ($this->filled('name')) {
            // People type the separator as a dash as often as a slash, and pad it with
            // spaces. "2026 - 2027", "2026-2027" and "2026/2027" are all the same year, so
            // the whitespace around the separator has to go too: replacing only the dash
            // would leave "2026 / 2027", which is a different string from the stored
            // "2026/2027" and would slip past the uniqueness check.
            $name = preg_replace('~\s*[-/]\s*~', '/', trim((string) $this->input('name')));

            $this->merge(['name' => $name]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function sessionRules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:50',
                // Scoped to the record being amended, so re-saving a session with its own
                // name does not collide with itself, while two different sessions still
                // cannot share one. The route parameter is {academicSession}, matching the
                // controller signature; the key must be spelled exactly as registered or
                // the record is not ignored and every amend collides with itself.
                Rule::unique('academic_sessions', 'name')->ignore($this->route('academicSession')),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function sessionMessages(): array
    {
        return [
            'name.required' => 'The session name is required, for example 2026/2027.',
            'name.unique' => 'An academic session with this name already exists.',
            'start_date.required' => 'The session start date is required.',
            'start_date.date' => 'The session start date must be a valid date.',
            'end_date.required' => 'The session end date is required.',
            'end_date.date' => 'The session end date must be a valid date.',
            'end_date.after' => 'The session must end after it starts.',
        ];
    }

    /**
     * The validated attributes in the shape the service expects.
     *
     * @return array<string, mixed>
     */
    public function sessionAttributes(): array
    {
        return $this->validated();
    }
}
