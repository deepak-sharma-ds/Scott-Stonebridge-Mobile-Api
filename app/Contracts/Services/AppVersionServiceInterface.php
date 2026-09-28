<?php

namespace App\Contracts\Services;

use App\DTOs\AppVersion\AppVersionCheckDTO;
use App\DTOs\AppVersion\AppVersionResultDTO;

interface AppVersionServiceInterface
{
    /**
     * Check if the specified mobile app version requires an update or is supported.
     */
    public function checkVersion(AppVersionCheckDTO $dto): AppVersionResultDTO;

    /**
     * Compare two semantic versions.
     *
     * Returns:
     *  -1 if $version1 < $version2
     *   0 if $version1 == $version2
     *   1 if $version1 > $version2
     */
    public function compareVersions(string $version1, string $version2): int;
}
