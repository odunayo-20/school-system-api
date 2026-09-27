<?php

namespace Database\Seeders;

use App\Enums\CatalogStatus;
use App\Models\ClassLevel;
use Illuminate\Database\Seeder;

/**
 * The class levels a school is likely to run, offered as a starting point.
 *
 * These are SEED VALUES, not a fixed list: nothing in the application switches on a level
 * name, and a school that runs a Pre-School or a Technical stream adds its own rows. Each
 * entry is created with updateOrCreate() on the code, so a school that has renamed or
 * removed a level keeps its own version on the next seed instead of having it recreated
 * or duplicated.
 */
class AcademicStructureSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, code: string, sort_order: int}>
     */
    protected array $classLevels = [
        ['name' => 'Nursery', 'code' => 'NUR', 'sort_order' => 1],
        ['name' => 'Primary', 'code' => 'PRI', 'sort_order' => 2],
        ['name' => 'Junior Secondary', 'code' => 'JSS', 'sort_order' => 3],
        ['name' => 'Senior Secondary', 'code' => 'SSS', 'sort_order' => 4],
    ];

    public function run(): void
    {
        foreach ($this->classLevels as $classLevel) {
            ClassLevel::query()->updateOrCreate(
                ['code' => $classLevel['code']],
                [
                    'name' => $classLevel['name'],
                    'sort_order' => $classLevel['sort_order'],
                    'status' => CatalogStatus::ACTIVE,
                ],
            );
        }
    }
}
