<?php
/**
 * Profile Functions - Helper functions for user profile management
 * 
 * This file provides functions for:
 * - Getting user profile information
 * - Updating user profile data
 * - Changing passwords
 * - Managing avatars
 * - Retrieving activity logs
 * - Managing preferences
 * 
 * All functions integrate with existing AuditService for logging
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/AuditService.php';

/**
 * Get complete user profile with roles
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return array|null User profile data or null if not found
 */
function getUserProfile(PDO $conn, int $user_id): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT u.*, 
                   GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') as roles,
                   GROUP_CONCAT(r.id ORDER BY r.name SEPARATOR ',') as role_ids
            FROM user u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            WHERE u.id = ?
            GROUP BY u.id
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log("getUserProfile error: " . $e->getMessage());
        return null;
    }
}

/**
 * Update user profile (basic info)
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param array $data Profile data to update
 * @return array Result with success status and message
 */
function updateUserProfile(PDO $conn, int $user_id, array $data): array
{
    try {
        // Get old data for audit log
        $oldData = getUserProfile($conn, $user_id);
        
        // Validate email if provided
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email format'];
        }
        
        // Check if email is already used by another user
        if (!empty($data['email'])) {
            $stmt = $conn->prepare("SELECT id FROM user WHERE email = ? AND id != ?");
            $stmt->execute([$data['email'], $user_id]);
            if ($stmt->fetch()) {
                return ['success' => false, 'error' => 'Email already in use'];
            }
        }
        
        // Validate phone if provided
        if (!empty($data['phone']) && !preg_match('/^[+]?[0-9\s\-()]{8,20}$/', $data['phone'])) {
            return ['success' => false, 'error' => 'Invalid phone format'];
        }
        
        // Update user data
        $stmt = $conn->prepare("
            UPDATE user 
            SET fullname = ?, 
                email = ?, 
                phone = ?, 
                job_title = ?, 
                department = ?, 
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $data['fullname'] ?? $oldData['fullname'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['job_title'] ?? null,
            $data['department'] ?? null,
            $user_id
        ]);
        
        if ($result) {
            // Get new data for audit log
            $newData = getUserProfile($conn, $user_id);
            
            // Log the update
            AuditService::logUpdate('user', $user_id, $oldData, $newData, 
                'Updated profile information');
            
            // Update session data
            if (isset($_SESSION['user'])) {
                $_SESSION['user']['fullname'] = $data['fullname'] ?? $oldData['fullname'];
                $_SESSION['user']['full_name'] = $data['fullname'] ?? $oldData['fullname'];
            }
            
            return ['success' => true, 'message' => 'Profile updated successfully'];
        }
        
        return ['success' => false, 'error' => 'Failed to update profile'];
        
    } catch (Throwable $e) {
        error_log("updateUserProfile error: " . $e->getMessage());
        return ['success' => false, 'error' => 'An error occurred while updating profile'];
    }
}

/**
 * Change user password
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param string $currentPass Current password
 * @param string $newPass New password
 * @return array Result with success status and message
 */
function changeUserPassword(PDO $conn, int $user_id, string $currentPass, string $newPass): array
{
    try {
        // Get current password hash
        $stmt = $conn->prepare("SELECT password FROM user WHERE id = ?");
        $stmt->execute([$user_id]);
        $stored = $stmt->fetchColumn();
        
        if (!$stored) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Verify current password
        $isValid = false;
        
        // Check if it's a bcrypt/argon2 hash
        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2')) {
            $isValid = password_verify($currentPass, $stored);
        }
        // Check if it's MD5 (legacy)
        elseif (preg_match('/^[a-f0-9]{32}$/i', $stored)) {
            $isValid = (md5($currentPass) === strtolower($stored));
        }
        // Plain text comparison (legacy)
        else {
            $isValid = hash_equals($stored, $currentPass);
        }
        
        if (!$isValid) {
            AuditService::log([
                'action' => 'password_change',
                'object_type' => 'auth',
                'object_id' => $user_id,
                'summary' => 'Failed password change attempt - incorrect current password',
                'success' => false,
                'error_message' => 'Current password verification failed'
            ]);
            return ['success' => false, 'error' => 'Current password is incorrect'];
        }
        
        // Validate new password strength
        $validation = validatePasswordStrength($newPass);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }
        
        // Hash new password
        $hashed = password_hash($newPass, PASSWORD_BCRYPT);
        
        // Update password
        $stmt = $conn->prepare("UPDATE user SET password = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$hashed, $user_id]);
        
        if ($result) {
            // Log successful password change
            AuditService::log([
                'action' => 'password_change',
                'object_type' => 'auth',
                'object_id' => $user_id,
                'summary' => 'User successfully changed their password',
                'success' => true
            ]);
            
            return ['success' => true, 'message' => 'Password changed successfully'];
        }
        
        return ['success' => false, 'error' => 'Failed to update password'];
        
    } catch (Throwable $e) {
        error_log("changeUserPassword error: " . $e->getMessage());
        return ['success' => false, 'error' => 'An error occurred while changing password'];
    }
}

