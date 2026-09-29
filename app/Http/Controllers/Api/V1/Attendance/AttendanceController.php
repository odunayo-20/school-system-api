<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceListRequest;
use App\Http\Requests\Attendance\AttendanceSummaryRequest;
use App\Http\Requests\Attendance\StoreAttendanceBulkRequest;
use App\Http\Requests\Attendance\StoreAttendanceRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\EnrollmentResource;
use App\Models\Attendance;
use App\Services\Attendance\AttendanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recording and amending whether a specific student was present, absent, late or excused on a
 * specific date.
 *
 * There is no destroy(). Attendance is exactly the kind of academic-history record every prior
 * anchor table in this project (Enrollment, Score, Result, Promotion) already refuses to
 * delete - a mistaken mark is corrected through the ordinary PUT, not erased.
 *
 * Every read and write here is additionally scoped to the acting user by AttendanceService - a
 * holder of attendance.view/attendance.record/attendance.update is not thereby entitled to
 * every mark in the school; see the service's own docblock.
 */
class AttendanceController extends Controller
{
    protected const WITH = [
        'enrollment.student',
        'enrollment.academicSession',
        'enrollment.schoolClass',
        'enrollment.section',
        'recordedBy',
    ];

    public function __construct(protected AttendanceService $attendance) {}

    public function index(AttendanceListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            AttendanceResource::collection(
                $this->attendance->paginate($request->filters(), $request->user())
            )
        );
    }

    /**
     * Record a single attendance mark.
     */
    public function store(StoreAttendanceRequest $request): JsonResponse
    {
        $attendance = $this->attendance->create($request->attendanceAttributes(), $request->user());

        return ApiResponse::success(
            new AttendanceResource($attendance->load(self::WITH)),
            'Attendance recorded.',
            201,
        );
    }

    /**
     * Record a whole class register for one date in one call.
     */
    public function bulkStore(StoreAttendanceBulkRequest $request): JsonResponse
    {
        $records = $this->attendance->createBulk($request->bulkAttributes(), $request->user());

        return ApiResponse::success(
            AttendanceResource::collection(collect($records)->each->load(self::WITH)),
            'Attendance recorded.',
            201,
        );
    }

    public function show(Request $request, Attendance $attendance): JsonResponse
    {
        $attendance = $this->attendance->view($attendance, $request->user());

        return ApiResponse::success(new AttendanceResource($attendance));
    }

    /**
     * Correct a mark's status or remarks. See UpdateAttendanceRequest for what cannot be
     * reached this way - the placement and date it names, in particular.
     */
    public function update(UpdateAttendanceRequest $request, Attendance $attendance): JsonResponse
    {
        $updated = $this->attendance->update($attendance, $request->attendanceAttributes(), $request->user());

        return ApiResponse::success(
            new AttendanceResource($updated->load(self::WITH)),
            'Attendance updated.',
        );
    }

    /**
     * Present/absent/late/excused counts and a percentage for one enrollment.
     */
    public function summary(AttendanceSummaryRequest $request): JsonResponse
    {
        $summary = $this->attendance->summary($request->summaryFilters(), $request->user());

        return ApiResponse::success([
            'enrollment' => new EnrollmentResource($summary['enrollment']),
            'total_recorded' => $summary['total_recorded'],
            'present' => $summary['present'],
            'absent' => $summary['absent'],
            'late' => $summary['late'],
            'excused' => $summary['excused'],
            'attendance_percentage' => $summary['attendance_percentage'],
        ]);
    }
}
