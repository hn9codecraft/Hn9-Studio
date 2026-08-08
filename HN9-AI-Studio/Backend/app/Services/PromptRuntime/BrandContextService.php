<?php

declare(strict_types=1);

namespace App\Services\PromptRuntime;

use App\Contracts\Services\PromptRuntime\BrandContextServiceInterface;
use App\Models\Project;
use Illuminate\Support\Arr;

/**
 * Builds a brand-aware runtime context for prompt composition.
 *
 * This service only prepares data for downstream prompt assembly and does not
 * dispatch providers or generate any content.
 */
final readonly class BrandContextService implements BrandContextServiceInterface
{
    public function forProject(Project $project, array $options = []): array
    {
        $language = (string) (Arr::get($options, 'language') ?? 'en');
        $settings = is_array($project->settings) ? $project->settings : [];
        $metadata = is_array($project->metadata) ? $project->metadata : [];

        $brand = $this->loadJson('../Brand/brand.json');
        $tone = $this->loadJson('../Brand/tone.json');
        $audience = $this->loadJson('../Brand/audience.json');
        $colors = $this->loadJson('../Brand/colors.json');
        $company = $this->loadJson('../Brand/company.json');

        $industry = Arr::get($settings, 'industry', Arr::get($metadata, 'industry', Arr::get($brand, 'industry', [])));
        $colors = Arr::get($settings, 'colors', Arr::get($metadata, 'colors', Arr::get($colors, 'primary', [])));
        $brandColors = Arr::get($settings, 'brand_colors', Arr::get($metadata, 'brand_colors', Arr::get($brand, 'brandColors', [])));

        return [
            'project_id' => $project->getKey(),
            'project_name' => $project->name,
            'project_type' => $project->type,
            'language' => $language,
            'tone' => (string) (Arr::get($settings, 'tone') ?? Arr::get($metadata, 'tone') ?? Arr::get($tone, 'coreVoice', 'Professional')),
            'audience' => Arr::get($settings, 'audience', Arr::get($metadata, 'audience', Arr::get($audience, 'segments', []))),
            'brand_name' => (string) (Arr::get($settings, 'brand_name') ?? Arr::get($metadata, 'brand_name') ?? Arr::get($brand, 'brandName') ?? Arr::get($company, 'brandName') ?? 'HN9'),
            'company_name' => (string) (Arr::get($settings, 'company_name') ?? Arr::get($metadata, 'company_name') ?? Arr::get($company, 'displayName') ?? Arr::get($brand, 'brandName') ?? 'HN9'),
            'website' => (string) (Arr::get($settings, 'website') ?? Arr::get($metadata, 'website') ?? Arr::get($brand, 'website') ?? Arr::get($company, 'website') ?? ''),
            'tagline' => (string) (Arr::get($settings, 'tagline') ?? Arr::get($metadata, 'tagline') ?? Arr::get($brand, 'tagline') ?? Arr::get($company, 'tagline') ?? ''),
            'industry' => is_array($industry) ? $industry : [],
            'business_type' => (string) (Arr::get($settings, 'business_type') ?? Arr::get($metadata, 'business_type') ?? 'agency'),
            'writing_style' => (string) (Arr::get($settings, 'writing_style') ?? Arr::get($metadata, 'writing_style') ?? Arr::get($tone, 'writingTone.description', 'Clear and concise')),
            'colors' => is_array($colors) ? $colors : [],
            'brand_colors' => is_array($brandColors) ? $brandColors : [],
            'voice' => (string) (Arr::get($settings, 'voice') ?? Arr::get($metadata, 'voice') ?? Arr::get($tone, 'coreVoice', 'Professional')),
            'persona' => Arr::get($settings, 'persona', Arr::get($metadata, 'persona', Arr::get($audience, 'personas.0.name', 'Customer'))),
            'brand_metadata' => [
                'version' => Arr::get($brand, 'version', '1.0.0'),
                'last_updated' => Arr::get($brand, 'lastUpdated', null),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $relativePath): array
    {
        $path = base_path($relativePath);
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
