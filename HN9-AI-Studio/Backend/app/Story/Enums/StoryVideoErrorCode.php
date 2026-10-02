<?php

declare(strict_types=1);

namespace App\Story\Enums;

enum StoryVideoErrorCode: string
{
    case ProviderUnavailable = 'PROVIDER_UNAVAILABLE';
    case CapabilityUnsupported = 'CAPABILITY_UNSUPPORTED';
    case CapabilityNotAvailable = 'VIDEO_CAPABILITY_NOT_AVAILABLE';
    case ModelUnavailable = 'MODEL_UNAVAILABLE';
    case InvalidInput = 'INVALID_INPUT';
    case AuthenticationFailed = 'AUTHENTICATION_FAILED';
    case QuotaExceeded = 'QUOTA_EXCEEDED';
    case RateLimited = 'RATE_LIMITED';
    case Timeout = 'TIMEOUT';
    case UpstreamError = 'UPSTREAM_ERROR';
    case DownloadFailed = 'DOWNLOAD_FAILED';
    case InvalidProviderResponse = 'INVALID_PROVIDER_RESPONSE';
    case UnknownProviderError = 'UNKNOWN_PROVIDER_ERROR';
    case GenerationNotEnabled = 'GENERATION_NOT_ENABLED';
    case SubmissionUnconfirmed = 'SUBMISSION_UNCONFIRMED';
}
