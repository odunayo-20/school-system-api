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

        $assessmentTypes = [
            ['name' => 'Continuous Assessment', 'code' => 'CA', 'sort_order' => 1, 'status' => CatalogStatus::ACTIVE],
            ['name' => 'Class Test', 'code' => 'TEST', 'sort_order' => 2, 'status' => CatalogStatus::ACTIVE],
            ['name' => 'Terminal Examination', 'code' => 'EXAM', 'sort_order' => 3, 'status' => CatalogStatus::ACTIVE],
            ['name' => 'Project Assignment', 'code' => 'PRJ', 'sort_order' => 4, 'status' => CatalogStatus::ACTIVE],
        ];

        foreach ($assessmentTypes as $at) {
            \App\Models\AssessmentType::firstOrCreate(['code' => $at['code']], $at);
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
        // Standard classes and sections
        $classesByLevel = [
            2 => [ // Primary
                ['name' => 'Primary 1', 'code' => 'PRI-1', 'sort_order' => 1],
                ['name' => 'Primary 2', 'code' => 'PRI-2', 'sort_order' => 2],
                ['name' => 'Primary 3', 'code' => 'PRI-3', 'sort_order' => 3],
                ['name' => 'Primary 4', 'code' => 'PRI-4', 'sort_order' => 4],
                ['name' => 'Primary 5', 'code' => 'PRI-5', 'sort_order' => 5],
                ['name' => 'Primary 6', 'code' => 'PRI-6', 'sort_order' => 6],
            ],
            3 => [ // Junior Secondary
                ['name' => 'JSS 1', 'code' => 'JSS-1', 'sort_order' => 1],
                ['name' => 'JSS 2', 'code' => 'JSS-2', 'sort_order' => 2],
                ['name' => 'JSS 3', 'code' => 'JSS-3', 'sort_order' => 3],
            ],
            4 => [ // Senior Secondary
                ['name' => 'SSS 1', 'code' => 'SSS-1', 'sort_order' => 1],
                ['name' => 'SSS 2', 'code' => 'SSS-2', 'sort_order' => 2],
                ['name' => 'SSS 3', 'code' => 'SSS-3', 'sort_order' => 3],
            ],
        ];

        foreach ($classesByLevel as $levelId => $classList) {
            foreach ($classList as $cls) {
                $classModel = \App\Models\SchoolClass::firstOrCreate(
                    ['class_level_id' => $levelId, 'name' => $cls['name']],
                    [
                        'code' => $cls['code'],
                        'sort_order' => $cls['sort_order'],
                        'status' => CatalogStatus::ACTIVE,
                    ]
                );

                // Ensure at least 2 sections (arms) exist for every class
                \App\Models\Section::firstOrCreate(
                    ['school_class_id' => $classModel->id, 'name' => 'Section A'],
                    [
                        'code' => 'A',
                        'sort_order' => 1,
                        'status' => CatalogStatus::ACTIVE,
                    ]
                );

                \App\Models\Section::firstOrCreate(
                    ['school_class_id' => $classModel->id, 'name' => 'Section B'],
                    [
                        'code' => 'B',
                        'sort_order' => 2,
                        'status' => CatalogStatus::ACTIVE,
                    ]
                );
            }
        }

        // Also ensure any preexisting class has Section A & B
        foreach (\App\Models\SchoolClass::all() as $existingClass) {
            \App\Models\Section::firstOrCreate(
                ['school_class_id' => $existingClass->id, 'name' => 'Section A'],
                [
                    'code' => 'A',
                    'sort_order' => 1,
                    'status' => CatalogStatus::ACTIVE,
                ]
            );

            \App\Models\Section::firstOrCreate(
                ['school_class_id' => $existingClass->id, 'name' => 'Section B'],
                [
                    'code' => 'B',
                    'sort_order' => 2,
                    'status' => CatalogStatus::ACTIVE,
                ]
            );
        }
    }
}
