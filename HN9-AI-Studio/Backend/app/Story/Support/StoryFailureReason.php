<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\AI\Support\ProviderErrorSanitizer;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Models\StoryVideoGenerationJob;

/**
 * Plain reasons for a failed or cancelled attempt. Provider bodies and keys stay out.
 */
final class StoryFailureReason
{
    /**
     * @return array{code: string, reason: string, retryable: bool, hint: string}
     */
    public static function forJob(StoryVideoGenerationJob $job): array
    {
        return self::describe($job->error_code, $job->error_message);
    }

    /**
     * @return array{code: string, reason: string, retryable: bool, hint: string}
     */
    public static function describe(?string $code, ?string $message): array
    {
        $known = self::ownMessage($message);
        if ($known !== null) {
            return [
                'code' => $code ?: StoryVideoErrorCode::InvalidProviderResponse->value,
                'reason' => $known,
                'retryable' => true,
                'hint' => 'Try this clip again.',
            ];
        }

        $lower = strtolower((string) $message);
        if (str_contains($lower, 'content policy') || str_contains($lower, 'safety') || str_contains($lower, 'moderation')) {
            return self::row($code ?: 'CONTENT_POLICY', 'This prompt was blocked by the video service\'s content rules.', false, 'Change the prompt and generate again.');
        }

        $enum = is_string($code) ? StoryVideoErrorCode::tryFrom($code) : null;

        return match ($enum) {
            StoryVideoErrorCode::InvalidInput => self::row($enum->value, 'This request cannot be sent as it is.', false, 'Change the prompt and generate again.'),
            StoryVideoErrorCode::CapabilityUnsupported,
            StoryVideoErrorCode::CapabilityNotAvailable => self::row((string) $enum->value, 'This video setup is not supported by the connected service.', false, 'Change the prompt and generate again.'),
            StoryVideoErrorCode::AuthenticationFailed => self::row($enum->value, 'The video service is not signed in.', false, 'Change the prompt and generate again.'),
            StoryVideoErrorCode::GenerationNotEnabled => self::row($enum->value, 'Video generation is not configured yet.', false, 'Change the prompt and generate again.'),
            StoryVideoErrorCode::QuotaExceeded,
            StoryVideoErrorCode::RateLimited => self::row($enum->value, 'The video service is busy right now.', true, 'Try this clip again in a little while.'),
            StoryVideoErrorCode::Timeout => self::row($enum->value, 'The video service took too long.', true, 'Try this clip again.'),
            default => self::row(
                $enum?->value ?: ($code ?: StoryVideoErrorCode::UnknownProviderError->value),
                'The video service had a problem.',
                true,
                'Try this clip again.',
            ),
        };
    }

    /**
     * @return array{code: string, reason: string, retryable: bool, hint: string}
     */
    private static function row(string $code, string $reason, bool $retryable, string $hint): array
    {
        return [
            'code' => $code,
            'reason' => $reason,
            'retryable' => $retryable,
            'hint' => $hint,
        ];
    }

    private static function ownMessage(?string $message): ?string
    {
        $clean = trim((string) $message);
        $owned = [
            'The generated clip was missing or could not be read, so it was not accepted.',
            'The generated clip\'s length could not be checked, so it was not accepted.',
            'The generated clip is not the length of this generation unit, so it was not accepted.',
        ];
        if (! in_array($clean, $owned, true)) {
            return null;
        }

        $safe = ProviderErrorSanitizer::message($clean, $clean);

        return $safe === $clean ? $clean : null;
    }
}
