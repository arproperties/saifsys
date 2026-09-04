<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_bank_reconciliation.php';
require_once __DIR__ . '/includes/construction_bank_reco_rules.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
co_bank_reco_require_permission($conn, 'construction.bank_reconciliation.rules');

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$csrf = csrf_token();
$tablesReady = co_bank_reco_tables_ready($conn) && co_bank_rules_table_ready($conn);

$banks = [];
$coaAccounts = [];
$projects = [];
$contacts = ['clients' => [], 'suppliers' => [], 'contractors' => []];
$rules = [];
$prefill = null;

if ($tablesReady) {
    $st = $conn->prepare("
        SELECT b.id, b.account_name AS name, coa.account_code AS account_no
        FROM re_bank_accounts b
        JOIN re_chart_of_accounts coa ON coa.id = b.gl_account_id AND coa.company_id = b.company_id
        WHERE b.company_id = ? AND b.is_active = 1 ORDER BY coa.account_code
    ");
    $st->execute([$cid]);
    $banks = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $st = $conn->prepare('SELECT id, account_code, account_name FROM re_chart_of_accounts WHERE company_id = ? AND is_active = 1 ORDER BY account_code');
    $st->execute([$cid]);
    $coaAccounts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (co_db_table_exists($conn, 'co_projects')) {
        $st = $conn->prepare('SELECT id, project_code, project_name FROM co_projects WHERE company_id = ? ORDER BY project_code');
        $st->execute([$cid]);
        $projects = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $contacts = co_bank_reco_contacts($conn, $cid);
    $rules = co_bank_rules_list($conn, $cid);

    $lineId = (int) ($_GET['line_id'] ?? 0);
    if ($lineId > 0) {
        $line = co_bank_get_statement_line($conn, $lineId, $cid);
        if ($line) {
            $prefill = co_bank_rule_from_line($conn, $cid, $line, (int) $line['bank_account_id']);
            $prefill['source_line_id'] = $lineId;
        }
    }
}

$pageTitle = 'Bank Rules';
$pageHead = '<link href="assets/bank_reconciliation.css?v=20260712-contrast" rel="stylesheet">';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="co-breco-hero">
  <div class="d-flex flex-wrap align-items-start gap-3">
    <div class="flex-grow-1">
      <div class="text-uppercase small text-muted">Construction · Bank Reconciliation</div>
      <h3 class="mb-1">Bank rules</h3>
      <div class="text-muted small">Auto-suggest coding for statement lines that match description, reference, or amount conditions.</div>
    </div>
    <div class="d-flex gap-2">
      <a href="bank_reconciliation.php" class="btn btn-outline-primary btn-sm">Reconcile</a>
      <button type="button" class="btn btn-primary btn-sm" id="btnNewRule" <?= $tablesReady ? '' : 'disabled' ?>>New rule</button>
    </div>
  </div>
</div>

<?php if (!$tablesReady): ?>
  <div class="alert alert-warning">Run <code>migrations/construction_bank_reconciliation_v2.sql</code> to enable bank rules.</div>
<?php else: ?>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead class="table-light">
        <tr><th>Priority</th><th>Name</th><th>Direction</th><th>Bank</th><th>Conditions</th><th>Action</th><th>Active</th><th></th></tr>
      </thead>
      <tbody id="rulesTableBody">
        <?php if (!$rules): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No rules yet. Create one from a statement line on the Reconcile screen or click New rule.</td></tr>
        <?php else: ?>
          <?php foreach ($rules as $r): ?>
            <?php
              $c = $r['conditions'];
              $a = $r['action'];
              $condParts = [];
              if (!empty($c['description_contains'])) $condParts[] = 'Desc contains "' . $c['description_contains'] . '"';
              if (!empty($c['reference_contains'])) $condParts[] = 'Ref contains "' . $c['reference_contains'] . '"';
              if (!empty($c['amount_equals'])) $condParts[] = 'Amount = ' . number_format((float)$c['amount_equals'], 2);
            ?>
            <tr>
              <td><?= (int) $r['priority'] ?></td>
              <td class="fw-semibold"><?= h($r['rule_name']) ?></td>
              <td><?= h(ucfirst($r['direction'])) ?></td>
              <td class="small"><?php
                if (empty($r['bank_account_id'])) { echo 'All banks'; }
                else {
                  foreach ($banks as $b) { if ((int)$b['id'] === (int)$r['bank_account_id']) { echo h($b['account_no'].' — '.$b['name']); break; } }
                }
              ?></td>
              <td class="small"><?= h(implode(' · ', $condParts) ?: '—') ?></td>
              <td class="small"><?= h($a['transaction_type'] ?? '—') ?><?= !empty($a['account_id']) ? ' · Acct #'.(int)$a['account_id'] : '' ?></td>
              <td><?= (int)$r['is_active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
              <td class="text-end">
                <button type="button" class="btn btn-outline-primary btn-sm btn-edit-rule" data-rule-id="<?= (int)$r['id'] ?>">Edit</button>
                <button type="button" class="btn btn-outline-danger btn-sm btn-del-rule" data-id="<?= (int)$r['id'] ?>">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="ruleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="ruleForm">
      <div class="modal-header">
        <h5 class="modal-title" id="ruleModalTitle">Bank rule</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-2">
        <input type="hidden" name="id" id="f_id" value="0">
        <div class="col-md-8"><label class="form-label small">Rule name</label><input type="text" name="rule_name" id="f_name" class="form-control form-control-sm" required></div>
        <div class="col-md-4"><label class="form-label small">Priority (lower = first)</label><input type="number" name="priority" id="f_priority" class="form-control form-control-sm" value="100"></div>
        <div class="col-md-4"><label class="form-label small">Direction</label>
          <select name="direction" id="f_direction" class="form-select form-select-sm">
            <option value="spent">Spent (money out)</option>
            <option value="received">Received (money in)</option>
            <option value="both">Both</option>
          </select>
        </div>
        <div class="col-md-8"><label class="form-label small">Bank account</label>
          <select name="bank_account_id" id="f_bank" class="form-select form-select-sm">
            <option value="">All banks</option>
            <?php foreach ($banks as $b): ?>
              <option value="<?= (int)$b['id'] ?>"><?= h($b['account_no'].' — '.$b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><hr class="my-1"><div class="small fw-semibold">Conditions</div></div>
        <div class="col-md-6"><label class="form-label small">Description contains</label><input type="text" name="description_contains" id="f_desc_contains" class="form-control form-control-sm"></div>
        <div class="col-md-6"><label class="form-label small">Reference contains</label><input type="text" name="reference_contains" id="f_ref_contains" class="form-control form-control-sm"></div>
        <div class="col-md-4"><label class="form-label small">Amount equals</label><input type="text" name="amount_equals" id="f_amt_eq" class="form-control form-control-sm"></div>
        <div class="col-md-4"><label class="form-label small">Amount min</label><input type="text" name="amount_min" id="f_amt_min" class="form-control form-control-sm"></div>
        <div class="col-md-4"><label class="form-label small">Amount max</label><input type="text" name="amount_max" id="f_amt_max" class="form-control form-control-sm"></div>
        <div class="col-12"><hr class="my-1"><div class="small fw-semibold">Action (Who / What / Why pre-fill)</div></div>
        <div class="col-md-4"><label class="form-label small">Transaction type</label>
          <select name="action_transaction_type" id="f_action_type" class="form-select form-select-sm">
            <option value="quick_expense">Quick expense</option>
            <option value="bank_charge">Bank charge</option>
            <option value="client_receipt">Client receipt</option>
            <option value="direct_project_expense">Direct project expense</option>
            <option value="cash_withdrawal">Cash withdrawal</option>
          </select>
        </div>
        <div class="col-md-4"><label class="form-label small">Contact type</label>
          <select name="action_contact_type" id="f_contact_type" class="form-select form-select-sm">
            <option value="">— None —</option>
            <option value="client">Client</option>
            <option value="supplier">Supplier</option>
            <option value="contractor">Contractor</option>
          </select>
        </div>
        <div class="col-md-4"><label class="form-label small">Contact</label><select name="action_contact_id" id="f_contact_id" class="form-select form-select-sm"><option value="">— Select —</option></select></div>
        <div class="col-md-6"><label class="form-label small">Account (What)</label>
          <select name="action_account_id" id="f_account" class="form-select form-select-sm"><option value="">— Select —</option>
            <?php foreach ($coaAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['account_code'].' — '.$a['account_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label small">Project</label>
          <select name="action_project_id" id="f_project" class="form-select form-select-sm"><option value="">— Optional —</option>
            <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['project_code'].' — '.$p['project_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><label class="form-label small">Description template (Why)</label><input type="text" name="description_template" id="f_desc_tpl" class="form-control form-control-sm" value="{description}"></div>
        <div class="col-md-4"><label class="form-check small mt-4"><input type="checkbox" name="is_active" id="f_active" class="form-check-input" checked> Active</label></div>
        <div class="col-md-4"><label class="form-check small mt-4"><input type="checkbox" name="auto_suggest" id="f_auto_suggest" class="form-check-input" checked> Auto-suggest on reconcile</label></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save rule</button>
      </div>
    </form>
  </div>
</div>

<?php
$pageScripts = '<script>
window.CO_BRECO_CONTACTS = ' . json_encode($contacts) . ';
window.CO_BANK_RULES = ' . json_encode($rules) . ';
window.CO_RULE_PREFILL = ' . json_encode($prefill) . ';
(function(){
  function initBankRulesPage(){
    if (typeof bootstrap === "undefined") {
      console.error("Bootstrap JS not loaded — bank rules modal unavailable.");
      return;
    }
    const CSRF = ' . json_encode($csrf) . ';
    const rulesById = {};
    (window.CO_BANK_RULES || []).forEach(function(r){ rulesById[String(r.id)] = r; });
    const modalEl = document.getElementById("ruleModal");
    if (!modalEl) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const contacts = window.CO_BRECO_CONTACTS || {clients:[],suppliers:[],contractors:[]};

    function fillContacts(type, selected){
      const sel = document.getElementById("f_contact_id");
      if (!sel) return;
      sel.innerHTML = "<option value=\"\">— Select —</option>";
      const map = {client:"clients",supplier:"suppliers",contractor:"contractors"};
      (contacts[map[type]] || []).forEach(function(c){
        const opt = document.createElement("option");
        opt.value = c.id; opt.textContent = c.name;
        if (String(selected) === String(c.id)) opt.selected = true;
        sel.appendChild(opt);
      });
    }

    function openForm(data){
      data = data || {};
      document.getElementById("ruleModalTitle").textContent = data.id ? "Edit rule" : "New rule";
      document.getElementById("f_id").value = data.id || 0;
      document.getElementById("f_name").value = data.rule_name || "";
      document.getElementById("f_priority").value = data.priority || 100;
      document.getElementById("f_direction").value = data.direction || "spent";
      document.getElementById("f_bank").value = data.bank_account_id || "";
      const c = data.conditions || {};
      document.getElementById("f_desc_contains").value = c.description_contains || "";
      document.getElementById("f_ref_contains").value = c.reference_contains || "";
      document.getElementById("f_amt_eq").value = c.amount_equals != null ? c.amount_equals : "";
      document.getElementById("f_amt_min").value = c.amount_min != null ? c.amount_min : "";
      document.getElementById("f_amt_max").value = c.amount_max != null ? c.amount_max : "";
      const a = data.action || {};
      document.getElementById("f_action_type").value = a.transaction_type || "quick_expense";
      document.getElementById("f_contact_type").value = a.contact_type || "";
      fillContacts(a.contact_type || "", a.contact_id || "");
      document.getElementById("f_account").value = a.account_id || "";
      document.getElementById("f_project").value = a.project_id || "";
      document.getElementById("f_desc_tpl").value = a.description_template || "{description}";
      document.getElementById("f_active").checked = data.is_active !== 0 && data.is_active !== false;
      document.getElementById("f_auto_suggest").checked = data.auto_suggest !== 0 && data.auto_suggest !== false;
      modal.show();
    }

    document.getElementById("f_contact_type").addEventListener("change", function(){
      fillContacts(this.value, "");
    });
    document.getElementById("btnNewRule").addEventListener("click", function(){ openForm({}); });
    document.querySelectorAll(".btn-edit-rule").forEach(function(btn){
      btn.addEventListener("click", function(){
        const rule = rulesById[String(btn.getAttribute("data-rule-id"))];
        if (rule) openForm(rule);
      });
    });
    document.querySelectorAll(".btn-del-rule").forEach(function(btn){
      btn.addEventListener("click", async function(){
        if (!confirm("Delete this rule?")) return;
        const p = new URLSearchParams({_csrf: CSRF, rule_id: btn.getAttribute("data-id")});
        const r = await fetch("ajax/bank_reco_rules_delete.php", {method:"POST", body:p});
        const j = await r.json();
        if (!j.success) { alert(j.error || "Delete failed"); return; }
        location.reload();
      });
    });
    document.getElementById("ruleForm").addEventListener("submit", async function(ev){
      ev.preventDefault();
      const fd = new FormData(ev.target);
      fd.append("_csrf", CSRF);
      const r = await fetch("ajax/bank_reco_rules_save.php", {method:"POST", body:fd});
      const j = await r.json();
      if (!j.success) { alert(j.error || "Save failed"); return; }
      location.reload();
    });
    if (window.CO_RULE_PREFILL) {
      openForm(window.CO_RULE_PREFILL);
    }
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initBankRulesPage);
  } else {
    initBankRulesPage();
  }
})();
</script>';
?>

<?php endif; ?>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
