<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Services\ExportServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExportResource;
use App\Models\Export;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Completes the existing global export routes. A project UUID is required;
 * CSV and other formats are rejected without creating a row.
 */
final class ExportController extends Controller
{
    public function __construct(private ExportServiceInterface $exports) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Export::class);

        return ApiResponse::success(ExportResource::collection($this->exports->index($request->user())));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Export::class);

        $export = $this->exports->create($request->user(), $request->all());

        return ApiResponse::created(new ExportResource($export));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $export = $this->exports->show($request->user(), $uuid);

        if ($export === null) {
            return ApiResponse::error('Not found', 'not_found', 404);
        }

        $this->authorize('view', $export);

        return ApiResponse::success(new ExportResource($export));
    }

    public function download(Request $request, string $uuid): StreamedResponse
    {
        $export = $this->exports->show($request->user(), $uuid);

        abort_if($export === null, 404);

        $this->authorize('download', $export);

        return $this->exports->download($request->user(), $uuid);
    }
}
