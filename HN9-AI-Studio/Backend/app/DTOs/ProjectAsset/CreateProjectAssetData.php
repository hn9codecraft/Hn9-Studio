<?php

declare(strict_types=1);

namespace App\DTOs\ProjectAsset;

use App\DTOs\Concerns\ArrayableData;
use App\Enums\ProjectAssetSource;
use App\Enums\ProjectAssetStatus;
use App\Enums\ProjectAssetType;

/**
 * Immutable payload for creating a studio project asset.
 */
final readonly class CreateProjectAssetData
{
    use ArrayableData;

    public function __construct(
        public int $project_id,
        public string $title,
        public string $type = ProjectAssetType::Other->value,
        public string $source = ProjectAssetSource::Manual->value,
        public string $status = ProjectAssetStatus::Draft->value,
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
            project_id: (int) $data['project_id'],
            title: (string) $data['title'],
            type: (string) ($data['type'] ?? ProjectAssetType::Other->value),
            source: (string) ($data['source'] ?? ProjectAssetSource::Manual->value),
            status: (string) ($data['status'] ?? ProjectAssetStatus::Draft->value),
            file_url: self::nullableString($data, 'file_url'),
            mime_type: self::nullableString($data, 'mime_type'),
            notes: self::nullableString($data, 'notes'),
        );
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
