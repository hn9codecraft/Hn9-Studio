<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AI\Providers\Xai\XaiImageParameterMap;
use PHPUnit\Framework\TestCase;

final class XaiImageParameterMapTest extends TestCase
{
    public function test_openai_size_and_style_become_xai_fields(): void
    {
        $body = (new XaiImageParameterMap)->body(
            'grok-imagine-image',
            'A paper boat',
            1,
            '1792x1024',
            'hd',
            'url',
        );

        $this->assertSame('grok-imagine-image', $body['model']);
        $this->assertSame('16:9', $body['aspect_ratio']);
        $this->assertSame('url', $body['response_format']);
        $this->assertArrayNotHasKey('size', $body);
        $this->assertArrayNotHasKey('style', $body);
        $this->assertArrayNotHasKey('quality', $body);
    }

    public function test_quality_is_sent_only_for_the_second_image_model(): void
    {
        $body = (new XaiImageParameterMap)->body(
            'grok-imagine-image-2.0',
            'A paper boat',
            1,
            '1024x1024',
            'hd',
            'b64_json',
        );

        $this->assertSame('1:1', $body['aspect_ratio']);
        $this->assertSame('medium', $body['quality']);
        $this->assertSame('b64_json', $body['response_format']);
    }
}
