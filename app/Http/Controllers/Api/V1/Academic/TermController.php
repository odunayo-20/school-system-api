<?php

namespace App\Http\Controllers\Api\V1\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\ListTermsRequest;
use App\Http\Requests\Academic\StoreTermRequest;
use App\Http\Requests\Academic\UpdateTermRequest;
use App\Http\Resources\Academic\TermResource;
use App\Models\AcademicSession;
use App\Models\Term;
use App\Services\Academic\TermService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TermController extends Controller
{
    public function __construct(protected TermService $terms) {}

    /**
     * The terms of one session.
     *
     * Listed under the session but acted on through flat /api/v1/terms/{term} URLs. That
     * asymmetry is intentional: reading a year is always "show me this session's terms",
     * while changing a term is "change this term", and a term carries its own session id so
     * a flat URL can resolve it. It is also what lets a term be reparented by the database
     * rather than inferred from the URL.
     */
    public function index(ListTermsRequest $request, AcademicSession $academicSession): JsonResponse
    {
        return ApiResponse::paginated(
            TermResource::collection(
                $this->terms->paginateForSession($academicSession, $request->filters())
            )
        );
    }

    public function store(StoreTermRequest $request, AcademicSession $academicSession): JsonResponse
    {
        $term = $this->terms->create($academicSession, $request->termAttributes());

        return ApiResponse::success(
            new TermResource($term->load('academicSession')),
            'Term created.',
            201,
        );
    }

    public function show(Term $term): JsonResponse
    {
        return ApiResponse::success(new TermResource($term->load('academicSession')));
    }

    public function update(UpdateTermRequest $request, Term $term): JsonResponse
    {
        $model = $this->terms->update($term, $request->termAttributes());

        return ApiResponse::success(
            new TermResource($model->load('academicSession')),
            'Term updated.',
        );
    }

    public function destroy(Term $term): JsonResponse
    {
        $this->terms->delete($term);

        return ApiResponse::success(null, 'Term deleted.');
    }

    /**
     * Make this term the current one, completing whichever was current.
     */
    public function activate(Term $term): JsonResponse
    {
        $model = $this->terms->activate($term);

        return ApiResponse::success(
            new TermResource($model->load('academicSession')),
            'Term activated. The previous term has been completed.',
        );
    }
}
