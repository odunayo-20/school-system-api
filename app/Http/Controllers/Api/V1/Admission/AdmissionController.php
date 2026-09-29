<?php

namespace App\Http\Controllers\Api\V1\Admission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admission\AdmissionListRequest;
use App\Http\Requests\Admission\DecideAdmissionRequest;
use App\Http\Requests\Admission\StoreAdmissionRequest;
use App\Http\Requests\Admission\UpdateAdmissionRequest;
use App\Http\Resources\AdmissionResource;
use App\Models\Admission;
use App\Services\Admission\AdmissionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Admission management: applicants, decisions, and the pupils those decisions create.
 *
 * There is no destroy(). An admission is a historical business record of a decision the
 * school made, for exactly the reason Module 03 and Module 04 give none either: it does not
 * stop existing because the decision is old, and a rejected or withdrawn application is worth
 * keeping precisely so "how many of this year's applicants did we admit?" has an answer next
 * year too.
 *
 * There is no PATCH, only PUT, following the whole-record-write convention Module 03 and
 * Module 04 both use: first_name and academic_session_id are required, so a client cannot
 * half-update the record by omitting them.
 */
class AdmissionController extends Controller
{
    public function __construct(protected AdmissionService $admissions) {}

    public function index(AdmissionListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            AdmissionResource::collection(
                $this->admissions->paginate($request->filters())
            )
        );
    }

    /**
     * Record a new admission. PENDING, always. No Student is created here - see
     * StoreAdmissionRequest.
     */
    public function store(StoreAdmissionRequest $request): JsonResponse
    {
        return ApiResponse::success(
            new AdmissionResource($this->admissions->create($request->admissionAttributes())),
            'Admission recorded and is pending a decision.',
            201,
        );
    }

    public function show(Admission $admission): JsonResponse
    {
        return ApiResponse::success(
            new AdmissionResource($admission->load(['student', 'academicSession', 'entryClassLevel']))
        );
    }

    /**
     * Amend a pending admission. See UpdateAdmissionRequest for what cannot be reached this
     * way - status and student_id, in particular.
     */
    public function update(UpdateAdmissionRequest $request, Admission $admission): JsonResponse
    {
        $updated = $this->admissions->update($admission, $request->admissionAttributes());

        return ApiResponse::success(
            new AdmissionResource($updated),
            'Admission updated.',
        );
    }

    /**
     * Accept the applicant. Creates and links a Student in the same transaction - see
     * AdmissionService::admit().
     */
    public function admit(Admission $admission): JsonResponse
    {
        return ApiResponse::success(
            new AdmissionResource($this->admissions->admit($admission)),
            'Admission accepted. A student record has been created.',
        );
    }

    /**
     * Decline the applicant. No Student is created or touched.
     */
    public function reject(DecideAdmissionRequest $request, Admission $admission): JsonResponse
    {
        return ApiResponse::success(
            new AdmissionResource($this->admissions->reject($admission, $request->notes())),
            'Admission rejected.',
        );
    }

    /**
     * Record that the applicant withdrew before a decision was made.
     */
    public function withdraw(DecideAdmissionRequest $request, Admission $admission): JsonResponse
    {
        return ApiResponse::success(
            new AdmissionResource($this->admissions->withdraw($admission, $request->notes())),
            'Admission marked as withdrawn.',
        );
    }
}
