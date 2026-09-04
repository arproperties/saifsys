<?php
/**
 * Branding Helper Functions
 * 
 * Provides centralized brand identity management for the BMSystem.
 * Allows dynamic customization of system name, logo, and color scheme.
 * 
 * Usage:
 *   require_once __DIR__ . '/includes/branding.php';
 *   $brand = getBrandSettings($conn);
 *   echo $brand['system_name']; // Full system name
 *   echo $brand['system_name_short']; // Short name (logo)
 *   echo $brand['primary_color']; // Primary brand color
 */

/**
 * Get all branding settings from database
 * 
 * @param PDO $conn Database connection
 * @return array Associative array of branding settings with defaults
 */
function getBrandSettings(PDO $conn): array
{
    static $cache = null;
    
    // Return cached value if available
    if ($cache !== null) {
        return $cache;
    }
    
    // Default branding values
    $defaults = [
        'system_name' => 'BMSystem',
        'system_name_short' => 'BM',
        'primary_color' => '#7a0000',
        'primary_light' => '#910c0c',
        'primary_dark' => '#600000',
        'accent_color' => '#ffd86a',
        'logo_path' => '',
        'dark_mode_enabled' => false
    ];
    
    try {
        $stmt = $conn->prepare("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'brand_%'");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $settings = [];
        foreach ($rows as $row) {
            // Convert brand_system_name to system_name
            $key = str_replace('brand_', '', $row['key']);
            $settings[$key] = $row['value'];
        }
        
        // Merge with defaults (defaults used if not set in database)
        $cache = array_merge($defaults, $settings);
        
    } catch (Throwable $e) {
        error_log("getBrandSettings error: " . $e->getMessage());
        $cache = $defaults;
    }
    
    return $cache;
}

/**
 * Resolve a depth-proof, root-relative web URL for the brand logo.
 *
 * Returns '' when no logo is configured or the file is missing on disk, so
 * callers can simply do `if ($src = brand_logo_src($brand)) { ... }`.
 * The URL is anchored at the application web root (the folder that contains
 * /modules and /uploads), so it works from pages at any folder depth
 * (e.g. /modules/realestate/accounting/...).
 */
if (!function_exists('brand_logo_src')) {
    function brand_logo_src(array $brand): string
    {
        $logo = isset($brand['logo_path']) ? ltrim((string)$brand['logo_path'], '/') : '';
        if ($logo === '') {
            return '';
        }

        // Filesystem check is relative to the project root (folder above /includes).
        $fsRoot = dirname(__DIR__);
        if (!is_file($fsRoot . '/' . $logo)) {
            return '';
        }

        if (!function_exists('get_application_web_root')) {
            $uh = __DIR__ . '/url_helper.php';
            if (is_file($uh)) {
                require_once $uh;
            }
        }
        $base = function_exists('get_application_web_root') ? get_application_web_root() : '';

        return ($base !== '' ? $base : '') . '/' . $logo;
    }
}

/**
 * Get single branding setting
 * 
 * @param PDO $conn Database connection
 * @param string $key Setting key (e.g., 'system_name', 'primary_color')
 * @param string $default Default value if not found
 * @return string Setting value
 */
function getBrandSetting(PDO $conn, string $key, string $default = ''): string
{
    $settings = getBrandSettings($conn);
    return $settings[$key] ?? $default;
}

/**
 * Update branding settings in database
 * 
 * @param PDO $conn Database connection
 * @param array $settings Associative array of settings to update
 * @return bool Success status
 * @throws Exception on database error
 */
function updateBrandSettings(PDO $conn, array $settings): bool
{
    $conn->beginTransaction();
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO settings (`key`, `value`) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
        ");
        
        foreach ($settings as $key => $value) {
            // Prefix with brand_
            $dbKey = 'brand_' . $key;
            $stmt->execute([$dbKey, $value]);
        }
        
        $conn->commit();
        
        // Clear cache
        getBrandSettings($conn); // This will rebuild cache
        
        return true;
        
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("updateBrandSettings error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Generate CSS variables for brand colors
 * 
 * @param PDO $conn Database connection
 * @return string CSS :root block with color variables
 */
function getBrandCSS(PDO $conn): string
{
    $brand = getBrandSettings($conn);
    
    $css = "<style>
    :root {
      --primary: {$brand['primary_color']};
      --primary-light: {$brand['primary_light']};
      --primary-dark: {$brand['primary_dark']};
      --accent: {$brand['accent_color']};
    }
  </style>";
    
    return $css;
}

/**
 * Get brand colors as JSON for JavaScript
 * 
 * @param PDO $conn Database connection
 * @return string JSON string of color values
 */
function getBrandColorsJSON(PDO $conn): string
{
    $brand = getBrandSettings($conn);
    
    $colors = [
        'primary' => $brand['primary_color'],
        'primaryLight' => $brand['primary_light'],
        'primaryDark' => $brand['primary_dark'],
        'accent' => $brand['accent_color']
    ];
    
    return json_encode($colors);
}

/**
 * Escape HTML for safe output
 * 
 * @param string $str String to escape
 * @return string Escaped string
 */
function h_brand($str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * Initialize default branding settings if not exists
 * 
 * @param PDO $conn Database connection
 * @return bool Success status
 */
function initDefaultBrandSettings(PDO $conn): bool
{
    try {
        // Check if branding settings exist
        $stmt = $conn->prepare("SELECT COUNT(*) FROM settings WHERE `key` LIKE 'brand_%'");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        
        // If no branding settings exist, initialize defaults
        if ($count == 0) {
            $defaults = [
                'system_name' => 'BMSystem',
                'system_name_short' => 'BM',
                'primary_color' => '#7a0000',
                'primary_light' => '#910c0c',
                'primary_dark' => '#600000',
                'accent_color' => '#ffd86a'
            ];
            
            return updateBrandSettings($conn, $defaults);
        }
        
        return true;
        
    } catch (Throwable $e) {
        error_log("initDefaultBrandSettings error: " . $e->getMessage());
        return false;
    }
}

