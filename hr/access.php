<?php

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/audit_bridge.php';
require_once __DIR__.'/../includes/rbac_department.php';
require_once __DIR__.'/../includes/module_access.php';
require_role(['Owner','Admin'], $conn);



// Handle form post
$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $roles   = isset($_POST['roles']) && is_array($_POST['roles']) ? $_POST['roles'] : [];

    if ($user_id > 0) {
        $conn->beginTransaction();
        try {
            // Clear existing
            $stmt = $conn->prepare("DELETE FROM user_roles WHERE user_id=?");
            $stmt->execute([$user_id]);

            if ($roles) {
                // Map role names -> ids
                $in  = str_repeat('?,', count($roles)-1) . '?';
                $stm = $conn->prepare("SELECT id, name FROM roles WHERE name IN ($in)");
                $stm->execute($roles);
                $map = $stm->fetchAll(PDO::FETCH_KEY_PAIR); // id=>name? no, we want name=>id
                // Build name=>id
                $nameToId = [];
                foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $_) {
                    $nameToId[$_['name']] = (int)$_['id'];
                }
                // fetchAll used twice would be empty; fix: re-run
                $stm = $conn->prepare("SELECT id, name FROM roles WHERE name IN ($in)");
                $stm->execute($roles);
                $nameToId = [];
                while ($r = $stm->fetch(PDO::FETCH_ASSOC)) {
                    $nameToId[$r['name']] = (int)$r['id'];
                }

                $ins = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                foreach ($roles as $rn) {
                    if (isset($nameToId[$rn])) $ins->execute([$user_id, $nameToId[$rn]]);
                }
            }
            $conn->commit();
            $msg = 'Roles updated.';
            $uidActor = $_SESSION['user']['id'] ?? null;
            $targetName = '';
            try {
                $uSt = $conn->prepare("SELECT COALESCE(NULLIF(fullname,''), username) FROM `user` WHERE id=? LIMIT 1");
                $uSt->execute([$user_id]);
                $targetName = (string)($uSt->fetchColumn() ?: ('#' . $user_id));
            } catch (Throwable $e) {
                $targetName = '#' . $user_id;
            }
            audit_bridge_hr_ops(
                'hr_roles_updated',
                'user_roles',
                $user_id,
                'Updated HR roles for ' . $targetName
                    . ' → ' . (!empty($roles) ? implode(', ', $roles) : '(none)'),
                function_exists('current_company_id') ? (current_company_id($conn) ?: null) : null,
                [
                    'user_id' => $user_id,
                    'roles' => array_values($roles),
                ],
                'User #' . $user_id . ' — ' . $targetName,
                $uidActor ? (int)$uidActor : null
            );
        } catch (Exception $e) {
            $conn->rollBack();
            $msg = 'Error: '.$e->getMessage();
        }
    } else {
        $msg = 'Please select a user.';
    }
}

