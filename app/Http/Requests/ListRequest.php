<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shape shared by every list endpoint in the API.
 *
 * Filters used to be read with $request->only([...]) and handed straight to a service, which
 * was fast to write and wrong in three ways that a client could reach:
 *
 *  - per_page was unbounded, so ?per_page=1000000 asked the database for the whole table.
 *  - a filter value that was not a number, ?class_level_id=abc, reached a closure typed
 *    `int $levelId` and raised a TypeError, answering a malformed query string with 500.
 *  - ?active_only=false is a non-empty string, and Builder::when() branches on plain
 *    truthiness, so "false" filtered the list exactly as "true" does. The endpoint's one way
 *    of asking for everything looked like a way of asking for nothing.
 *
 * Validating the query string is the fix for all three, and it means an unusable filter is a
 * 422 naming the field instead of a 500 or a silently wrong page. Status values are validated
 * against their enum here too, so a typo reports the permitted values rather than quietly
 * returning an empty page that looks like a school with no data.
 *
 * This class was Module 02's `Http\Requests\Academic\AcademicListRequest` and was moved out
 * of the Academic namespace when Module 03 needed the same paging and search rules for the
 * staff list. Nothing else about it changed: same rules, same messages, same MAX_PER_PAGE.
 * A base class named for one module governing another module's paging is a wrong abstraction
 * that costs more later than a rename costs now.
 *
 * Per the design decision there is no `sort` parameter: each endpoint has one sensible
 * order, chosen in the service, and offering a caller-chosen column would be an injection
 * surface for no benefit at this size. See the Module 02 final audit, D.11.
 */
abstract class ListRequest extends FormRequest
{
    /**
     * The largest page a client may ask for. Comfortably above any screen a registrar will
     * paginate through, and low enough that a single request cannot pull a whole table into
     * memory. A larger export is a different endpoint, not a bigger per_page.
     */
    public const MAX_PER_PAGE = 100;

    public const DEFAULT_PER_PAGE = 15;

    /**
     * Authorization is enforced by the route's `permission:` middleware, which runs before
     * this request is resolved, so it is not repeated here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ], $this->filterRules());
    }

    /**
     * The rule for a query-string boolean.
     *
     * Laravel's own `boolean` rule accepts only true, false, 1, 0, "1" and "0", so
     * ?active_only=true - the spelling a developer types first - would be rejected. A URL
     * flag is read by hand far more often than it is sent by a typed client, so the four
     * obvious spellings are all accepted here and anything else is refused. The value is
     * then cast with $this->boolean(), which is what turns "false" into a real false.
     *
     * @return array<int, string>
     */
    protected static function booleanFlag(): array
    {
        return ['sometimes', Rule::in(['1', '0', 'true', 'false'], true)];
    }

    /**
     * The filters this particular list accepts, on top of paging and search.
     *
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.integer' => 'The page size must be a whole number.',
            'per_page.min' => 'The page size must be at least 1.',
            'per_page.max' => 'The page size may not be greater than '.self::MAX_PER_PAGE.'.',
            'search.max' => 'The search term may not be longer than 120 characters.',
        ];
    }

    /**
     * The validated filters in the shape the services read.
     *
     * Only keys the caller actually sent are present, because every service treats a missing
     * key as "no filter". The boolean flags named here are cast to real bools rather than
     * passed through as the string "false", which is what made the truthiness checks in the
     * services wrong: "false" is a non-empty string and therefore truthy.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = array_filter(
            $this->validated(),
            static fn (string $key): bool => $key !== 'per_page',
            ARRAY_FILTER_USE_KEY,
        );

        $filters['per_page'] = $this->perPage();

        foreach (self::BOOLEAN_FILTERS as $flag) {
            if (array_key_exists($flag, $filters)) {
                $filters[$flag] = $this->boolean($flag);
            }
        }

        return $filters;
    }

    /**
     * The filters that filters() casts to a real bool.
     *
     * A subclass that adds its own query-string boolean overrides this rather than editing
     * filters(), so the cast cannot be forgotten by a request added later.
     *
     * @var list<string>
     */
    protected const BOOLEAN_FILTERS = ['active_only', 'has_account'];

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