/**
 * Validate password strength
 * 
 * @param string $password Password to validate
 * @return array Validation result with 'valid' boolean and 'error' message
 */
function validatePasswordStrength(string $password): array
{
    if (strlen($password) < 8) {
        return ['valid' => false, 'error' => 'Password must be at least 8 characters long'];
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        return ['valid' => false, 'error' => 'Password must contain at least one uppercase letter'];
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        return ['valid' => false, 'error' => 'Password must contain at least one lowercase letter'];
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'error' => 'Password must contain at least one number'];
    }
    
    return ['valid' => true];
}

/**
 * Upload and save user avatar
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param array $file Uploaded file array from $_FILES
 * @return array Result with success status, path, and message
 */
function uploadUserAvatar(PDO $conn, int $user_id, array $file): array
{
    try {
        // Validate file upload
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => 'No file uploaded'];
        }
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'File upload error: ' . $file['error']];
        }
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            return ['success' => false, 'error' => 'Invalid file type. Only JPEG and PNG allowed'];
        }
        
        // Validate file size (2MB max)
        $maxSize = 2 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'error' => 'File too large. Maximum size is 2MB'];
        }
        
        // Create upload directory if it doesn't exist
        $uploadDir = __DIR__ . '/../uploads/avatars/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                return ['success' => false, 'error' => 'Failed to create upload directory'];
            }
        }
        
        // Generate unique filename
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'png'])) {
            $ext = 'jpg';
        }
        $filename = 'user_' . $user_id . '_' . time() . '.' . strtolower($ext);
        $uploadPath = $uploadDir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            return ['success' => false, 'error' => 'Failed to save uploaded file'];
        }
        
        // Resize image to 300x300
        $resizeResult = resizeImage($uploadPath, 300, 300);
        if (!$resizeResult) {
            // If resize fails, still keep the original
            error_log("Failed to resize avatar for user {$user_id}");
        }
        
        // Get old avatar path
        $stmt = $conn->prepare("SELECT avatar_path FROM user WHERE id = ?");
        $stmt->execute([$user_id]);
        $oldAvatar = $stmt->fetchColumn();
        
        // Delete old avatar if exists
        if ($oldAvatar && file_exists(__DIR__ . '/../' . $oldAvatar)) {
            @unlink(__DIR__ . '/../' . $oldAvatar);
        }
        
        // Update database
        $relativePath = 'uploads/avatars/' . $filename;
        $stmt = $conn->prepare("UPDATE user SET avatar_path = ?, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$relativePath, $user_id]);
        
        if ($result) {
            // Log to audit
            AuditService::logUpload($filename, $relativePath, 'user', $user_id, 
                'Updated profile picture');
            
            return ['success' => true, 'path' => $relativePath, 'message' => 'Avatar uploaded successfully'];
        }
        
        return ['success' => false, 'error' => 'Failed to update database'];
        
    } catch (Throwable $e) {
        error_log("uploadUserAvatar error: " . $e->getMessage());
        return ['success' => false, 'error' => 'An error occurred while uploading avatar'];
    }
}

/**
 * Delete user avatar
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return array Result with success status and message
 */
