<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Contracts\HealthManagerInterface;
use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderManagerInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Exceptions\ProviderDisabledException;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Exceptions\ProviderNotRegisteredException;
use App\AI\Providers\AbstractProvider;
use App\AI\Support\Capability;
use App\AI\Support\HealthStatus;
use Tests\TestCase;

class AIProviderSubsystemTest extends TestCase
{
    private function registry(): ProviderRegistryInterface
    {
        return $this->app->make(ProviderRegistryInterface::class);
    }

    /**
     * A registration closure producing a capability-scoped test provider.
     * This is a test double (anonymous class), not a shipped provider — it
     * calls no API and returns no fabricated generation output.
     *
     * @return \Closure(ProviderConfigDTO): AIProviderInterface
     */
    private function providerFactory(string $name): \Closure
    {
        return static fn (ProviderConfigDTO $config): AbstractProvider => new class($config, $name) extends AbstractProvider
        {
            public function __construct(ProviderConfigDTO $config, private readonly string $name)
            {
                parent::__construct($config);
            }

            public function providerName(): string
            {
                return $this->name;
            }

            public function providerVersion(): string
            {
                return '1.0.0';
            }
        };
    }

    private function capabilities(string $key, bool $text = true, bool $image = false): ProviderCapabilityDTO
    {
        return new ProviderCapabilityDTO(
            key: $key,
            name: ucfirst($key),
            version: '1.0.0',
            text: $text,
            image: $image,
        );
    }

    public function test_registry_registers_and_orders_by_priority_for_capability(): void
    {
        $registry = $this->registry();
        $registry->register('low', $this->providerFactory('low'), $this->capabilities('low'), priority: 1);
        $registry->register('high', $this->providerFactory('high'), $this->capabilities('high'), priority: 10);
        $registry->register('imager', $this->providerFactory('imager'), $this->capabilities('imager', text: false, image: true), priority: 5);

        $this->assertTrue($registry->has('high'));
        $this->assertSame(['high', 'low'], $registry->forCapability(Capability::Text));
        $this->assertSame(['imager'], $registry->forCapability(Capability::Image));
        $this->assertSame('low', $registry->defaultKey(), 'first enabled registration becomes default');
    }

    public function test_registry_enable_disable_and_default(): void
    {
        $registry = $this->registry();
        $registry->register('alpha', $this->providerFactory('alpha'), $this->capabilities('alpha'));

        $registry->disable('alpha');
        $this->assertArrayNotHasKey('alpha', $registry->enabled());
        $this->assertSame([], $registry->forCapability(Capability::Text));

        $registry->enable('alpha');
        $this->assertArrayHasKey('alpha', $registry->enabled());

        $registry->setDefault('alpha');
        $this->assertSame('alpha', $registry->defaultKey());
    }

    public function test_factory_builds_registered_provider_and_guards_state(): void
    {
        $registry = $this->registry();
        $registry->register('alpha', $this->providerFactory('alpha'), $this->capabilities('alpha'));

        $factory = $this->app->make(ProviderFactoryInterface::class);
        $provider = $factory->make('alpha');

        $this->assertSame('alpha', $provider->providerName());
        $this->assertSame('1.0.0', $provider->providerVersion());

        $registry->disable('alpha');
        $this->expectException(ProviderDisabledException::class);
        $factory->make('alpha');
    }

    public function test_factory_make_default_without_config_throws(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);
        $this->app->make(ProviderFactoryInterface::class)->makeDefault();
    }

    public function test_manager_validates_and_lists_available_providers(): void
    {
        $registry = $this->registry();
        $registry->register('alpha', $this->providerFactory('alpha'), $this->capabilities('alpha'));

        $manager = $this->app->make(ProviderManagerInterface::class);

        $this->assertTrue($manager->has('alpha'));
        $this->assertContains('alpha', $manager->available());
        $this->assertSame([Capability::Text->value], array_map(
            static fn (Capability $c): string => $c->value,
            $manager->capabilities('alpha')->capabilities(),
        ));

        $this->expectException(ProviderNotRegisteredException::class);
        $manager->validate('ghost');
    }

    public function test_health_manager_aggregates_and_probes(): void
    {
        $health = $this->app->make(HealthManagerInterface::class);
        $this->assertSame([], $health->aggregate(), 'no providers registered → empty aggregate');

        $registry = $this->registry();
        $registry->register('alpha', $this->providerFactory('alpha'), $this->capabilities('alpha'));

        $aggregate = $health->aggregate();
        $this->assertArrayHasKey('alpha', $aggregate);
        // AbstractProvider has no real probe yet → reports Unknown, not a fabricated status.
        $this->assertSame(HealthStatus::Unknown, $aggregate['alpha']->status);

        $unregistered = $health->check('ghost');
        $this->assertSame(HealthStatus::Unavailable, $unregistered->status);
    }
}
