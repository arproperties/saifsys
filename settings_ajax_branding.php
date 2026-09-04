<?php
/**
 * AJAX Handler for Branding Settings
 * 
 * Handles all AJAX requests for managing brand identity:
 * - Get current branding settings
 * - Update branding settings
 * - Reset to defaults
 */

// Function to send JSON error response
function sendJsonError($message, $code = 500) {
    ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    exit;
}

// Function to send JSON success response
function sendJsonSuccess($data) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    exit;
}

// Start output buffering to prevent any accidental output
ob_start();

// Suppress warnings/notices that might corrupt JSON  
error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR);

// CRITICAL: Set AJAX detection headers BEFORE any includes
// This ensures Guard class and auth.php know this is an AJAX request
if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
}
if (empty($_SERVER['HTTP_ACCEPT']) || strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === false) {
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
}

// Set JSON header IMMEDIATELY before any includes
if (!headers_sent()) {
    header('Content-Type: application/json');
}

try {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/db_connect.php';
    require_once __DIR__ . '/includes/branding.php';
    
    // Re-check headers after includes (includes might have tried to redirect)
    if (headers_sent()) {
        // Headers were already sent (likely a redirect), force JSON error
        ob_clean();
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }
} catch (Throwable $e) {
    error_log("Branding AJAX initialization error: " . $e->getMessage());
    sendJsonError('Initialization error: ' . $e->getMessage(), 500);
}

// Clear any output that might have been generated (including from includes)
ob_clean();

