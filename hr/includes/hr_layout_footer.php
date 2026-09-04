<?php
/**
 * HR Layout Footer — closes shell and loads scripts.
 */
$appBase = function_exists('get_application_web_root')
    ? get_application_web_root()
    : (function_exists('get_base_path') ? get_base_path() : '');
$hrAssetBase = ($appBase !== '' ? $appBase : '') . '/assets/hr';
?>
    </main>
    <div id="hr-unsaved-bar" class="hr-unsaved-bar" role="status">
      <span><strong>Unsaved changes</strong> — save before leaving this page.</span>
      <span class="small text-muted">Your edits are not stored until you click Save.</span>
    </div>
  </div><!-- .hr-main -->
</div><!-- .hr-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/lucide@0.469.0/dist/umd/lucide.min.js"></script>
<script src="<?= htmlspecialchars($hrAssetBase, ENT_QUOTES, 'UTF-8') ?>/hr-ui-v2.js?v=20260716-2"></script>
<?php if (!empty($pageScripts)) {
    echo $pageScripts;
} ?>
</body>
</html>
