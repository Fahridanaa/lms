<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MaterialService;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    public function __construct(
        protected MaterialService $materialService
    ) {}

    /**
     * Get materials for a course
     * GET /api/courses/{courseId}/materials
     */
    public function index(int $courseId)
    {
        $materials = $this->materialService->getCourseMaterials($courseId);

        return response()->json([
            'success' => true,
            'data' => $materials,
        ]);
    }

    /**
     * Get material detail
     * GET /api/materials/{id}
     */
    public function show(int $id)
    {
        $material = $this->materialService->getMaterialById($id);

        return response()->json([
            'success' => true,
            'data' => $material,
        ]);
    }

    /**
     * Get material download metadata
     * GET /api/materials/{id}/download
     */
    public function download(int $id)
    {
        $metadata = $this->materialService->getMaterialMetadata($id);

        return response()->json([
            'success' => true,
            'data' => $metadata,
        ]);
    }

    /**
     * Upload new material
     * POST /api/materials
     */
    public function store(Request $request)
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id',
            'title' => 'required|string|max:255',
            'file_path' => 'required|string',
            'file_size' => 'required|integer',
            'type' => 'required|in:pdf,video,document,image,other',
        ]);

        $material = $this->materialService->createMaterial($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Material uploaded successfully',
            'data' => $material,
        ], 201);
    }

    /**
     * Update material
     * PUT /api/materials/{id}
     */
    public function update(Request $request, int $id)
    {
        $request->validate([
            'title' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:pdf,video,document,image,other',
        ]);

        $material = $this->materialService->updateMaterial($id, $request->all());

        return response()->json([
            'success' => true,
            'message' => 'Material updated successfully',
            'data' => $material,
        ]);
    }

    /**
     * Delete material
     * DELETE /api/materials/{id}
     */
    public function destroy(int $id)
    {
        $this->materialService->deleteMaterial($id);

        return response()->json([
            'success' => true,
            'message' => 'Material deleted successfully',
        ]);
    }
}