// Check authentication and permissions
try {
    if (!is_logged_in()) {
        sendJsonError('Unauthorized', 401);
    }

    // Only Owner and Admin can manage branding
    if (!has_role('Owner', $conn) && !has_role('Admin', $conn)) {
        sendJsonError('Forbidden - Admin or Owner role required', 403);
    }
} catch (Throwable $e) {
    error_log("Branding AJAX auth check error: " . $e->getMessage());
    sendJsonError('Authentication check failed: ' . $e->getMessage(), 500);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // Get current branding settings
        case 'get_settings':
            try {
                $settings = getBrandSettings($conn);
                sendJsonSuccess([
                    'success' => true,
                    'settings' => $settings
                ]);
            } catch (Throwable $e) {
                error_log("Error in get_settings: " . $e->getMessage());
                sendJsonError('Failed to load branding settings: ' . $e->getMessage(), 500);
            }
            break;
            
        // Update branding settings
        case 'update_settings':
            // Validate inputs
            $systemName = trim($_POST['system_name'] ?? '');
            $systemNameShort = trim($_POST['system_name_short'] ?? '');
            $primaryColor = trim($_POST['primary_color'] ?? '');
            $primaryLight = trim($_POST['primary_light'] ?? '');
            $primaryDark = trim($_POST['primary_dark'] ?? '');
            $accentColor = trim($_POST['accent_color'] ?? '');
            
            // Validation
            $errors = [];
            
            if (empty($systemName)) {
                $errors[] = 'System name is required';
            }
            
            if (empty($systemNameShort)) {
                $errors[] = 'Short name is required';
            }
            
            // Validate color formats
            $colorPattern = '/^#[0-9A-Fa-f]{6}$/';
            
            if (!preg_match($colorPattern, $primaryColor)) {
                $errors[] = 'Invalid primary color format';
            }
            
            if (!preg_match($colorPattern, $primaryLight)) {
                $errors[] = 'Invalid primary light color format';
            }
            
            if (!preg_match($colorPattern, $primaryDark)) {
                $errors[] = 'Invalid primary dark color format';
            }
            
            if (!preg_match($colorPattern, $accentColor)) {
                $errors[] = 'Invalid accent color format';
            }
            
            if (!empty($errors)) {
                ob_clean();
                echo json_encode([
                    'success' => false,
                    'error' => implode(', ', $errors)
                ]);
                ob_end_flush();
                exit;
            }
            
            // Handle logo upload
            $logoPath = $_POST['logo_path'] ?? ''; // Existing logo path
            
            // Check if a NEW file was actually uploaded.
            // Browsers always submit the (empty) file field, reporting
            // UPLOAD_ERR_NO_FILE when nothing was chosen — in that case we must
            // keep the existing logo instead of treating it as an error.
            if (isset($_FILES['logo_file']) && ($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['logo_file'];
                $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
                
                // Check for upload errors
                if ($uploadError !== UPLOAD_ERR_OK) {
                    $errorMessages = [
                        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
                        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
                        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                        UPLOAD_ERR_EXTENSION => 'File upload stopped by PHP extension'
                    ];
                    
                    $errorMsg = $errorMessages[$uploadError] ?? 'Unknown upload error (code: ' . $uploadError . ')';
                    error_log("Logo upload error: " . $errorMsg . " (code: " . $uploadError . ")");
                    
                    ob_clean();
                    echo json_encode([
                        'success' => false,
                        'error' => 'Upload failed: ' . $errorMsg
                    ]);
                    ob_end_flush();
                    exit;
                }
                
                // Validate file exists and was uploaded
                if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                    error_log("Logo upload error: Invalid file upload - tmp_name not set or not uploaded");
                    ob_clean();
                    echo json_encode([
                        'success' => false,
                        'error' => 'Invalid file upload. Please try again.'
                    ]);
                    ob_end_flush();
                    exit;
                }
                
                $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml', 'image/webp'];
                $maxSize = 2 * 1024 * 1024; // 2MB
                
                // Validate file type
                $fileType = $file['type'] ?? '';
                if (!in_array($fileType, $allowedTypes)) {
                    error_log("Logo upload error: Invalid file type - " . $fileType);
                    ob_clean();
                    echo json_encode([
                        'success' => false,
                        'error' => 'Invalid logo file type (' . $fileType . '). Only PNG, JPG, SVG, and WebP are allowed.'
                    ]);
                    ob_end_flush();
                    exit;
                }
                
                // Validate file size
                $fileSize = $file['size'] ?? 0;
                if ($fileSize <= 0) {
                    error_log("Logo upload error: File size is zero or invalid");
                    ob_clean();
                    echo json_encode([
                        'success' => false,
                        'error' => 'Invalid file size. Please try again.'
                    ]);
                    ob_end_flush();
                    exit;
                }
                
                if ($fileSize > $maxSize) {
                    error_log("Logo upload error: File size exceeds limit - " . $fileSize . " bytes");
                    ob_clean();
                    echo json_encode([
                        'success' => false,
                        'error' => 'Logo file size (' . round($fileSize / 1024 / 1024, 2) . 'MB) exceeds 2MB limit.'
                    ]);
                    ob_end_flush();
                    exit;
                }
                
                // Create upload directory if doesn't exist
                $uploadDir = __DIR__ . '/uploads/branding/';
                
                // Ensure parent directory exists
                $parentDir = dirname($uploadDir);
                if (!is_dir($parentDir)) {
                    if (!mkdir($parentDir, 0777, true)) {
                        error_log("Logo upload error: Failed to create parent directory - " . $parentDir);
                        ob_clean();
                        echo json_encode([
                            'success' => false,
                            'error' => 'Failed to create upload directory. Please check server permissions on ' . $parentDir
                        ]);
                        ob_end_flush();
                        exit;
                    }
                }
                
                if (!is_dir($uploadDir)) {
                    // Try to create with maximum permissions
                    if (!@mkdir($uploadDir, 0777, true)) {
                        error_log("Logo upload error: Failed to create upload directory - " . $uploadDir);
                        error_log("Current permissions of parent: " . substr(sprintf('%o', fileperms($parentDir)), -4));
                        
                        // Try to diagnose the issue
                        $phpUser = get_current_user();
                        $isWritable = is_writable($parentDir);
                        error_log("PHP running as: " . $phpUser . ", Parent writable: " . ($isWritable ? 'yes' : 'no'));
                        
                        ob_clean();
                        echo json_encode([
                            'success' => false,
                            'error' => 'Failed to create upload directory at ' . $uploadDir . '. Please manually create the directory "uploads/branding" and set permissions to 777.'
                        ]);
                        ob_end_flush();
                        exit;
                    }
                    // Set permissions after creation
                    @chmod($uploadDir, 0777);
                }
                
                // Check if directory is writable - try multiple times to fix permissions
                $attempts = 0;
                $maxAttempts = 3;
                while (!is_writable($uploadDir) && $attempts < $maxAttempts) {
                    $attempts++;
                    
                    // Try to fix permissions multiple ways
                    @chmod($uploadDir, 0777);
                    @chmod($parentDir, 0777); // Also fix parent
                    
                    // Use system commands as a last resort (if available)
                    if ($attempts >= 2 && function_exists('exec')) {
                        $escapedPath = escapeshellarg($uploadDir);
                        @exec("chmod 777 $escapedPath 2>&1", $output, $returnCode);
                        error_log("Attempted chmod via exec: " . implode("\n", $output));
                    }
                    
                    // Small delay before checking again
                    usleep(100000); // 0.1 seconds
                }
                
                // Final check
                if (!is_writable($uploadDir)) {
                    $currentPerms = substr(sprintf('%o', @fileperms($uploadDir)), -4);
                    $parentPerms = is_dir($parentDir) ? substr(sprintf('%o', @fileperms($parentDir)), -4) : 'N/A';
                    
                    error_log("Logo upload error: Upload directory is not writable - " . $uploadDir);
                    error_log("Current permissions: $currentPerms (parent: $parentPerms)");
                    error_log("Directory exists: " . (is_dir($uploadDir) ? 'yes' : 'no'));
                    
                    // Provide helpful instructions
                    ob_clean();
                    echo json_encode([
                        'success' => false,
                        'error' => 'Upload directory is not writable. Current permissions: ' . $currentPerms . '. ' .
                                   'Please visit http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/fix_permissions_now.php to fix permissions automatically, ' .
                                   'or run: chmod -R 777 ' . $uploadDir
                    ]);
                    ob_end_flush();
                    exit;
                }
                
                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                if (empty($extension)) {
                    // Try to detect extension from MIME type
                    $mimeToExt = [
                        'image/png' => 'png',
                        'image/jpeg' => 'jpg',
                        'image/jpg' => 'jpg',
                        'image/svg+xml' => 'svg',
                        'image/webp' => 'webp'
                    ];
                    $extension = $mimeToExt[$fileType] ?? 'png';
                }
                $filename = 'logo_' . time() . '_' . uniqid() . '.' . strtolower($extension);
                $targetPath = $uploadDir . $filename;
                
                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    // Verify file was moved
                    if (file_exists($targetPath)) {
                        // Delete old logo if exists
                        if ($logoPath && file_exists(__DIR__ . '/' . $logoPath)) {
                            @unlink(__DIR__ . '/' . $logoPath);
                        }
                        $logoPath = 'uploads/branding/' . $filename;
                        error_log("Logo uploaded successfully: " . $logoPath);
                    } else {
                        error_log("Logo upload error: File moved but does not exist at target path - " . $targetPath);
                        ob_clean();
                        echo json_encode([
                            'success' => false,
                            'error' => 'File upload failed. Please try again.'
                        ]);
                        ob_end_flush();
                        exit;
                    }
                } else {
                    $lastError = error_get_last();
                    error_log("Logo upload error: move_uploaded_file failed - " . ($lastError['message'] ?? 'Unknown error'));
                    error_log("Source: " . $file['tmp_name'] . " | Destination: " . $targetPath);
                    error_log("File exists: " . (file_exists($file['tmp_name']) ? 'yes' : 'no'));
                    error_log("Directory writable: " . (is_writable($uploadDir) ? 'yes' : 'no'));
                    
                    ob_clean();
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to move uploaded file. Please check server permissions or try again.'
                    ]);
                    ob_end_flush();
                    exit;
                }
            } elseif (isset($_POST['logo_path']) && empty($_POST['logo_path'])) {
                // Logo removed
                if ($logoPath && file_exists(__DIR__ . '/' . $logoPath)) {
                    @unlink(__DIR__ . '/' . $logoPath);
                }
                $logoPath = '';
            }
            
            // Handle dark mode
            $darkModeEnabled = isset($_POST['dark_mode_enabled']) && $_POST['dark_mode_enabled'] === 'on' ? true : false;
            
            // Update settings
            $settings = [
                'system_name' => $systemName,
                'system_name_short' => $systemNameShort,
                'primary_color' => $primaryColor,
                'primary_light' => $primaryLight,
                'primary_dark' => $primaryDark,
                'accent_color' => $accentColor,
                'logo_path' => $logoPath,
                'dark_mode_enabled' => $darkModeEnabled
            ];
            
            updateBrandSettings($conn, $settings);
            
            // Audit Log
            require_once __DIR__ . '/includes/AuditService.php';
            AuditService::log([
                'action' => 'update',
                'object_type' => 'branding_settings',
                'summary' => 'Updated branding settings: ' . $systemName,
                'new_data' => $settings,
                'success' => true
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Branding settings updated successfully',
                'settings' => $settings
            ]);
            break;
            
        // Reset to defaults
        case 'reset_defaults':
            // Get current logo to delete it
            $currentSettings = getBrandSettings($conn);
            if (!empty($currentSettings['logo_path']) && file_exists(__DIR__ . '/' . $currentSettings['logo_path'])) {
                unlink(__DIR__ . '/' . $currentSettings['logo_path']);
            }
            
            $defaults = [
                'system_name' => 'BMSystem',
                'system_name_short' => 'BM',
                'primary_color' => '#7a0000',
                'primary_light' => '#910c0c',
                'primary_dark' => '#600000',
                'accent_color' => '#ffd86a',
                'logo_path' => ''
            ];
            
            updateBrandSettings($conn, $defaults);
            
            // Audit Log
            require_once __DIR__ . '/includes/AuditService.php';
            AuditService::log([
                'action' => 'update',
                'object_type' => 'branding_settings',
                'summary' => 'Reset branding settings to defaults',
                'new_data' => $defaults,
                'success' => true
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Branding settings reset to defaults',
                'settings' => $defaults
            ]);
            break;
            
        default:
            sendJsonError('Invalid action', 400);
            break;
    }
    
} catch (Throwable $e) {
    error_log("Branding AJAX error: " . $e->getMessage());
    error_log("Branding AJAX trace: " . $e->getTraceAsString());
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}

// Final check - make sure we always output JSON
if (ob_get_level() > 0) {
    $output = ob_get_contents();
    ob_end_clean();
    
    // Only send if we haven't already sent JSON via sendJsonSuccess/sendJsonError
    if (!empty($output)) {
        $trimmed = trim($output);
        // Check if it's already valid JSON
        if (($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = @json_decode($trimmed);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Valid JSON, send it
                header('Content-Type: application/json');
                echo $trimmed;
            } else {
                // Not valid JSON, send error
                header('Content-Type: application/json');
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Invalid JSON response']);
            }
        } else {
            // Not JSON, send error
            error_log("Branding AJAX: Non-JSON output detected: " . substr($trimmed, 0, 200));
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Server returned invalid response']);
        }
    } else {
        // No output at all - this shouldn't happen, but send error JSON
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'No response generated']);
    }
}
