<?php

declare(strict_types=1);

return [
    'company_journey' => [
        'label' => 'Company journey',
        'description' => 'The story of how a company started, grew and where it is going.',
        'defaults' => [
            'duration_seconds' => 180,
            'aspect_ratio' => '16:9',
            'language' => 'en',
            'tone' => 'Inspiring',
            'genre' => 'Brand documentary',
            'narrative_style' => 'Narrated journey from beginning to today and the future',
            'video_style' => 'Cinematic documentary',
            'audience' => 'Clients, partners and future team members',
        ],
        'style' => [
            'visual_style' => 'Cinematic realism',
            'lighting' => 'Warm natural light',
            'camera_style' => 'Slow push-ins and wide establishing shots',
            'color_direction' => 'Warm, optimistic',
            'mood' => 'Hopeful',
        ],
        'audio_defaults' => [
            'voice_enabled' => true,
            'music_enabled' => true,
            'sfx_enabled' => false,
            'voice_style' => 'Warm narrator',
            'music_mood' => 'Uplifting',
        ],
        'characters' => [
            ['name' => 'Founder', 'short_description' => 'The person who started the company.'],
            ['name' => 'Team', 'short_description' => 'The people who helped it grow.'],
        ],
        'fields' => [
            ['key' => 'company_name', 'label' => 'Company name', 'required' => true, 'max' => 120],
            ['key' => 'milestones', 'label' => 'Key milestones (one per line)', 'required' => false, 'max' => 2000],
            ['key' => 'vision', 'label' => 'Where you are going next', 'required' => false, 'max' => 1000],
        ],
        'idea_scaffold' => 'Create a {duration_label} company journey video for {company_name}. Topic: {topic}. Milestones: {milestones}. Future vision: {vision}. Tone: {tone}. Language: {language_label}.',
    ],

    'product_ad' => [
        'label' => 'Product ad',
        'description' => 'A short commercial that shows a product, its benefits and a clear next step.',
        'defaults' => [
            'duration_seconds' => 40,
            'aspect_ratio' => '16:9',
            'language' => 'en',
            'tone' => 'Energetic',
            'genre' => 'Product advertisement',
            'narrative_style' => 'Product story that ends on a clear call to action',
            'video_style' => 'Clean commercial with product hero shots',
            'audience' => 'People who could use the product',
        ],
        'style' => [
            'visual_style' => 'Studio-lit commercial',
            'lighting' => 'Crisp',
            'camera_style' => 'Product hero shots',
            'color_direction' => 'High contrast',
            'mood' => 'Energetic',
        ],
        'audio_defaults' => [
            'voice_enabled' => true,
            'music_enabled' => true,
            'sfx_enabled' => false,
            'voice_style' => 'Clear commercial voice',
            'music_mood' => 'Upbeat',
        ],
        'characters' => [
            ['name' => 'Customer', 'short_description' => 'The person the product is for.'],
        ],
        'fields' => [
            ['key' => 'product_name', 'label' => 'Product name', 'required' => true, 'max' => 120],
            ['key' => 'key_benefits', 'label' => 'Key benefits', 'required' => true, 'max' => 2000],
            ['key' => 'call_to_action', 'label' => 'Call to action', 'required' => false, 'max' => 200],
        ],
        'idea_scaffold' => 'Create a {duration_label} product advertisement for {product_name}. Topic: {topic}. Benefits: {key_benefits}. Tone: {tone}. Language: {language_label}. Call to action: {call_to_action}.',
    ],

    'reel' => [
        'label' => 'Reel',
        'description' => 'A vertical social video with a fast hook and a short payoff.',
        'defaults' => [
            'duration_seconds' => 40,
            'aspect_ratio' => '9:16',
            'language' => 'en',
            'tone' => 'Punchy',
            'genre' => 'Social media reel',
            'narrative_style' => 'Hook first, then one clear idea, then a short close',
            'video_style' => 'Fast cuts, bold framing, hook in the first 3 seconds',
            'audience' => 'Social media viewers',
        ],
        'style' => [
            'visual_style' => 'Bold social video',
            'lighting' => 'Bright and direct',
            'camera_style' => 'Handheld close-ups',
            'color_direction' => 'Bold and saturated',
            'mood' => 'Punchy',
        ],
        'audio_defaults' => [
            'voice_enabled' => true,
            'music_enabled' => true,
            'sfx_enabled' => false,
            'voice_style' => 'Direct and quick',
            'music_mood' => 'Driving',
        ],
        'characters' => [],
        'fields' => [
            ['key' => 'hook', 'label' => 'Opening hook', 'required' => false, 'max' => 500],
            ['key' => 'call_to_action', 'label' => 'Call to action', 'required' => false, 'max' => 200],
        ],
        'idea_scaffold' => 'Create a {duration_label} social reel. Topic: {topic}. Hook: {hook}. Tone: {tone}. Language: {language_label}. Call to action: {call_to_action}.',
    ],
];
