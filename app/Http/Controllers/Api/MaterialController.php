<?php

namespace App\Http\Controllers\Api;

use App\Constants\Messages\MaterialMessage;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponseTrait;
use App\Http\Requests\StoreMaterialRequest;
use App\Http\Requests\UpdateMaterialRequest;
use App\Services\MaterialService;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        protected MaterialService $materialService
    ) {
    }

    /**
     * GET /api/courses/{courseId}/materials
     */
    public function index(int $courseId)
    {
        $materials = $this->materialService->getCourseMaterials($courseId);

        return $this->success($materials);
    }

    /**
     * GET /api/materials/{id}
     */
    public function show(int $id)
    {
        $material = $this->materialService->getMaterialById($id);

        return $this->success($material);
    }

    /**
     * GET /api/materials/{id}/download
     */
    public function download(int $id)
    {
        $metadata = $this->materialService->getMaterialMetadata($id);

        return $this->success($metadata);
    }

    /**
     * POST /api/materials
     */
    public function store(StoreMaterialRequest $request)
    {
        $material = $this->materialService->createMaterial($request->validated());

        return $this->created($material, MaterialMessage::UPLOADED);
    }

    /**
     * PUT /api/materials/{id}
     */
    public function update(UpdateMaterialRequest $request, int $id)
    {
        $material = $this->materialService->updateMaterial($id, $request->validated());

        return $this->success($material, MaterialMessage::UPDATED);
    }

    /**
     * DELETE /api/materials/{id}
     */
    public function destroy(int $id)
    {
        $this->materialService->deleteMaterial($id);

        return $this->success(null, MaterialMessage::DELETED);
    }
}
