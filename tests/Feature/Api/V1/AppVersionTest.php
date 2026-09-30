<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class AppVersionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app_update.platforms.ios', [
            'minimum_version' => '1.2.0',
            'latest_version' => '1.5.0',
            'store_url' => 'https://apps.apple.com/app/scott-stonebridge/id123456789',
            'message' => 'A new iOS version is available.',
            'force_update_message' => 'Please update iOS app to continue.',
        ]);

        config()->set('app_update.platforms.android', [
            'minimum_version' => '2.0.0',
            'latest_version' => '2.3.0',
            'store_url' => 'https://play.google.com/store/apps/details?id=com.scottstonebridge.app',
            'message' => 'A new Android version is available.',
            'force_update_message' => 'Please update Android app to continue.',
        ]);
    }

    public function test_version_check_returns_force_update_true_when_outdated(): void
    {
        $response = $this->getJson('/api/v1/app/version?platform=ios&version=1.1.9');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'App version check completed successfully',
                'data' => [
                    'platform' => 'ios',
                    'installed_version' => '1.1.9',
                    'current_version' => '1.5.0',
                    'latest_version' => '1.5.0',
                    'minimum_version' => '1.2.0',
                    'minimum_supported_version' => '1.2.0',
                    'force_update' => true,
                    'update_available' => true,
                    'store_url' => 'https://apps.apple.com/app/scott-stonebridge/id123456789',
                    'update_message' => 'Please update iOS app to continue.',
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'platform',
                    'installed_version',
                    'current_version',
                    'latest_version',
                    'minimum_version',
                    'minimum_supported_version',
                    'force_update',
                    'update_available',
                    'store_url',
                    'update_message',
                ],
                'meta' => [
                    'correlation_id',
                    'timestamp',
                    'version',
                ],
            ]);
    }

    public function test_version_check_returns_force_update_false_when_supported(): void
    {
        $response = $this->getJson('/api/v1/app/version?platform=ios&version=1.2.0');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'platform' => 'ios',
                    'installed_version' => '1.2.0',
                    'force_update' => false,
                    'update_available' => true,
                    'minimum_version' => '1.2.0',
                    'latest_version' => '1.5.0',
                    'update_message' => 'A new iOS version is available.',
                ],
            ]);
    }

    public function test_version_check_returns_up_to_date_when_on_latest_version(): void
    {
        $response = $this->getJson('/api/v1/app/version?platform=ios&version=1.5.0');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'platform' => 'ios',
                    'installed_version' => '1.5.0',
                    'force_update' => false,
                    'update_available' => false,
                ],
            ]);
    }

    public function test_version_check_handles_newer_than_latest_version(): void
    {
        $response = $this->getJson('/api/v1/app/version?platform=ios&version=1.6.0');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'platform' => 'ios',
                    'installed_version' => '1.6.0',
                    'force_update' => false,
                    'update_available' => false,
                ],
            ]);
    }

    public function test_version_check_supports_android_separately(): void
    {
        // 1.5.0 is latest on iOS, but below Android minimum (2.0.0)
        $response = $this->getJson('/api/v1/app/version?platform=android&version=1.5.0');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'platform' => 'android',
                    'installed_version' => '1.5.0',
                    'minimum_version' => '2.0.0',
                    'latest_version' => '2.3.0',
                    'force_update' => true,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.scottstonebridge.app',
                    'update_message' => 'Please update Android app to continue.',
                ],
            ]);
    }

    public function test_version_check_supports_case_insensitive_platform(): void
    {
        $response = $this->getJson('/api/v1/app/version?platform=iOS&version=1.3.0');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'platform' => 'ios',
                    'force_update' => false,
                ],
            ]);
    }

    public function test_version_check_accepts_app_version_parameter_alias(): void
    {
        $response = $this->getJson('/api/v1/app/version?platform=android&app_version=2.1.0');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'platform' => 'android',
                    'installed_version' => '2.1.0',
                    'force_update' => false,
                    'update_available' => true,
                ],
            ]);
    }

    public function test_version_check_handles_semver_pre_release_tags(): void
    {
        // 1.2.0-beta.1 is less than 1.2.0 (minimum)
        $response = $this->getJson('/api/v1/app/version?platform=ios&version=1.2.0-beta.1');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'platform' => 'ios',
                    'installed_version' => '1.2.0-beta.1',
                    'force_update' => true,
                ],
            ]);
    }

    public function test_version_check_handles_v_prefix(): void
    {
        $response = $this->getJson('/api/v1/app/version?platform=ios&version=v1.2.0');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'platform' => 'ios',
                    'force_update' => false,
                ],
            ]);
    }

    public function test_version_check_works_on_unversioned_route_alias(): void
    {
        $response = $this->getJson('/api/app/version?platform=ios&version=1.0.0');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'platform' => 'ios',
                    'force_update' => true,
                ],
            ]);
    }

    public function test_version_check_is_accessible_without_authentication(): void
    {
        // Call without Bearer token or customer header
        $response = $this->getJson('/api/v1/app/version?platform=ios&version=1.5.0');

        $response->assertStatus(200);
    }

    public function test_version_check_validates_required_platform(): void
    {
        $response = $this->getJson('/api/v1/app/version?version=1.0.0');

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed.',
            ])
            ->assertJsonPath('meta.error_code', 'VALIDATION_ERROR');
    }

    public function test_version_check_validates_required_version(): void
    {
        $response = $this->getJson('/api/v1/app/version?platform=ios');

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed.',
            ])
            ->assertJsonPath('meta.error_code', 'VALIDATION_ERROR');
    }

    public function test_version_check_rejects_unsupported_platform(): void
    {
        $response = $this->getJson('/api/v1/app/version?platform=windows&version=1.0.0');

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed.',
            ])
            ->assertJsonPath('meta.error_code', 'VALIDATION_ERROR');
    }

    public function test_version_check_rejects_invalid_version_format(): void
    {
        $invalidVersions = [
            'invalid-version',
            'abc',
            '1..0',
            '-1.0.0',
            'v',
            '..',
        ];

        foreach ($invalidVersions as $invalidVersion) {
            $response = $this->getJson('/api/v1/app/version?platform=ios&version='.urlencode($invalidVersion));

            $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation failed.',
                ])
                ->assertJsonPath('meta.error_code', 'VALIDATION_ERROR');
        }
    }
}