// Data for form
$users = $conn->query("SELECT id, username, COALESCE(fullname, username) AS label FROM `user` ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$all_roles = $conn->query("SELECT name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Which user is in focus?
$selected_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if (!$selected_user_id && $users) $selected_user_id = (int)$users[0]['id'];

// Current roles for selected user
$current_roles = [];
if ($selected_user_id) {
    $stmt = $conn->prepare("
        SELECT r.name
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
        ORDER BY r.name
    ");
    $stmt->execute([$selected_user_id]);
    $current_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get user's effective departments (grouped by module)
$user_departments = [];
if ($selected_user_id) {
    $user_departments = get_user_departments($selected_user_id, $conn);
}

// Module display names
$module_names = [
    MODULE_CLEANING => 'Cleaning',
    MODULE_REALESTATE => 'Real Estate'
];

// Department display names
$dept_names = [
    DEPT_CLEANING_OPERATIONS => 'Operations',
    DEPT_CLEANING_ACCOUNTS => 'Accounts',
    DEPT_REALESTATE_CORE => 'Core Management',
    DEPT_REALESTATE_FINANCIAL => 'Financial',
    DEPT_REALESTATE_MAINTENANCE => 'Maintenance',
    DEPT_REALESTATE_OPERATIONS => 'Operations',
    DEPT_REALESTATE_COMPLIANCE => 'Compliance & Reports',
    DEPT_HR => 'HR (Shared)'
];

// Page settings for shared layout
$pageTitle = 'Access';
$pageStyles = '
    .role-chip{display:inline-block;border:1px solid #dee2e6;border-radius:999px;padding:.25rem .6rem;margin:.15rem;background:#fff}
';
?>
<?php require_once __DIR__ . '/includes/hr_layout_header.php'; ?>

<?php
echo hr_ui_page_header(
    'Access Control',
    'Assign roles to users and review department access.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Access'],
    ],
    '<a href="../../operation.php" class="btn btn-outline-secondary btn-sm">Back</a>'
);
?>

  <?php if($msg): ?>
    <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <div class="hr-settings-card shadow-sm">
    <div class="settings-header">User roles</div>
    <div class="card-body">
      <div class="hr-filter-bar mb-3">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-6">
          <label class="form-label">User</label>
          <select name="user_id" class="form-select" onchange="this.form.submit()">
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= $selected_user_id==$u['id']?'selected':'' ?>>
                <?= htmlspecialchars($u['label']).' ('.htmlspecialchars($u['username']).')' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 text-muted">
          Current roles:
          <?php if($current_roles): foreach($current_roles as $cr): ?>
            <span class="role-chip"><?= htmlspecialchars($cr) ?></span>
          <?php endforeach; else: ?>
            <em>None</em>
          <?php endif; ?>
        </div>
      </form>
      </div>

      <form method="post" class="mt-3">
        <?php csrf_field(); ?>
        <input type="hidden" name="user_id" value="<?= (int)$selected_user_id ?>">
        <div class="row g-3">
          <?php foreach ($all_roles as $r): ?>
            <div class="col-sm-6 col-md-4 col-lg-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="roles[]" value="<?= htmlspecialchars($r) ?>"
                       id="r-<?= md5($r) ?>" <?= in_array($r, $current_roles, true)?'checked':'' ?>>
                <label class="form-check-label" for="r-<?= md5($r) ?>"><?= htmlspecialchars($r) ?></label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-3">
          <button class="btn btn-primary">Save Roles</button>
        </div>
      </form>
    </div>
  </div>

  <div class="text-muted small mt-3">
    Tip: use <code>require_role(['Owner','Admin'])</code> at the top of files you want to protect.
  </div>

  <?php if ($selected_user_id): ?>
    <div class="hr-settings-card mt-4">
      <div class="settings-header">
        <span><i class="bi bi-shield-check"></i> Department Access</span>
        <small class="text-muted d-block fw-normal mt-1">Departments this user can access through their assigned roles</small>
      </div>
      <div class="card-body">
        <?php
        // Check if user is Owner or Admin (they have all departments)
        $isOwnerOrAdmin = false;
        foreach ($current_roles as $role) {
            if ($role === 'Owner' || $role === 'Admin') {
                $isOwnerOrAdmin = true;
                break;
            }
        }
        
        if ($isOwnerOrAdmin):
        ?>
          <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> 
            <strong>Owner/Admin Role:</strong> This user has access to all departments across all modules.
          </div>
        <?php elseif (!empty($user_departments)): ?>
          <?php foreach ($user_departments as $module => $departments): ?>
            <?php if (!empty($departments)): ?>
              <div class="mb-4">
                <h6 class="text-primary mb-3">
                  <i class="bi bi-folder"></i> <?= htmlspecialchars($module_names[$module] ?? ucfirst($module)) ?>
                  <span class="badge bg-secondary"><?= count($departments) ?> department(s)</span>
                </h6>
                <div class="row g-2">
                  <?php foreach ($departments as $dept): ?>
                    <div class="col-md-6 col-lg-4">
                      <div class="card border mb-2">
                        <div class="card-body p-3">
                          <div class="d-flex align-items-center">
                            <i class="bi bi-check-circle-fill text-success me-2" style="font-size: 1.2rem;"></i>
                            <div>
                              <strong><?= htmlspecialchars($dept_names[$dept] ?? ucfirst(str_replace('_', ' ', $dept))) ?></strong>
                              <br><small class="text-muted"><?= htmlspecialchars($dept) ?></small>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i> 
            This user has no department access assigned. They can only access features based on their roles.
          </div>
        <?php endif; ?>
        
        <div class="mt-3 pt-3 border-top">
          <small class="text-muted">
            <i class="bi bi-info-circle"></i> 
            To manage department access, go to <a href="../settings.php?tab=departments" target="_blank">Settings → Departments</a>
          </small>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
