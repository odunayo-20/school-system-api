<?php

namespace App\Http\Resources;

use App\Models\GradingScaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One percentage band. Reused in two places - nested under GradingScaleResource's own `items`
 * list, and as the matched band GradingScaleController::calculate() returns - so both render
 * the identical shape rather than maintaining two divergent field lists for the same row.
 *
 * @mixin GradingScaleItem
 */
class GradingScaleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grade' => $this->grade,
            'min_percentage' => $this->min_percentage,
            'max_percentage' => $this->max_percentage,
            'grade_point' => $this->grade_point,
            'remark' => $this->remark,
        ];
    }
}
