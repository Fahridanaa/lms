<?php

namespace App\Http\Controllers\Api;

use App\Constants\Messages\MaterialMessage;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponseTrait;
use App\Http\Controllers\Traits\ResolvesActor;
use App\Http\Requests\StoreMaterialRequest;
use App\Http\Requests\UpdateMaterialRequest;
use App\Models\Course;
use App\Models\Material;
use App\Services\ActorResolver;
use App\Services\CourseAccessService;
use App\Services\MaterialService;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    use ApiResponseTrait;
    use ResolvesActor;

    public function __construct(
        protected MaterialService $materialService,
        protected ActorResolver $actorResolver,
        protected CourseAccessService $courseAccessService
    ) {
    }

    /**
     * GET /api/courses/{courseId}/materials
     */
    public function index(Request $request, int $courseId)
    {
        $materials = $this->materialService->getCourseMaterials($courseId, $this->resolveActor($request));

        return $this->success($materials);
    }

    /**
     * GET /api/materials/{id}
     */
    public function show(Request $request, int $id)
    {
        $material = $this->materialService->getMaterialById($id, $this->resolveActor($request));

        return $this->success($material);
    }

    /**
     * GET /api/materials/{id}/download
     */
    public function download(Request $request, int $id)
    {
        $metadata = $this->materialService->getMaterialMetadata($id, $this->resolveActor($request));

        return $this->success($metadata);
    }

    /**
     * POST /api/materials
     */
    public function store(StoreMaterialRequest $request)
    {
        $actor = $this->resolveActor($request);
        $course = Course::query()->findOrFail($request->validated()['course_id']);

        if (! $this->courseAccessService->isInstructorForCourse($actor, $course)) {
            throw new BusinessException('You do not have permission to create materials for this course', 403);
        }

        $material = $this->materialService->createMaterial($request->validated());

        return $this->created($material, MaterialMessage::UPLOADED);
    }

    /**
     * PUT /api/materials/{id}
     */
    public function update(UpdateMaterialRequest $request, int $id)
    {
        $actor = $this->resolveActor($request);
        $material = Material::query()->with('course')->findOrFail($id);

        if (! $this->courseAccessService->isInstructorForCourse($actor, $material->course)) {
            throw new BusinessException('You do not have permission to update this material', 403);
        }

        $material = $this->materialService->updateMaterial($id, $request->validated());

        return $this->success($material, MaterialMessage::UPDATED);
    }

    /**
     * DELETE /api/materials/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $actor = $this->resolveActor($request);
        $material = Material::query()->with('course')->findOrFail($id);

        if (! $this->courseAccessService->isInstructorForCourse($actor, $material->course)) {
            throw new BusinessException('You do not have permission to delete this material', 403);
        }

        $this->materialService->deleteMaterial($id);

        return $this->success(null, MaterialMessage::DELETED);
    }
}
