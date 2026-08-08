<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ExportController extends Controller
{
    public function index(Request $request)
    {
        if (class_exists('App\\Services\\ExportService')) {
            $svc = app('App\\Services\\ExportService');
            if (method_exists($svc, 'index')) {
                return ApiResponse::success($svc->index($request->user()));
            }
        }

        return ApiResponse::success([]);
    }

    public function store(Request $request)
    {
        $payload = $request->all();

        if (class_exists('App\\Services\\ExportService')) {
            $svc = app('App\\Services\\ExportService');
            if (method_exists($svc, 'create')) {
                $export = $svc->create($request->user(), $payload);

                // Dispatch job if available
                if (class_exists('App\\Jobs\\ExportJob')) {
                    $job = app('App\\Jobs\\ExportJob', ['export' => $export]);
                    Bus::dispatch($job);
                }

                return ApiResponse::created($export);
            }
        }

        return ApiResponse::created([]);
    }

    public function show(Request $request, string $uuid)
    {
        if (class_exists('App\\Services\\ExportService')) {
            $svc = app('App\\Services\\ExportService');
            if (method_exists($svc, 'show')) {
                $item = $svc->show($request->user(), $uuid);
                if ($item === null) {
                    return ApiResponse::error('Not found', 'not_found', 404);
                }

                return ApiResponse::success($item);
            }
        }

        return ApiResponse::error('Not found', 'not_found', 404);
    }

    public function download(Request $request, string $uuid)
    {
        if (class_exists('App\\Services\\ExportService')) {
            $svc = app('App\\Services\\ExportService');
            if (method_exists($svc, 'download')) {
                $result = $svc->download($request->user(), $uuid);

                // If service returned a response, return it directly.
                if ($result instanceof Response || $result instanceof BinaryFileResponse) {
                    return $result;
                }

                // If service returned a storage path, stream download.
                if (is_string($result) && Storage::exists($result)) {
                    return response()->download(Storage::path($result));
                }
            }
        }

        return ApiResponse::error('Not found', 'not_found', 404);
    }
}
