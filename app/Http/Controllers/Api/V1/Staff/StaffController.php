<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StaffListRequest;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Services\Staff\StaffService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Staff management.
 *
 * There is no destroy(). A staff record is not deletable in Module 03: nothing references
 * it yet, so a "has no dependents" guard would be a check that can never fail and would
 * imply the operation is safe. Deactivation is the whole story, and when subjects,
 * attendance and results arrive their own restrictOnDelete keys become the real guard.
 */
class StaffController extends Controller
{
    public function __construct(protected StaffService $staff) {}

    public function index(StaffListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            StaffResource::collection(
                $this->staff->paginate($request->filters())
            )
        );
    }

    /**
     * Create a staff record and the login account behind it.
     *
     * The account is a declared part of the request, not a side effect: email and password
     * are required inputs, and the response carries the new account's email and status. The
     * password is never echoed.
     */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        $staff = $this->staff->create(
            $request->accountAttributes(),
            $request->staffAttributes(),
        );

        return ApiResponse::success(
            new StaffResource($staff),
            'Staff member created with a login account. The account is active and the password is the one supplied.',
            201,
        );
    }

    public function show(Staff $staff): JsonResponse
    {
        return ApiResponse::success(
            new StaffResource($staff->load('user'))
        );
    }

    /**
     * Amend a staff record. PUT only, and a whole-record write: name and staff_type are
     * required so a client cannot half-update the record by omitting them.
     *
     * Email, password, role and user_id are not amendable here - see UpdateStaffRequest.
     */
    public function update(UpdateStaffRequest $request, Staff $staff): JsonResponse
    {
        $updated = $this->staff->update(
            $staff,
            $request->staffAttributes(),
            $request->requestedStatus(),
        );

        return ApiResponse::success(
            new StaffResource($updated),
            'Staff member updated.',
        );
    }

    /**
     * Return a staff member to active employment.
     */
    public function activate(Staff $staff): JsonResponse
    {
        return ApiResponse::success(
            new StaffResource($this->staff->activate($staff)),
            'Staff member activated.',
        );
    }

    /**
     * Record that a staff member is no longer actively employed.
     *
     * The login account is deliberately left alone. See StaffService::deactivate().
     */
    public function deactivate(Staff $staff): JsonResponse
    {
        return ApiResponse::success(
            new StaffResource($this->staff->deactivate($staff)),
            'Staff member deactivated. Their login account is unchanged and still works.',
        );
    }
}
