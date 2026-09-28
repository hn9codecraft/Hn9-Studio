<?php

declare(strict_types=1);

namespace App\Story\Services;

/**
 * Builds one local media file from already-stored clip bytes.
 * Trims and transitions are recorded in the file. No provider is called.
 */
final class StoryTimelineComposer
{
    /**
     * @param  list<array{media_kind: string, in_ms: int, out_ms: int, transition: string|null, transition_ms: int, bytes: string}>  $segments
     */
    public function compose(array $segments): string
    {
        $payload = '';
        $clips = [];
        foreach ($segments as $segment) {
            $payload .= $segment['bytes'];
            $clips[] = [
                'media_kind' => $segment['media_kind'],
                'in_ms' => $segment['in_ms'],
                'out_ms' => $segment['out_ms'],
                'transition' => $segment['transition'],
                'transition_ms' => $segment['transition_ms'],
                'byte_length' => strlen($segment['bytes']),
            ];
        }

        $meta = json_encode(['clips' => $clips], JSON_THROW_ON_ERROR);

        return $this->box('ftyp', "isom\x00\x00\x00\x00isom")
            .$this->box('hn9r', $meta)
            .$this->box('mdat', $payload);
    }

    private function box(string $type, string $body): string
    {
        return pack('N', 8 + strlen($body)).$type.$body;
    }
}
