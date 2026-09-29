<?php

namespace App\Http\Requests\ReportCard;

use App\Http\Requests\ListRequest;

/**
 * Paging only, for GET /report-cards/students/{student}. No filters beyond per_page: the
 * student is already the whole scope (named by the route, not a query parameter), and a
 * student's entire report-card history is small enough that filtering by session or term adds
 * little value the brief itself cautions against building unless genuinely needed - see the
 * Module 14 audit §9.
 *
 * There is no `search` - a report-card history row holds no text field of its own, matching
 * every other list in this API that has none.
 */
class ReportCardListRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'search' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'search.prohibited' => 'Report-card history cannot be searched by text.',
        ]);
    }
}
