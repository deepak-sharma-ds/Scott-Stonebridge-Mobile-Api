<?php

namespace App\Services;

use App\Contracts\Services\AppVersionServiceInterface;
use App\DTOs\AppVersion\AppVersionCheckDTO;
use App\DTOs\AppVersion\AppVersionResultDTO;
use App\Services\Base\BaseService;
use InvalidArgumentException;

/**
 * App Version Service
 *
 * Handles semantic version evaluation for mobile app compulsory and optional updates.
 */
class AppVersionService extends BaseService implements AppVersionServiceInterface
{
    /**
     * Check if the specified mobile app version requires an update or is supported.
     */
    public function checkVersion(AppVersionCheckDTO $dto): AppVersionResultDTO
    {
        $platform = strtolower($dto->platform);
        $platformConfig = config("app_update.platforms.{$platform}");

        if (! is_array($platformConfig)) {
            $this->logError("Unsupported platform requested for app version check: {$platform}", [
                'platform' => $platform,
                'version' => $dto->version,
            ]);

            throw new InvalidArgumentException("Unsupported platform: {$platform}");
        }

        $minimumVersion = (string) ($platformConfig['minimum_version'] ?? '1.0.0');
        $latestVersion = (string) ($platformConfig['latest_version'] ?? '1.0.0');
        $storeUrl = (string) ($platformConfig['store_url'] ?? '');

        $isBelowMinimum = $this->compareVersions($dto->version, $minimumVersion) < 0;
        $isBelowLatest = $this->compareVersions($dto->version, $latestVersion) < 0;

        $forceUpdate = $isBelowMinimum;
        $updateAvailable = $isBelowLatest;

        $updateMessage = $this->resolveUpdateMessage($platformConfig, $forceUpdate, $updateAvailable);

        $this->logInfo('Mobile app version check evaluated', [
            'platform' => $platform,
            'installed_version' => $dto->version,
            'minimum_version' => $minimumVersion,
            'latest_version' => $latestVersion,
            'force_update' => $forceUpdate,
            'update_available' => $updateAvailable,
        ]);

        return new AppVersionResultDTO(
            platform: $platform,
            installedVersion: $dto->version,
            currentVersion: $latestVersion,
            minimumVersion: $minimumVersion,
            forceUpdate: $forceUpdate,
            updateAvailable: $updateAvailable,
            storeUrl: $storeUrl,
            updateMessage: $updateMessage,
        );
    }

    /**
     * Compare two semantic versions using SemVer conventions.
     *
     * Returns:
     *  -1 if $version1 < $version2
     *   0 if $version1 == $version2
     *   1 if $version1 > $version2
     */
    public function compareVersions(string $version1, string $version2): int
    {
        $v1 = $this->normalizeVersion($version1);
        $v2 = $this->normalizeVersion($version2);

        return version_compare($v1, $v2);
    }

    /**
     * Normalize a version string for semantic comparison.
     * Trims leading 'v'/'V', removes build metadata, and pads incomplete SemVer segments.
     */
    protected function normalizeVersion(string $version): string
    {
        $version = trim($version);
        $version = ltrim($version, 'vV');

        // Build metadata (+...) must be ignored during precedence comparison in SemVer 2.0.0
        $version = (string) preg_replace('/\+.*$/', '', $version);

        // Separate pre-release tag if present
        $preRelease = '';
        if (str_contains($version, '-')) {
            $parts = explode('-', $version, 2);
            $version = $parts[0];
            $preRelease = '-'.$parts[1];
        }

        // Pad numeric segments up to 3 parts (MAJOR.MINOR.PATCH)
        $segments = explode('.', $version);
        while (count($segments) < 3) {
            $segments[] = '0';
        }

        return implode('.', $segments).$preRelease;
    }

    /**
     * Resolve the update message based on update requirement and configuration.
     *
     * @param  array<string, mixed>  $platformConfig
     */
    protected function resolveUpdateMessage(array $platformConfig, bool $forceUpdate, bool $updateAvailable): ?string
    {
        if ($forceUpdate) {
            return $platformConfig['force_update_message']
                ?? $platformConfig['message']
                ?? config('app_update.force_update_message')
                ?? config('app_update.default_message');
        }

        if ($updateAvailable) {
            return $platformConfig['message']
                ?? config('app_update.default_message');
        }

        return $platformConfig['message'] ?? null;
    }
}
