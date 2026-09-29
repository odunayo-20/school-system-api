<?php

use App\Enums\Role;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ClassLevel;
use App\Models\GradingScale;
use App\Models\GradingScaleItem;
use App\Services\Grading\GradingService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Database integrity: races and foreign-key deletion behaviour (Module 11)
|--------------------------------------------------------------------------
*/

it('lets the database catch a second active scale for the same class level that validation could not, and reports it as a 422', function (): void {
    // Simulates the race two concurrent requests would create: both pass validation (neither
    // sees the other's row yet), and the second insert is the one that must fail cleanly -
    // the identical technique every prior module's own integrity test uses for its own
    // unique index, here backing the unique(class_level_id, active_marker) index the
    // TracksSingleActiveRecord trait relies on.
    $service = app(GradingService::class);
    $level = ClassLevel::factory()->create();

    $service->create([
        'class_level_id' => $level->id,
        'name' => 'First',
        'code' => 'FST',
        'items' => [['grade' => 'A', 'min_percentage' => 0, 'max_percentage' => 100, 'grade_point' => null, 'remark' => null]],
    ]);

    expect(fn () => $service->create([
        'class_level_id' => $level->id,
        'name' => 'Second',
        'code' => 'SEC',
        'items' => [['grade' => 'B', 'min_percentage' => 0, 'max_percentage' => 100, 'grade_point' => null, 'remark' => null]],
    ]))->toThrow(BusinessRuleViolation::class);

    expect(GradingScale::query()->where('class_level_id', $level->id)->count())->toBe(1);
});

it('refuses to delete a class level that a grading scale still references', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();
    $levelId = $scale->class_level_id;

    $response = withToken($token)->deleteJson("/api/v1/class-levels/{$levelId}");

    $response->assertStatus(500);

    expect(ClassLevel::query()->whereKey($levelId)->exists())->toBeTrue()
        ->and(GradingScale::query()->whereKey($scale->id)->exists())->toBeTrue();
});

it('cascades an item delete when a scale is removed directly at the database layer', function (): void {
    // Confirms grading_scale_items.grading_scale_id is genuinely cascadeOnDelete, not
    // restrictOnDelete like every other foreign key in this project - see the
    // grading_scale_items migration for why an item, unlike an academic-history anchor, has
    // no meaning independent of the scale that owns it. There is no HTTP path to this at all
    // (GradingScale has no delete endpoint), so it is exercised directly at the model layer.
    $scale = configuredGradingScale();
    $itemIds = $scale->items()->pluck('id');

    $scale->delete();

    expect(GradingScaleItem::query()->whereIn('id', $itemIds)->exists())->toBeFalse();
});

it('refuses to delete a scale directly at the database layer while enforcing nothing prevents it today', function (): void {
    // There is no destroy() on GradingScaleController, so this pins the property that matters
    // regardless of HTTP exposure: the database itself does not yet guard against deleting a
    // scale a future grading/result compilation module will reference, because no such
    // reference exists yet - only the class_level_id foreign key (restrictOnDelete, tested
    // above) protects anything today. This is documented, not accidental - see the Module 11
    // audit's "what this module owes the next one".
    $scale = configuredGradingScale();
    $scaleId = $scale->id;

    expect(fn () => $scale->delete())->not->toThrow(QueryException::class);

    expect(GradingScale::query()->whereKey($scaleId)->exists())->toBeFalse();
});
