<?php

namespace App\Http\Controllers\Api\V1\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\ListAcademicSessionsRequest;
use App\Http\Requests\Academic\StoreAcademicSessionRequest;
use App\Http\Requests\Academic\UpdateAcademicSessionRequest;
use App\Http\Resources\Academic\AcademicSessionResource;
use App\Models\AcademicSession;
use App\Services\Academic\AcademicSessionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AcademicSessionController extends Controller
{
    public function __construct(protected AcademicSessionService $sessions) {}

    public function index(ListAcademicSessionsRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            AcademicSessionResource::collection(
                $this->sessions->paginate($request->filters())
            )
        );
    }

    public function store(StoreAcademicSessionRequest $request): JsonResponse
    {
        $session = $this->sessions->create($request->sessionAttributes());

        return ApiResponse::success(
            new AcademicSessionResource($session),
            'Academic session created. Activate it to make it the current session.',
            201,
        );
    }

    public function show(AcademicSession $academicSession): JsonResponse
    {
        return ApiResponse::success(
            new AcademicSessionResource($academicSession)
        );
    }

    public function update(UpdateAcademicSessionRequest $request, AcademicSession $academicSession): JsonResponse
    {
        $session = $this->sessions->update(
            $academicSession,
            $request->sessionAttributes(),
        );

        return ApiResponse::success(new AcademicSessionResource($session), 'Academic session updated.');
    }

    public function destroy(AcademicSession $academicSession): JsonResponse
    {
        $this->sessions->delete($academicSession);

        return ApiResponse::success(null, 'Academic session deleted.');
    }

    /**
     * Make this session the current one, completing whichever was current.
     */
    public function activate(AcademicSession $academicSession): JsonResponse
    {
        $session = $this->sessions->activate($academicSession);

        return ApiResponse::success(
            new AcademicSessionResource($session),
            'Academic session activated. The previous session has been completed.',
        );
    }
}
