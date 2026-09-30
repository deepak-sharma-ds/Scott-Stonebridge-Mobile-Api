<?php

namespace App\DTOs\AppVersion;

use App\DTOs\Base\BaseDTO;

/**
 * App Version Check Request DTO
 *
 * Encapsulates client platform and installed version for update evaluation.
 */
class AppVersionCheckDTO extends BaseDTO
{
    public function __construct(
        public readonly string $platform,
        public readonly string $version,
    ) {
        $this->validate();
    }

    /**
     * Validate the DTO properties.
     */
    protected function validate(): void
    {
        $this->validateRequired($this->platform, 'Platform');
        $this->validateRequired($this->version, 'Version');
    }

    /**
     * Create from validated request data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromRequest(array $data): self
    {
        return new self(
            platform: strtolower(trim((string) $data['platform'])),
            version: trim((string) ($data['version'] ?? $data['app_version'] ?? '')),
        );
    }
}
