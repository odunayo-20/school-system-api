<?php

namespace Database\Seeders;

use App\Enums\CatalogStatus;
use App\Models\GradingScale;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class AcademicCurriculumSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = [
            ['name' => 'English Language', 'code' => 'ENG', 'sort_order' => 1],
            ['name' => 'Mathematics', 'code' => 'MTH', 'sort_order' => 2],
            ['name' => 'Basic Science', 'code' => 'BSC', 'sort_order' => 3],
            ['name' => 'Social Studies', 'code' => 'SOS', 'sort_order' => 4],
            ['name' => 'Computer Studies', 'code' => 'CMP', 'sort_order' => 5],
            ['name' => 'Civic Education', 'code' => 'CVE', 'sort_order' => 6],
            ['name' => 'Biology', 'code' => 'BIO', 'sort_order' => 7],
            ['name' => 'Chemistry', 'code' => 'CHM', 'sort_order' => 8],
            ['name' => 'Physics', 'code' => 'PHY', 'sort_order' => 9],
        ];

        foreach ($subjects as $s) {
            Subject::firstOrCreate(['code' => $s['code']], $s);
        }

        $scale = GradingScale::firstOrCreate(
            ['code' => 'STD-SCALE'],
            [
                'class_level_id' => 3,
                'name' => 'Standard Universal Grading Scale',
                'code' => 'STD-SCALE',
                'sort_order' => 1,
                'status' => CatalogStatus::ACTIVE,
            ]
        );

        if ($scale->items()->count() === 0) {
            $bands = [
                ['grade' => 'A', 'min_percentage' => 75, 'max_percentage' => 100, 'grade_point' => 5.0, 'remark' => 'Distinction'],
                ['grade' => 'B', 'min_percentage' => 65, 'max_percentage' => 74.99, 'grade_point' => 4.0, 'remark' => 'Very Good'],
                ['grade' => 'C', 'min_percentage' => 50, 'max_percentage' => 64.99, 'grade_point' => 3.0, 'remark' => 'Credit'],
                ['grade' => 'D', 'min_percentage' => 45, 'max_percentage' => 49.99, 'grade_point' => 2.0, 'remark' => 'Pass'],
                ['grade' => 'E', 'min_percentage' => 40, 'max_percentage' => 44.99, 'grade_point' => 1.0, 'remark' => 'Fair'],
                ['grade' => 'F', 'min_percentage' => 0, 'max_percentage' => 39.99, 'grade_point' => 0.0, 'remark' => 'Fail'],
            ];
            foreach ($bands as $b) {
                $scale->items()->create($b);
            }
        }
    }
}
