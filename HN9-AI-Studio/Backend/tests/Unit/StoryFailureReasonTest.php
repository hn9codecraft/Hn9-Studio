<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Support\StoryFailureReason;
use PHPUnit\Framework\TestCase;

final class StoryFailureReasonTest extends TestCase
{
    public function test_known_codes_stay_plain_and_content_policy_cannot_be_retried(): void
    {
        $invalid = StoryFailureReason::describe(StoryVideoErrorCode::InvalidInput->value, 'raw sk-live-secret body');
        $this->assertFalse($invalid['retryable']);
        $this->assertSame('Change the prompt and generate again.', $invalid['hint']);
        $this->assertStringNotContainsString('sk-live-secret', $invalid['reason']);

        $policy = StoryFailureReason::describe(StoryVideoErrorCode::UpstreamError->value, 'Blocked by content policy');
        $this->assertFalse($policy['retryable']);

        $upstream = StoryFailureReason::describe(StoryVideoErrorCode::UpstreamError->value, 'provider key=secret raw body');
        $this->assertTrue($upstream['retryable']);
        $this->assertSame('The video service had a problem.', $upstream['reason']);
        $this->assertStringNotContainsString('secret', $upstream['reason']);

        $missing = StoryFailureReason::describe(
            StoryVideoErrorCode::InvalidProviderResponse->value,
            'The generated clip was missing or could not be read, so it was not accepted.',
        );
        $this->assertTrue($missing['retryable']);
        $this->assertStringContainsString('could not be read', $missing['reason']);

        $unknown = StoryFailureReason::describe('NOT_A_CODE', null);
        $this->assertTrue($unknown['retryable']);
        $this->assertSame('The video service had a problem.', $unknown['reason']);
    }
}
