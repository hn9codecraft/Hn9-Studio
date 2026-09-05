<?php

declare(strict_types=1);

namespace App\DTOs\ProjectAsset;

/**
 * Immutable payload for a partial project asset update. Only keys present in
 * the validated request are persisted, including explicit nulls so a file URL
 * can be cleared.
 */
final readonly class UpdateProjectAssetData
{
    /**
     * @param  list<string>  $provided
     */
    public function __construct(
        public array $provided = [],
        public ?string $title = null,
        public ?string $type = null,
        public ?string $source = null,
        public ?string $status = null,
        public ?string $file_url = null,
        public ?string $mime_type = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            provided: array_keys($data),
            title: isset($data['title']) ? (string) $data['title'] : null,
            type: isset($data['type']) ? (string) $data['type'] : null,
            source: isset($data['source']) ? (string) $data['source'] : null,
            status: isset($data['status']) ? (string) $data['status'] : null,
            file_url: self::nullableString($data, 'file_url'),
            mime_type: self::nullableString($data, 'mime_type'),
            notes: self::nullableString($data, 'notes'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $map = [
            'title' => $this->title,
            'type' => $this->type,
            'source' => $this->source,
            'status' => $this->status,
            'file_url' => $this->file_url,
            'mime_type' => $this->mime_type,
            'notes' => $this->notes,
        ];

        $payload = [];
        foreach ($this->provided as $key) {
            if (array_key_exists($key, $map)) {
                $payload[$key] = $map[$key];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }

        return (string) $data[$key];
    }
}
