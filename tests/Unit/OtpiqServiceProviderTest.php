<?php

namespace Rstacode\Otpiq\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Rstacode\Otpiq\OtpiqService;
use Rstacode\Otpiq\OtpiqServiceProvider;

class OtpiqServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [OtpiqServiceProvider::class];
    }

    public function test_service_provider_registers_otpiq_service_as_singleton(): void
    {
        $service1 = $this->app->make(OtpiqService::class);
        $service2 = $this->app->make(OtpiqService::class);

        $this->assertInstanceOf(OtpiqService::class, $service1);
        $this->assertSame($service1, $service2);
    }

    public function test_service_provider_merges_config(): void
    {
        $config = $this->app['config']->get('otpiq');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('api_key', $config);
        $this->assertArrayHasKey('base_url', $config);
        $this->assertArrayHasKey('timeout', $config);
    }

    public function test_config_has_default_base_url(): void
    {
        $baseUrl = $this->app['config']->get('otpiq.base_url');

        $this->assertEquals('https://api.otpiq.com/api/', $baseUrl);
    }

    public function test_config_has_default_timeout(): void
    {
        $timeout = $this->app['config']->get('otpiq.timeout');

        $this->assertEquals(30, $timeout);
    }

    public function test_config_api_key_defaults_to_empty_string(): void
    {
        $apiKey = $this->app['config']->get('otpiq.api_key');

        $this->assertEquals('', $apiKey);
    }

    public function test_service_provider_publishes_config(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'otpiq-config', '--force' => true]);

        $publishedPath = config_path('otpiq.php');
        $this->assertFileExists($publishedPath);
    }

    public function test_service_uses_configured_api_key(): void
    {
        $this->app['config']->set('otpiq.api_key', 'test-configured-key');

        // Re-resolve to get fresh instance with new config
        $this->app->forgetInstance(OtpiqService::class);
        $service = $this->app->make(OtpiqService::class);

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('apiKey');
        $property->setAccessible(true);

        $this->assertEquals('test-configured-key', $property->getValue($service));
    }

    public function test_service_uses_configured_base_url(): void
    {
        $this->app['config']->set('otpiq.base_url', 'https://custom-api.example.com/v2/');

        $this->app->forgetInstance(OtpiqService::class);
        $service = $this->app->make(OtpiqService::class);

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('baseUrl');
        $property->setAccessible(true);

        $this->assertEquals('https://custom-api.example.com/v2/', $property->getValue($service));
    }
}
