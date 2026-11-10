<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Story\Support\StoryVoiceProfileResolver;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class StoryVoiceLanguageController extends Controller
{
    public function __construct(private StoryVoiceProfileResolver $voices) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'default_language' => $this->voices->defaultLanguage(),
            'languages' => $this->voices->catalog(),
        ]);
    }
}
