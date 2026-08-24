<?php

namespace App\Contracts\Services;

use App\DTOs\Navigation\MenuDTO;

interface NavigationServiceInterface
{
    /**
     * Get menu by handle
     *
     * @param  string  $handle  Menu handle (e.g., 'main-menu', 'footer')
     */
    public function getMenu(string $handle): MenuDTO;

    /**
     * Clear navigation cache
     */
    public function clearNavigationCache(): void;
}
