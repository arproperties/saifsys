<?php
/**
 * Administration Control Center layout footer.
 */
$appBase = function_exists('get_base_path') ? get_base_path() : '';
$adminAssetBase = $appBase . '/assets/admin';
?>
    </main>
    <div id="admin-unsaved-bar" class="admin-unsaved-bar" role="status">
      <span><strong>Unsaved changes</strong> — save before leaving this page.</span>
      <span class="small text-muted">Your edits are not stored until you click Save.</span>
    </div>
  </div><!-- .admin-main -->
</div><!-- .admin-shell -->

<div id="admin-drawer-backdrop" class="admin-drawer-backdrop" aria-hidden="true"></div>
<aside id="admin-drawer" class="admin-drawer" aria-hidden="true" role="dialog" aria-labelledby="admin-drawer-title">
  <div class="admin-drawer-header">
    <div>
      <div class="small text-muted text-uppercase fw-semibold" style="letter-spacing:.06em;font-size:.65rem">Details</div>
      <h2 class="h5 mb-0" id="admin-drawer-title">Activity</h2>
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-admin-drawer-close aria-label="Close details">
      <i data-lucide="x" style="width:16px;height:16px"></i>
    </button>
  </div>
  <div class="admin-drawer-body" id="admin-drawer-body">
    <!-- Filled by Audit History JS -->
  </div>
</aside>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/lucide@0.469.0/dist/umd/lucide.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="<?= htmlspecialchars($adminAssetBase, ENT_QUOTES, 'UTF-8') ?>/admin-ui-v2.js?v=20260716-1"></script>
