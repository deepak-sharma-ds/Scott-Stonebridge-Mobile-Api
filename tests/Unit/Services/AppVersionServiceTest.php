<?php

namespace Tests\Unit\Services;

use App\DTOs\AppVersion\AppVersionCheckDTO;
use App\Services\AppVersionService;
use InvalidArgumentException;
use Tests\TestCase;

class AppVersionServiceTest extends TestCase
{
    private AppVersionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AppVersionService;

        config()->set('app_update.platforms.ios', [
            'minimum_version' => '1.5.0',
            'latest_version' => '2.0.0',
            'store_url' => 'https://apps.apple.com/app/test-ios',
            'message' => 'Optional iOS update available.',
            'force_update_message' => 'Mandatory iOS update required.',
        ]);

        config()->set('app_update.platforms.android', [
            'minimum_version' => '1.2.0',
            'latest_version' => '1.8.0',
            'store_url' => 'https://play.google.com/store/apps/details?id=com.test.android',
            'message' => 'Optional Android update available.',
            'force_update_message' => 'Mandatory Android update required.',
        ]);
    }

    public function test_compare_versions_evaluates_semver_correctly(): void
    {
        // Equal
        $this->assertSame(0, $this->service->compareVersions('1.0.0', '1.0.0'));
        $this->assertSame(0, $this->service->compareVersions('v1.0.0', '1.0.0'));
        $this->assertSame(0, $this->service->compareVersions('1.0', '1.0.0'));
        $this->assertSame(0, $this->service->compareVersions('1.0.0+build1', '1.0.0+build2'));

        // Less than
        $this->assertLessThan(0, $this->service->compareVersions('1.0.0', '1.0.1'));
        $this->assertLessThan(0, $this->service->compareVersions('1.0.0', '1.1.0'));
        $this->assertLessThan(0, $this->service->compareVersions('1.9.9', '2.0.0'));
        $this->assertLessThan(0, $this->service->compareVersions('1.0.0-beta.1', '1.0.0'));
        $this->assertLessThan(0, $this->service->compareVersions('1.0.0-alpha', '1.0.0-beta'));

        // Greater than
        $this->assertGreaterThan(0, $this->service->compareVersions('1.1.0', '1.0.9'));
        $this->assertGreaterThan(0, $this->service->compareVersions('2.0.0', '1.9.9'));
        $this->assertGreaterThan(0, $this->service->compareVersions('1.0.0', '1.0.0-beta'));
    }

    public function test_check_version_flags_force_update_when_below_minimum(): void
    {
        $dto = new AppVersionCheckDTO(platform: 'ios', version: '1.4.9');
        $result = $this->service->checkVersion($dto);

        $this->assertTrue($result->forceUpdate);
        $this->assertTrue($result->updateAvailable);
        $this->assertSame('1.5.0', $result->minimumVersion);
        $this->assertSame('2.0.0', $result->currentVersion);
        $this->assertSame('https://apps.apple.com/app/test-ios', $result->storeUrl);
        $this->assertSame('Mandatory iOS update required.', $result->updateMessage);
    }

    public function test_check_version_allows_supported_version_below_latest(): void
    {
        $dto = new AppVersionCheckDTO(platform: 'ios', version: '1.5.0');
        $result = $this->service->checkVersion($dto);

        $this->assertFalse($result->forceUpdate);
        $this->assertTrue($result->updateAvailable);
        $this->assertSame('Optional iOS update available.', $result->updateMessage);
    }

    public function test_check_version_identifies_latest_current_version(): void
    {
        $dto = new AppVersionCheckDTO(platform: 'ios', version: '2.0.0');
        $result = $this->service->checkVersion($dto);

        $this->assertFalse($result->forceUpdate);
        $this->assertFalse($result->updateAvailable);
    }

    public function test_check_version_handles_newer_development_version(): void
    {
        $dto = new AppVersionCheckDTO(platform: 'ios', version: '2.1.0');
        $result = $this->service->checkVersion($dto);

        $this->assertFalse($result->forceUpdate);
        $this->assertFalse($result->updateAvailable);
    }

    public function test_check_version_evaluates_android_separately(): void
    {
        // Version 1.3.0 is below iOS minimum (1.5.0), but above Android minimum (1.2.0)
        $dto = new AppVersionCheckDTO(platform: 'android', version: '1.3.0');
        $result = $this->service->checkVersion($dto);

        $this->assertFalse($result->forceUpdate);
        $this->assertTrue($result->updateAvailable);
        $this->assertSame('1.2.0', $result->minimumVersion);
        $this->assertSame('1.8.0', $result->currentVersion);
        $this->assertSame('https://play.google.com/store/apps/details?id=com.test.android', $result->storeUrl);
    }

    public function test_check_version_throws_on_unsupported_platform(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $dto = new AppVersionCheckDTO(platform: 'windows', version: '1.0.0');
        $this->service->checkVersion($dto);
    }
}
