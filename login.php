<?php
// login.php — Modern Enhanced Version with Professional Design
// Features: Dynamic branding, Smooth animations, Floating labels, Loading states, Security badges

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/branding.php';

// Get branding settings
$brand = getBrandSettings($conn);

// Already logged in? Go home.
if (function_exists('is_logged_in') ? is_logged_in() : (isset($_SESSION['user']['id']) || isset($_SESSION['user_id']))) {
    header('Location: index');
    exit;
}

// Helpers
function safe_next_redirect(string $raw): string {
    $raw = trim($raw);
    if ($raw === '' || str_starts_with($raw, '//') || preg_match('#^[a-z]+://#i', $raw)) {
        return 'index';
    }
    // Remove .php extension if present
    $raw = preg_replace('/\.php$/', '', $raw);
    return $raw;
}

$error = '';
$info = '';
if (isset($_GET['reset']) && (string)$_GET['reset'] === '1') {
    $info = 'Your password was updated. You can sign in with your new password.';
}
$MAX_ATTEMPTS = 5;
$WINDOW_SEC   = 15 * 60;
$ip           = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if (!isset($_SESSION['rate_login'])) $_SESSION['rate_login'] = [];
$bucket_ip = &$_SESSION['rate_login'][$ip];
if (empty($bucket_ip) || (time() - ($bucket_ip['ts'] ?? 0)) > $WINDOW_SEC) {
    $bucket_ip = ['c' => 0, 'ts' => time()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    $usrKey = $ip . '|' . strtolower($username);
    if (!isset($_SESSION['rate_login'][$usrKey]) || (time() - ($_SESSION['rate_login'][$usrKey]['ts'] ?? 0)) > $WINDOW_SEC) {
        $_SESSION['rate_login'][$usrKey] = ['c' => 0, 'ts' => time()];
    }
    $bucket_user = &$_SESSION['rate_login'][$usrKey];

    if ($bucket_ip['c'] >= $MAX_ATTEMPTS || $bucket_user['c'] >= $MAX_ATTEMPTS) {
        http_response_code(429);
        $error = 'Too many attempts. Please try again in a few minutes.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, fullname, password FROM `user` WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $ok = false;
        if ($user) {
            $stored = (string)($user['password'] ?? '');

            if ($stored !== '' && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2'))) {
                $ok = password_verify($password, $stored);
                if ($ok && password_needs_rehash($stored, PASSWORD_BCRYPT)) {
                    $conn->prepare("UPDATE `user` SET password=? WHERE id=?")
                         ->execute([password_hash($password, PASSWORD_BCRYPT), (int)$user['id']]);
                }
            } elseif ($stored !== '' && preg_match('/^[a-f0-9]{32}$/i', $stored)) {
                $ok = (md5($password) === strtolower($stored));
                if ($ok) {
                    $conn->prepare("UPDATE `user` SET password=? WHERE id=?")
                         ->execute([password_hash($password, PASSWORD_BCRYPT), (int)$user['id']]);
                }
            } else {
                $ok = hash_equals($stored, $password);
                if ($ok) {
                    $conn->prepare("UPDATE `user` SET password=? WHERE id=?")
                         ->execute([password_hash($password, PASSWORD_BCRYPT), (int)$user['id']]);
                }
            }
        }

        if ($ok) {
            // Success: reset buckets, harden session
            $bucket_ip = ['c' => 0, 'ts' => time()];
            $bucket_user = ['c' => 0, 'ts' => time()];

            session_regenerate_id(true);
            set_logged_in_user((int)$user['id'], $user['username'] ?? null, $user['fullname'] ?? null);

            // Fetch user roles
            $roleIds = [];
            $roleNames = [];

            $rq = $conn->prepare("
                SELECT r.id, r.name
                FROM user_roles ur
                INNER JOIN roles r ON ur.role_id = r.id
                WHERE ur.user_id = ?
            ");
            $rq->execute([(int)$user['id']]);
            $roles = $rq->fetchAll(PDO::FETCH_ASSOC);

            if ($roles) {
                $roleIds   = array_column($roles, 'id');
                $roleNames = array_map('strval', array_column($roles, 'name'));
            }

            $_SESSION['roles']      = $roleIds;
            $_SESSION['role_names'] = $roleNames;

            // Tenant portal: if this user has an approved portal account, redirect to tenant portal
            $tpa = $conn->prepare("SELECT 1 FROM tenant_portal_accounts WHERE user_id = ? AND status = 'approved' LIMIT 1");
            $tpa->execute([(int)$user['id']]);
            if ($tpa->fetchColumn()) {
                require_once __DIR__ . '/includes/url_helper.php';
                $base = get_base_path() ? get_base_path() . '/' : '';
                header('Location: ' . $base . 'tenant_portal/dashboard.php');
                exit;
            }

            if (!class_exists('Guard')) {
                require_once __DIR__ . '/lib/Guard.php';
            }

            $workerMappingMissing = false;
            if (Guard::isWorker($roleNames ?? [])) {
                $employeeId = Guard::resolveEmployeeId($conn, (int)$user['id']);
                if ($employeeId) {
                    $_SESSION['worker_employee_id'] = $employeeId;
                    $_SESSION['user']['employee_id'] = $employeeId;

                    require_once __DIR__ . '/includes/AuditService.php';
                    AuditService::logEvent([
                        'action' => 'login_redirect',
                        'module' => 'auth',
                        'object_type' => 'auth',
                        'object_id' => (string)$employeeId,
                        'object_ref' => 'Sign-in',
                        'summary' => "Worker redirected to own profile after login",
                        'new_data' => ['employee_id' => $employeeId],
                        'source' => 'user',
                        'success' => true,
                    ]);

                    $target = Guard::employeeProfileLink($employeeId);
                    header('Location: ' . $target);
                    exit;
                }

                require_once __DIR__ . '/includes/AuditService.php';
                AuditService::logEvent([
                    'action' => 'login_redirect_failed',
                    'module' => 'auth',
                    'object_type' => 'auth',
                    'object_ref' => 'Sign-in',
                    'summary' => 'Worker login missing employee mapping',
                    'source' => 'user',
                    'success' => false,
                    'error_message' => 'No employee linked to user',
                ]);

                $error = 'Your account is not linked to an employee profile yet. Please contact HR.';
                $workerMappingMissing = true;
                unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['roles'], $_SESSION['role_names'], $_SESSION['worker_employee_id']);
            }

            if ($workerMappingMissing) {
                // stay on login page and show error
                $ok = false;
            } else {
                // Audit Log: Track successful login
                require_once __DIR__ . '/includes/AuditService.php';
                AuditService::logEvent([
                    'action' => 'login',
                    'module' => 'auth',
                    'object_type' => 'auth',
                    'object_ref' => 'Sign-in',
                    'summary' => "User {$user['username']} logged in successfully",
                    'source' => 'user',
                    'success' => true
                ]);
                
                // Update last login
                require_once __DIR__ . '/includes/profile_functions.php';
                updateLastLogin($conn, (int)$user['id']);

                // Remember Me
                if ($remember) {
                    $conn->prepare("DELETE FROM user_tokens WHERE user_id=? OR expires < NOW()")->execute([(int)$user['id']]);

                    $selector        = bin2hex(random_bytes(8));
                    $validator       = bin2hex(random_bytes(32));
                    $hashedValidator = hash('sha256', $validator);
                    $expiresAt       = time() + 60*60*24*30;
                    $expiresStr      = date('Y-m-d H:i:s', $expiresAt);

                    $conn->prepare("INSERT INTO user_tokens (user_id, selector, hashed_validator, expires)
                                    VALUES (?, ?, ?, ?)")
                         ->execute([(int)$user['id'], $selector, $hashedValidator, $expiresStr]);

                    $token = $selector . ':' . $validator;
                    setcookie('rememberme', $token, [
                        'expires'  => $expiresAt,
                        'path'     => '/',
                        'secure'   => !empty($_SERVER['HTTPS']),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                } else {
                    if (!empty($_COOKIE['rememberme'])) {
                        setcookie('rememberme', '', time() - 3600, '/');
                    }
                }

                // Multi-company/module routing logic
                $userId = (int)$user['id'];
                $userCompanies = get_user_companies($conn, $userId);
                $userModules = get_user_modules($conn, $userId);
                $isOwnerAdmin = in_array('Owner', $roleNames, true) || in_array('Admin', $roleNames, true);
                
                // Owner/Admin with multiple companies/modules: Show selector
                // They need to choose which company/module to work with
                if ($isOwnerAdmin && (count($userCompanies) > 1 || count($userModules) > 1)) {
                    // Clear any cached company_id to force fresh selection
                    unset($_SESSION['current_company_id']);
                    $_SESSION['needs_module_selection'] = true;
                    $next = 'select-module';
                } 
                // Single company: Check if user has only one module with departments
                elseif (count($userCompanies) === 1) {
                    require_once __DIR__ . '/includes/rbac_department.php';
                    require_once __DIR__ . '/includes/module_access.php';
                    
                    $company = $userCompanies[0];
                    set_current_company($company['id']);
                    
                    // Get user's departments
                    $userDepartments = get_user_departments($userId, $conn);
                    
                    // Filter modules to only those with departments
                    $modulesWithDepts = [];
                    foreach ($userModules as $module) {
                        $moduleName = is_array($module) ? $module['module'] : $module;
                        $moduleDepts = $userDepartments[$moduleName] ?? [];
                        if (!empty($moduleDepts)) {
                            $modulesWithDepts[] = [
                                'module' => $moduleName,
                                'departments' => $moduleDepts
                            ];
                        }
                    }
                    
                    // If only one module with departments, redirect directly
                    if (count($modulesWithDepts) === 1) {
                        $moduleData = $modulesWithDepts[0];
                        $moduleName = $moduleData['module'];
                        $moduleDepts = $moduleData['departments'];
                        
                        // Redirect to department landing (grocery/barber: POS vs back office, not alphabetically first dept)
                        if (!empty($moduleDepts)) {
                            if ($moduleName === MODULE_GROCERY || $moduleName === MODULE_BARBER) {
                                $route = resolve_grocery_or_barber_entry_route($moduleName, $moduleDepts);
                            } else {
                                $dept = $moduleDepts[0];
                                $route = get_department_route($moduleName, $dept);
                            }
                            if ($route) {
                                $next = $route;
                            } else {
                                $next = get_module_route($moduleName, $userId);
                            }
                        } else {
                            $next = get_module_route($moduleName, $userId);
                        }
                    } else {
                        // Multiple modules or no modules with departments - show selector
                        $_SESSION['needs_module_selection'] = true;
                        $next = 'select-module';
                    }
                }
                // Multiple companies or modules: Show selector
                // Note: Owner/Admin with multiple modules should see selector
                elseif (count($userCompanies) > 1 || count($userModules) > 1) {
                    $_SESSION['needs_module_selection'] = true;
                    $next = 'select-module';
                }
                // Default: Use original next or index
                else {
                    $next = safe_next_redirect($_GET['next'] ?? $_POST['next'] ?? $_SESSION['login_next'] ?? 'index');
                }
                
                unset($_SESSION['login_next']); // Clear after use
                header('Location: ' . $next);
                exit;
            }
        }

        // Failure: increment buckets
        $bucket_ip['c']++;   $bucket_ip['ts']   = time();
        $bucket_user['c']++; $bucket_user['ts'] = time();
        $error = 'Invalid username or password.';
        
        // Audit Log: Track failed login attempt
        require_once __DIR__ . '/includes/AuditService.php';
        AuditService::logEvent([
            'action' => 'login',
            'module' => 'auth',
            'object_type' => 'auth',
            'object_ref' => 'Sign-in',
            'summary' => "Failed login attempt for username: {$username}",
            'source' => 'user',
            'success' => false,
            'error_message' => 'Invalid credentials',
            'user_id' => null,
            'user_name' => $username,
            'user_role' => null
        ]);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Login – <?= htmlspecialchars($brand['system_name'], ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">

  <!-- Bootstrap + Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

  <style>
    :root {
      --primary: <?= $brand['primary_color'] ?>;
      --primary-light: <?= $brand['primary_light'] ?>;
      --primary-dark: <?= $brand['primary_dark'] ?>;
      --accent: <?= $brand['accent_color'] ?>;
      --card-radius: 26px;
      --input-radius: 12px;
      --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * {
      font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      margin: 0; padding: 0; box-sizing: border-box;
    }

    body {
      min-height: 100vh;
      background:
        linear-gradient(135deg, rgba(15,23,42,0.82) 0%, rgba(23,37,84,0.74) 100%),
        url('assets/login-bg.webp') center center / cover no-repeat fixed;
      display: flex; align-items: center; justify-content: center;
      padding: 24px; position: relative; overflow: hidden;
    }

    /* Main split card */
    .auth-shell {
      position: relative; z-index: 10;
      width: 100%; max-width: 1000px;
      display: grid; grid-template-columns: 1.05fr 1fr;
      background: #fff;
      border-radius: var(--card-radius);
      overflow: hidden;
      box-shadow: 0 40px 100px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(255,255,255,0.12);
      animation: slideUp 0.6s ease-out;
    }
    @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

    /* ---------------- LEFT: Brand hero ---------------- */
    .auth-hero {
      position: relative;
      padding: 3.25rem 3rem;
      color: #fff;
      background: linear-gradient(155deg, var(--primary-dark) 0%, var(--primary) 55%, var(--primary-light) 130%);
      display: flex; flex-direction: column;
      overflow: hidden;
      isolation: isolate;
    }
    .auth-hero::before {
      content: ''; position: absolute; inset: 0; z-index: -1;
      background:
        radial-gradient(420px 420px at 85% 12%, rgba(255,255,255,0.16), transparent 60%),
        radial-gradient(360px 360px at -10% 100%, rgba(255,255,255,0.10), transparent 60%);
    }
    .auth-hero .ring {
      position: absolute; border-radius: 50%; border: 1px solid rgba(255,255,255,0.18);
      z-index: -1; animation: float 22s ease-in-out infinite;
    }
    .auth-hero .ring.r1 { width: 360px; height: 360px; top: -120px; right: -120px; }
    .auth-hero .ring.r2 { width: 240px; height: 240px; bottom: -90px; left: -70px; animation-direction: reverse; }
    .auth-hero .ring.r3 { width: 140px; height: 140px; top: 40%; right: -40px; opacity: .6; }
    @keyframes float {
      0%, 100% { transform: translate(0,0); }
      50% { transform: translate(16px, -18px); }
    }

    .hero-logo {
      width: 76px; height: 76px; border-radius: 20px;
      background: rgba(255,255,255,0.14);
      border: 1px solid rgba(255,255,255,0.25);
      backdrop-filter: blur(6px);
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: 1.9rem; font-weight: 800; letter-spacing: 1px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.18);
      overflow: hidden;
    }
    .hero-logo img { width: 100%; height: 100%; object-fit: contain; }

    .hero-name {
      font-family: 'Playfair Display', Georgia, serif;
      font-size: 2.15rem; line-height: 1.15; font-weight: 700;
      margin-top: 1.6rem; letter-spacing: .2px;
    }
    .hero-tagline { margin-top: .75rem; color: rgba(255,255,255,0.82); font-size: 1rem; max-width: 30ch; }

    .hero-features { margin-top: auto; padding-top: 2.25rem; display: grid; gap: 1rem; }
    .hero-feature { display: flex; align-items: center; gap: .85rem; font-size: .95rem; color: rgba(255,255,255,0.92); }
    .hero-feature .fi {
      width: 38px; height: 38px; flex: 0 0 38px; border-radius: 11px;
      display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
      background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.2);
    }
    .hero-foot { margin-top: 2rem; font-size: .8rem; color: rgba(255,255,255,0.6); }

    /* ---------------- RIGHT: Form panel ---------------- */
    .auth-panel { padding: 3.25rem 3rem; display: flex; flex-direction: column; justify-content: center; }

    .panel-brand { display: none; align-items: center; gap: .75rem; margin-bottom: 1.5rem; }
    .panel-brand .pb-logo {
      width: 46px; height: 46px; border-radius: 12px; overflow: hidden;
      background: linear-gradient(135deg, var(--primary), var(--primary-light));
      display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800;
    }
    .panel-brand .pb-logo img { width: 100%; height: 100%; object-fit: contain; }
    .panel-brand .pb-name { font-weight: 700; color: #1a1a1a; font-size: 1.05rem; }

    .auth-header { margin-bottom: 1.75rem; animation: fadeIn 0.6s ease-out 0.25s both; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .auth-title { font-size: 1.7rem; font-weight: 700; color: #1a1a1a; letter-spacing: -0.4px; }
    .auth-subtitle { color: #6c757d; font-size: .98rem; margin-top: .35rem; }

    .form-floating-custom { position: relative; margin-bottom: 1.25rem; animation: fadeIn 0.6s ease-out 0.35s both; }
    .form-floating-custom input {
      width: 100%; padding: 1.25rem 1rem 0.5rem 3rem;
      border: 2px solid #e6e8ee; border-radius: var(--input-radius);
      font-size: 1rem; transition: var(--transition); background: #f7f8fb; outline: none;
    }
    .form-floating-custom input:focus {
      border-color: var(--primary); background: #fff;
      box-shadow: 0 0 0 4px color-mix(in srgb, var(--primary) 12%, transparent);
    }
    .form-floating-custom input:focus + label,
    .form-floating-custom input:not(:placeholder-shown) + label {
      transform: translateY(-0.55rem) scale(0.82); color: var(--primary); font-weight: 600;
    }
    .form-floating-custom label {
      position: absolute; left: 3rem; top: 1rem; color: #6c757d; font-size: 1rem;
      pointer-events: none; transition: var(--transition); transform-origin: left center;
    }
    .form-floating-custom .input-icon {
      position: absolute; left: 1rem; top: 50%; transform: translateY(-50%);
      color: #9aa0ab; font-size: 1.2rem; transition: var(--transition); z-index: 1;
    }
    .form-floating-custom input:focus ~ .input-icon { color: var(--primary); transform: translateY(-50%) scale(1.1); }
    .form-floating-custom .toggle-password {
      position: absolute; right: .85rem; top: 50%; transform: translateY(-50%);
      background: none; border: none; color: #9aa0ab; font-size: 1.2rem; cursor: pointer;
      padding: 0.5rem; transition: var(--transition); z-index: 2;
    }
    .form-floating-custom .toggle-password:hover { color: var(--primary); transform: translateY(-50%) scale(1.1); }

    .form-options { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; animation: fadeIn 0.6s ease-out 0.45s both; }
    .form-check { display: flex; align-items: center; gap: 0.5rem; }
    .form-check-input { width: 1.2rem; height: 1.2rem; border: 2px solid #d0d4dd; border-radius: 6px; cursor: pointer; transition: var(--transition); }
    .form-check-input:checked { background-color: var(--primary); border-color: var(--primary); }
    .form-check-label { color: #495057; font-size: 0.9rem; cursor: pointer; user-select: none; }
    .forgot-link { color: var(--primary); text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: var(--transition); }
    .forgot-link:hover { color: var(--primary-dark); text-decoration: underline; }

    .btn-login {
      width: 100%; padding: 1rem; background: linear-gradient(135deg, var(--primary), var(--primary-light));
      color: #fff; border: none; border-radius: var(--input-radius); font-size: 1.05rem; font-weight: 600;
      cursor: pointer; transition: var(--transition);
      box-shadow: 0 10px 24px color-mix(in srgb, var(--primary) 30%, transparent);
      position: relative; overflow: hidden; animation: fadeIn 0.6s ease-out 0.55s both;
    }
    .btn-login::before {
      content: ''; position: absolute; top: 50%; left: 50%; width: 0; height: 0; border-radius: 50%;
      background: rgba(255, 255, 255, 0.3); transform: translate(-50%, -50%); transition: width 0.6s, height 0.6s;
    }
    .btn-login:hover { transform: translateY(-2px); box-shadow: 0 14px 30px color-mix(in srgb, var(--primary) 36%, transparent); }
    .btn-login:hover::before { width: 320px; height: 320px; }
    .btn-login:active { transform: translateY(0); }
    .btn-login:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
    .btn-login .spinner { display: none; width: 20px; height: 20px; border: 3px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .btn-login.loading .btn-text { display: none; }
    .btn-login.loading .spinner { display: block; }

    .alert-error {
      background: #fff0f0; border: 2px solid #ffcdd2; border-radius: var(--input-radius);
      padding: 0.85rem 1rem; margin-bottom: 1.25rem; color: #c62828;
      display: flex; align-items: center; gap: 0.75rem; animation: shake 0.5s, fadeIn 0.3s;
    }
    @keyframes shake { 0%,100%{transform:translateX(0)} 10%,30%,50%,70%,90%{transform:translateX(-5px)} 20%,40%,60%,80%{transform:translateX(5px)} }
    .alert-error i { font-size: 1.4rem; flex-shrink: 0; }

    .security-badge { margin-top: 1.75rem; padding-top: 1.4rem; border-top: 1px solid #eceef2; text-align: center; animation: fadeIn 0.6s ease-out 0.65s both; }
    .security-text { display: flex; align-items: center; justify-content: center; gap: 0.5rem; color: #6c757d; font-size: 0.85rem; }
    .security-text i { color: var(--primary); font-size: 1rem; }
    .copyright { color: #9aa0ab; font-size: 0.8rem; margin-top: 0.5rem; }

    /* ---------------- Responsive ---------------- */
    @media (max-width: 880px) {
      .auth-shell { grid-template-columns: 1fr; max-width: 460px; }
      .auth-hero { display: none; }
      .auth-panel { padding: 2.25rem 1.75rem; }
      .panel-brand { display: flex; }
    }
    @media (max-width: 768px) {
      input[type="text"], input[type="password"] { font-size: 16px !important; }
    }

    /* ---------------- Dark mode ---------------- */
    body.dark-mode {
      background:
        linear-gradient(135deg, rgba(2,6,23,0.90) 0%, rgba(15,23,42,0.86) 100%),
        url('assets/login-bg.webp') center center / cover no-repeat fixed;
    }
    body.dark-mode .auth-shell { background: #0f172a; box-shadow: 0 30px 80px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.08); }
    body.dark-mode .auth-panel { background: #0f172a; }
    body.dark-mode .auth-title, body.dark-mode .panel-brand .pb-name { color: #f1f5f9; }
    body.dark-mode .auth-subtitle { color: #cbd5e1; }
    body.dark-mode .form-floating-custom input { background: #1e293b; border-color: #334155; color: #f1f5f9; }
    body.dark-mode .form-floating-custom input:focus { background: #243449; border-color: var(--primary-light); }
    body.dark-mode .form-floating-custom label, body.dark-mode .form-floating-custom .input-icon { color: #94a3b8; }
    body.dark-mode .security-text { color: #94a3b8; }
    body.dark-mode .copyright { color: #64748b; }
    body.dark-mode .security-badge { border-top-color: #1f2a3a; }
  </style>
</head>
<body<?= $brand['dark_mode_enabled'] ? ' class="dark-mode"' : '' ?>>
  <div class="auth-shell">

    <!-- LEFT: Brand hero -->
    <aside class="auth-hero">
      <span class="ring r1"></span><span class="ring r2"></span><span class="ring r3"></span>
      <div class="hero-logo">
        <?php if (!empty($brand['logo_path']) && file_exists(__DIR__ . '/' . $brand['logo_path'])): ?>
          <img src="<?= htmlspecialchars($brand['logo_path'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($brand['system_name'], ENT_QUOTES, 'UTF-8') ?>">
        <?php else: ?>
          <?= htmlspecialchars($brand['system_name_short'], ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
      </div>
      <h2 class="hero-name"><?= htmlspecialchars($brand['system_name'], ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="hero-tagline">Integrated business management — properties, legal, finance &amp; operations in one place.</p>

      <div class="hero-features">
        <div class="hero-feature"><span class="fi"><i class="bi bi-building"></i></span> Real Estate &amp; Lease Management</div>
        <div class="hero-feature"><span class="fi"><i class="bi bi-bank2"></i></span> Legal, Collections &amp; Accounting</div>
        <div class="hero-feature"><span class="fi"><i class="bi bi-people"></i></span> HR, Operations &amp; Reporting</div>
      </div>

      <div class="hero-foot">© <?= date('Y') ?> <?= htmlspecialchars($brand['system_name'], ENT_QUOTES, 'UTF-8') ?>. All rights reserved.</div>
    </aside>

    <!-- RIGHT: Form panel -->
    <main class="auth-panel">
      <!-- Compact brand (mobile only) -->
      <div class="panel-brand">
        <div class="pb-logo">
          <?php if (!empty($brand['logo_path']) && file_exists(__DIR__ . '/' . $brand['logo_path'])): ?>
            <img src="<?= htmlspecialchars($brand['logo_path'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($brand['system_name'], ENT_QUOTES, 'UTF-8') ?>">
          <?php else: ?>
            <?= htmlspecialchars($brand['system_name_short'], ENT_QUOTES, 'UTF-8') ?>
          <?php endif; ?>
        </div>
        <span class="pb-name"><?= htmlspecialchars($brand['system_name'], ENT_QUOTES, 'UTF-8') ?></span>
      </div>

      <!-- Header -->
      <div class="auth-header">
        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-subtitle">Sign in to continue to <?= htmlspecialchars($brand['system_name'], ENT_QUOTES, 'UTF-8') ?></p>
      </div>

      <!-- Alerts -->
      <?php if ($info): ?>
        <div class="alert-error" role="status" style="background:#ecfdf5;border-color:#a7f3d0;color:#065f46">
          <i class="bi bi-check-circle-fill"></i>
          <span><?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert-error" role="alert">
          <i class="bi bi-exclamation-circle-fill"></i>
          <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      <?php endif; ?>

      <!-- Login Form -->
      <form method="post" action="login" id="loginForm" novalidate>
        <?php csrf_field(); ?>
        <?php
        // Store next URL in session for clean URLs
        if (isset($_GET['next'])) {
            $_SESSION['login_next'] = safe_next_redirect($_GET['next']);
        }
        $next = htmlspecialchars($_SESSION['login_next'] ?? 'index', ENT_QUOTES, 'UTF-8');
        ?>
        <input type="hidden" name="next" value="<?= $next ?>">

        <!-- Username Input -->
        <div class="form-floating-custom">
          <i class="input-icon bi bi-person"></i>
          <input type="text" name="username" id="username" placeholder=" " required autofocus autocomplete="username">
          <label for="username">Username</label>
        </div>

        <!-- Password Input -->
        <div class="form-floating-custom">
          <i class="input-icon bi bi-lock"></i>
          <input type="password" name="password" id="password" placeholder=" " required autocomplete="current-password">
          <label for="password">Password</label>
          <button type="button" class="toggle-password" id="togglePassword" tabindex="-1">
            <i class="bi bi-eye"></i>
          </button>
        </div>

        <!-- Options -->
        <div class="form-options">
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="remember" id="remember">
            <label class="form-check-label" for="remember">Remember me</label>
          </div>
          <a href="forgot_password" class="forgot-link">Forgot password?</a>
        </div>

        <!-- Login Button -->
        <button type="submit" class="btn-login" id="loginBtn">
          <span class="btn-text">Sign In</span>
          <div class="spinner"></div>
        </button>
      </form>

      <!-- Security Badge -->
      <div class="security-badge">
        <div class="security-text">
          <i class="bi bi-shield-check"></i>
          <span>Secured with end-to-end encryption</span>
        </div>
        <div class="copyright">© <?= date('Y') ?> <?= htmlspecialchars($brand['system_name'], ENT_QUOTES, 'UTF-8') ?>. All rights reserved.</div>
      </div>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Form handling
    const form = document.getElementById('loginForm');
    const btn = document.getElementById('loginBtn');
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    
    // Password toggle
    const togglePassword = document.getElementById('togglePassword');
    togglePassword.addEventListener('click', function() {
      const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
      passwordInput.setAttribute('type', type);
      this.querySelector('i').classList.toggle('bi-eye');
      this.querySelector('i').classList.toggle('bi-eye-slash');
    });
    
    // Form submission
    form.addEventListener('submit', function(e) {
      // Validate
      if (!usernameInput.value.trim() || !passwordInput.value) {
        e.preventDefault();
        
        if (!usernameInput.value.trim()) {
          usernameInput.focus();
          usernameInput.style.borderColor = '#c62828';
          setTimeout(() => usernameInput.style.borderColor = '', 2000);
        } else if (!passwordInput.value) {
          passwordInput.focus();
          passwordInput.style.borderColor = '#c62828';
          setTimeout(() => passwordInput.style.borderColor = '', 2000);
        }
        return;
      }
      
      // Loading state
      btn.classList.add('loading');
      btn.disabled = true;
    });
    
    // Auto-focus on empty field if error
    <?php if ($error): ?>
      if (!usernameInput.value.trim()) {
        usernameInput.focus();
      } else if (!passwordInput.value) {
        passwordInput.focus();
      }
    <?php endif; ?>
    
    // Floating label fix for autofill
    window.addEventListener('load', function() {
      [usernameInput, passwordInput].forEach(input => {
        if (input.value) {
          input.dispatchEvent(new Event('input'));
        }
      });
    });
    
    // Prevent double submission
    let submitted = false;
    form.addEventListener('submit', function(e) {
      if (submitted) {
        e.preventDefault();
        return false;
      }
      submitted = true;
    });
    
    // Update URL to clean format (remove .php and query parameters)
    if (window.history && window.history.replaceState) {
      const cleanUrl = window.location.pathname.replace(/\.php$/, '');
      if (window.location.search || window.location.pathname.endsWith('.php')) {
        window.history.replaceState({}, document.title, cleanUrl);
      }
    }
  </script>
</body>
</html>

