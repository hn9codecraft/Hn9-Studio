<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Exceptions\AllProvidersFailedException;
use App\AI\Exceptions\NoProviderAvailableException;
use App\AI\Execution\DispatchOptions;
use App\AI\Execution\DispatchResult;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Requests\VideoRequest;
use App\AI\Requests\VoiceRequest;
use App\AI\Responses\ImageResponse;
use App\AI\Responses\TextResponse;
use App\AI\Responses\VideoResponse;
use App\AI\Responses\VoiceResponse;

/**
 * Executes a request against the routed providers, applying retry, circuit
 * breaking, fallback, timeout and metrics around each attempt.
 *
 * This is the resilient counterpart to {@see ProviderManagerInterface}: the
 * manager resolves a named provider, the dispatcher decides which provider to
 * use and keeps trying until the plan or the deadline is exhausted. The manager
 * keeps its existing behaviour untouched.
 */
interface ProviderDispatcherInterface
{
    /**
     * Route and execute a request of any modality.
     *
     * @throws NoProviderAvailableException When no provider can serve the request.
     * @throws AllProvidersFailedException When every routed provider failed.
     */
    public function dispatch(ProviderRequestInterface $request, ?DispatchOptions $options = null): DispatchResult;

    public function text(TextRequest $request, ?DispatchOptions $options = null): TextResponse;

    public function image(ImageRequest $request, ?DispatchOptions $options = null): ImageResponse;

    public function voice(VoiceRequest $request, ?DispatchOptions $options = null): VoiceResponse;

    public function video(VideoRequest $request, ?DispatchOptions $options = null): VideoResponse;
}
