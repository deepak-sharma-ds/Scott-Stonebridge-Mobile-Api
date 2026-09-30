<?php

namespace App\DTOs\AppVersion;

use App\DTOs\Base\BaseDTO;

/**
 * App Version Result DTO
 *
 * Encapsulates the version comparison outcome, update flags, store link, and message.
 */
class AppVersionResultDTO extends BaseDTO
{
    public function __construct(
        public readonly string $platform,
        public readonly string $installedVersion,
        public readonly string $currentVersion,
        public readonly string $minimumVersion,
        public readonly bool $forceUpdate,
        public readonly bool $updateAvailable,
        public readonly string $storeUrl,
        public readonly ?string $updateMessage,
    ) {
        $this->validate();
    }

    /**
     * Validate the DTO properties.
     */
    protected function validate(): void
    {
        $this->validateRequired($this->platform, 'Platform');
        $this->validateRequired($this->installedVersion, 'Installed version');
        $this->validateRequired($this->currentVersion, 'Current version');
        $this->validateRequired($this->minimumVersion, 'Minimum version');
    }

    /**
     * Convert result to array format matching API response specifications.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'installed_version' => $this->installedVersion,
            'current_version' => $this->currentVersion,
            'latest_version' => $this->currentVersion,
            'minimum_version' => $this->minimumVersion,
            'minimum_supported_version' => $this->minimumVersion,
            'force_update' => $this->forceUpdate,
            'update_available' => $this->updateAvailable,
            'store_url' => $this->storeUrl,
            'update_message' => $this->updateMessage,
        ];
    }
}
