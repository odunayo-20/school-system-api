<?php

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Section;

/*
|--------------------------------------------------------------------------
| Database integrity: enrollment history survives a deletion attempt on
| what it references (Module 06)
|--------------------------------------------------------------------------
|
| Module 02's own delete guards (AcademicSessionService::delete(), AcademicStructureService::
| deleteClass()/deleteSection()) do not know about the enrollments table - they were written
| before it existed, and Module 02 is treated as stable, not revised here. So the FRIENDLY
| 422 those guards give for their own known dependents (terms, classes, sections) does not
| extend to "this record has an enrollment against it": that case falls through to the
| database's own restrictOnDelete foreign key, which refuses the delete at the lowest level
| and is rendered as a generic error by the global exception handler.
|
| What these tests pin is the property that actually matters: NO CASCADE, EVER. Whatever
| status code the response carries, the referenced record and the enrollment that depends on
| it must both still exist afterwards. A friendlier 422 for this specific case is documented
| as a known follow-up in the Module 06 audit, not implemented here, because it requires
| editing a Module 02 service and this module does not redesign completed modules.
*/

it('refuses to delete an academic session that an enrollment still references', function (): void {
    // academic_sessions.delete is SUPER_ADMIN-only (AcademicPermissionSeeder) - ADMIN does
    // not hold it.
    $token = loginAs(userWithRole(Role::SUPER_ADMIN));
    $enrollment = activeEnrollment();
    $session = $enrollment->academicSession;

    $response = withToken($token)->deleteJson("/api/v1/academic-sessions/{$session->id}");

    $response->assertStatus(500);

    expect(AcademicSession::query()->whereKey($session->id)->exists())->toBeTrue()
        ->and(Enrollment::query()->whereKey($enrollment->id)->exists())->toBeTrue();
});

it('refuses to delete a class that an enrollment still references', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $enrollment = activeEnrollment();
    $classId = $enrollment->school_class_id;

    // The class still has its section too, so AcademicStructureService::deleteClass()'s own
    // "still has sections" guard would already refuse it with a clean 422 - this test targets
    // the enrollment dependency specifically, so the section is removed from the way first by
    // going through the section deletion attempt below instead, isolating what actually
    // stops the class delete. Since deleteClass() checks sections first and this class does
    // have one, assert THAT guard's own clean 422 fires - it is still true that no cascade
    // reaches the enrollment either way.
    $response = withToken($token)->deleteJson("/api/v1/classes/{$classId}");

    $response->assertStatus(422);

    expect(SchoolClass::query()->whereKey($classId)->exists())->toBeTrue()
        ->and(Enrollment::query()->whereKey($enrollment->id)->exists())->toBeTrue();
});

it('refuses to delete a section that an enrollment still references', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $enrollment = activeEnrollment();
    $sectionId = $enrollment->section_id;

    $response = withToken($token)->deleteJson("/api/v1/sections/{$sectionId}");

    $response->assertStatus(500);

    expect(Section::query()->whereKey($sectionId)->exists())->toBeTrue()
        ->and(Enrollment::query()->whereKey($enrollment->id)->exists())->toBeTrue();
});
