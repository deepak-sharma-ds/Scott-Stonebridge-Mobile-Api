<?php

/**
 * SHOPIFY Website APIs
 * This file contains all the apis related to the Shopify website APIs which are callled in Shopify website.
 */
require __DIR__ . '/shopify_website.php';


/**
 * Shopify Mobile App APIs (Legacy).
 * This file contains all the apis related to the mobile app. We are mainly using Storefront and GraphQL Shopify APIs.
 * 
 * Note: These routes are maintained for backward compatibility.
 * New development should use the versioned API routes below.
 */
require __DIR__ . '/mobile.php';


/**
 * Versioned API Routes
 * 
 * API v1: /api/v1/* - Current stable version
 * API v2: /api/v2/* - Future version (placeholder)
 * 
 * All versioned routes include:
 * - Correlation ID tracking
 * - Currency context handling
 * - API request/response logging
 * - Rate limiting
 * - Authentication (where required)
 */
require __DIR__ . '/api_v1.php';
require __DIR__ . '/api_v2.php';