function deleteUserAvatar(PDO $conn, int $user_id): array
{
    try {
        // Get current avatar path
        $stmt = $conn->prepare("SELECT avatar_path FROM user WHERE id = ?");
        $stmt->execute([$user_id]);
        $avatarPath = $stmt->fetchColumn();
        
        if (!$avatarPath) {
            return ['success' => false, 'error' => 'No avatar to delete'];
        }
        
        // Delete file
        $fullPath = __DIR__ . '/../' . $avatarPath;
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
        
        // Update database
        $stmt = $conn->prepare("UPDATE user SET avatar_path = NULL, updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$user_id]);
        
        if ($result) {
            AuditService::log([
                'action' => 'delete',
                'object_type' => 'user_avatar',
                'object_id' => $user_id,
                'summary' => 'Deleted profile picture',
                'old_data' => ['avatar_path' => $avatarPath],
                'success' => true
            ]);
            
            return ['success' => true, 'message' => 'Avatar deleted successfully'];
        }
        
        return ['success' => false, 'error' => 'Failed to update database'];
        
    } catch (Throwable $e) {
        error_log("deleteUserAvatar error: " . $e->getMessage());
        return ['success' => false, 'error' => 'An error occurred while deleting avatar'];
    }
}

/**
 * Resize image to specified dimensions (maintains aspect ratio, crops to square)
 * 
 * @param string $path Path to image file
 * @param int $width Target width
 * @param int $height Target height
 * @return bool Success status
 */
function resizeImage(string $path, int $width, int $height): bool
{
    try {
        if (!file_exists($path)) {
            return false;
        }
        
        list($origWidth, $origHeight, $type) = getimagesize($path);
        
        if (!$origWidth || !$origHeight) {
            return false;
        }
        
        // Create source image
        $source = match($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            default => false
        };
        
        if (!$source) {
            return false;
        }
        
        // Calculate crop dimensions (center crop to square)
        $cropSize = min($origWidth, $origHeight);
        $cropX = (int)(($origWidth - $cropSize) / 2);
        $cropY = (int)(($origHeight - $cropSize) / 2);
        
        // Create thumbnail
        $thumb = imagecreatetruecolor($width, $height);
        
        // Preserve transparency for PNG
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefill($thumb, 0, 0, $transparent);
        }
        
        // Resample image
        imagecopyresampled(
            $thumb, $source,
            0, 0, $cropX, $cropY,
            $width, $height, $cropSize, $cropSize
        );
        
        // Save image
        $result = match($type) {
            IMAGETYPE_JPEG => imagejpeg($thumb, $path, 90),
            IMAGETYPE_PNG => imagepng($thumb, $path, 9),
            default => false
        };
        
        // Clean up
        imagedestroy($source);
        imagedestroy($thumb);
        
        return $result;
        
    } catch (Throwable $e) {
        error_log("resizeImage error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user activity from audit_log
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param int $limit Number of records to return
 * @param int $offset Offset for pagination
 * @param string|null $actionFilter Filter by action type (optional)
 * @return array Activity records
 */
function getUserActivity(PDO $conn, int $user_id, int $limit = 20, int $offset = 0, ?string $actionFilter = null): array
{
    try {
        $sql = "
            SELECT action, object_type, object_id, summary, 
                   ip_address, success, created_at
            FROM audit_log
            WHERE user_id = ?
        ";
        
        if ($actionFilter) {
            $sql .= " AND action = ?";
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        
        $stmt = $conn->prepare($sql);
        
        // Bind parameters
        $paramIndex = 1;
        $stmt->bindValue($paramIndex++, $user_id, PDO::PARAM_INT);
        
        if ($actionFilter) {
            $stmt->bindValue($paramIndex++, $actionFilter, PDO::PARAM_STR);
        }
        
        $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Throwable $e) {
        error_log("getUserActivity error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get count of user activity records
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param string|null $actionFilter Filter by action type (optional)
 * @return int Total count
 */
function getUserActivityCount(PDO $conn, int $user_id, ?string $actionFilter = null): int
{
    try {
        $sql = "SELECT COUNT(*) FROM audit_log WHERE user_id = ?";
        $params = [$user_id];
        
        if ($actionFilter) {
            $sql .= " AND action = ?";
            $params[] = $actionFilter;
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
        
    } catch (Throwable $e) {
        error_log("getUserActivityCount error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get user login history from audit_log
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param int $limit Number of records to return
 * @return array Login history records
 */
function getUserLoginHistory(PDO $conn, int $user_id, int $limit = 20): array
{
    try {
        $stmt = $conn->prepare("
            SELECT summary, ip_address, user_agent, created_at, success
            FROM audit_log
            WHERE user_id = ? AND action = 'login'
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Throwable $e) {
        error_log("getUserLoginHistory error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user profile change history from audit_log
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param int $limit Number of records to return
 * @return array Profile change records
 */
function getUserProfileChanges(PDO $conn, int $user_id, int $limit = 20): array
{
    try {
        $stmt = $conn->prepare("
            SELECT action, summary, old_data, new_data, created_at
            FROM audit_log
            WHERE user_id = ? 
            AND object_type = 'user'
            AND action IN ('update', 'password_change', 'upload')
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Throwable $e) {
        error_log("getUserProfileChanges error: " . $e->getMessage());
        return [];
    }
}

/**
 * Update user preferences
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param array $prefs Preferences array
 * @return array Result with success status and message
 */
function updateUserPreferences(PDO $conn, int $user_id, array $prefs): array
{
    try {
        // Validate theme
        $allowedThemes = ['light', 'dark', 'auto'];
        $theme = isset($prefs['theme']) && in_array($prefs['theme'], $allowedThemes) 
            ? $prefs['theme'] 
            : 'light';
        
        // Validate language
        $allowedLangs = ['en', 'ar'];
        $lang = isset($prefs['language']) && in_array($prefs['language'], $allowedLangs)
            ? $prefs['language']
            : 'en';
        
        // Validate timezone
        $timezone = $prefs['timezone'] ?? 'Asia/Dubai';
        
        // Email notifications
        $emailNotif = isset($prefs['email_notifications']) ? 1 : 0;
        
        // Update database
        $stmt = $conn->prepare("
            UPDATE user 
            SET theme_preference = ?,
                language_preference = ?,
                timezone = ?,
                email_notifications = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $result = $stmt->execute([$theme, $lang, $timezone, $emailNotif, $user_id]);
        
        if ($result) {
            AuditService::log([
                'action' => 'update',
                'object_type' => 'user_preferences',
                'object_id' => $user_id,
                'summary' => 'Updated user preferences',
                'new_data' => [
                    'theme' => $theme,
                    'language' => $lang,
                    'timezone' => $timezone,
                    'email_notifications' => $emailNotif
                ],
                'success' => true
            ]);
            
            return ['success' => true, 'message' => 'Preferences updated successfully'];
        }
        
        return ['success' => false, 'error' => 'Failed to update preferences'];
        
    } catch (Throwable $e) {
        error_log("updateUserPreferences error: " . $e->getMessage());
        return ['success' => false, 'error' => 'An error occurred while updating preferences'];
    }
}

/**
 * Update last login timestamp
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return bool Success status
 */
function updateLastLogin(PDO $conn, int $user_id): bool
{
    try {
        $stmt = $conn->prepare("UPDATE user SET last_login = NOW() WHERE id = ?");
        return $stmt->execute([$user_id]);
    } catch (Throwable $e) {
        error_log("updateLastLogin error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user profile statistics
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return array Statistics array
 */
function getUserStats(PDO $conn, int $user_id): array
{
    try {
        $stats = [];
        
        // Total logins
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM audit_log 
            WHERE user_id = ? AND action = 'login' AND success = 1
        ");
        $stmt->execute([$user_id]);
        $stats['total_logins'] = (int)$stmt->fetchColumn();
        
        // Profile updates
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM audit_log 
            WHERE user_id = ? AND object_type = 'user' AND action = 'update'
        ");
        $stmt->execute([$user_id]);
        $stats['profile_updates'] = (int)$stmt->fetchColumn();
        
        // Total actions
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM audit_log WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $stats['total_actions'] = (int)$stmt->fetchColumn();
        
        // Last activity
        $stmt = $conn->prepare("
            SELECT created_at FROM audit_log 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $stats['last_activity'] = $stmt->fetchColumn();
        
        return $stats;
        
    } catch (Throwable $e) {
        error_log("getUserStats error: " . $e->getMessage());
        return [
            'total_logins' => 0,
            'profile_updates' => 0,
            'total_actions' => 0,
            'last_activity' => null
        ];
    }
}

