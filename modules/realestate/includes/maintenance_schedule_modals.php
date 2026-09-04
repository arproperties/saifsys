<?php
/**
 * Maintenance Schedule modals (Add/Edit work order + View/Status).
 * Included by maintenance_schedule.php. Expects: $buildings, $empGroups,
 * $openRequests, $taskTypes, $priorities, $statuses, $returnQs, $canOverride.
 */
if (!isset($buildings)) { return; }
?>
<!-- Add / Edit Work Order Modal -->
<div class="modal fade" id="msModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST" id="msForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="save_schedule">
        <input type="hidden" name="schedule_id" id="ms_schedule_id" value="">
        <input type="hidden" name="maintenance_request_id" id="ms_maintenance_request_id" value="">
        <input type="hidden" name="override_conflict" id="ms_override_conflict" value="">
        <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="ms_form_title">Add Work Order</h5>
          <span class="badge bg-secondary ms-2" id="ms_wo_badge" style="display:none"></span>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-light border py-2 px-3 small mb-3">
            <label class="form-label small mb-1 fw-semibold"><i class="bi bi-link-45deg"></i> Schedule from a maintenance request (optional)</label>
            <select class="form-select form-select-sm" id="ms_request_picker" onchange="msApplyRequest()">
              <option value="">-- Pick an open request to auto-fill --</option>
              <?php foreach ($openRequests as $r): ?>
              <option value="<?= $r['id'] ?>"
                      data-building="<?= (int)$r['building_id'] ?>"
                      data-unit="<?= (int)($r['unit_id'] ?? 0) ?>"
                      data-common-area="<?= (int)($r['common_area_id'] ?? 0) ?>"
                      data-location-type="<?= h($r['location_type'] ?? 'unit') ?>"
                      data-tasktype="<?= h(re_ms_map_category_to_task_type($r['category'])) ?>"
                      data-description="<?= h($r['description']) ?>">
                #<?= $r['id'] ?> · <?= h($r['location_label'] ?? ($r['building_name'] . ' ' . $r['unit_number'])) ?> · <?= h($r['category'] ?: 'general') ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Location type</label>
              <select name="location_type" id="ms_location_type" class="form-select">
                <option value="unit">Unit</option>
                <option value="common_area">Common Area</option>
                <option value="building" id="ms_loc_building_opt" style="display:none">Building (legacy — reassign)</option>
              </select>
              <div class="form-text" id="ms_location_hint" style="display:none">Legacy building-only WO — assign a Unit or Common Area when ready.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Building</label>
              <select name="building_id" id="ms_building_id" class="form-select">
                <option value="">-- Select building --</option>
                <?php foreach ($buildings as $b): ?>
                <option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4" id="ms_unit_wrap">
              <label class="form-label">Unit</label>
              <select name="unit_id" id="ms_unit_id" class="form-select">
                <option value="">-- Select unit --</option>
              </select>
            </div>
            <div class="col-md-4" id="ms_common_area_wrap" style="display:none">
              <label class="form-label">Common Area</label>
              <select name="common_area_id" id="ms_common_area_id" class="form-select">
                <option value="">-- Select common area --</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Task type</label>
              <select name="task_type" id="ms_task_type" class="form-select">
                <?php foreach ($taskTypes as $k => $v): ?>
                <option value="<?= $k ?>"><?= h($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Priority</label>
              <select name="priority" id="ms_priority" class="form-select">
                <?php foreach ($priorities as $k => $v): ?>
                <option value="<?= $k ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Title</label>
              <input type="text" name="title" id="ms_title" class="form-control" placeholder="Short title (e.g. AC not cooling - Unit 504)">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" id="ms_description" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">Date *</label>
              <input type="date" name="schedule_date" id="ms_schedule_date" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Start time *</label>
              <input type="time" name="start_time" id="ms_start_time" class="form-control" value="09:00" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">End time *</label>
              <input type="time" name="end_time" id="ms_end_time" class="form-control" value="10:00" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="status" id="ms_status" class="form-select">
                <?php foreach ($statuses as $k => $v): ?>
                <option value="<?= $k ?>" <?= $k === 'scheduled' ? 'selected' : '' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Notes</label>
              <input type="text" name="notes" id="ms_notes" class="form-control">
            </div>

            <div class="col-12">
              <label class="form-label mb-1">Assigned team <span class="text-muted small">(select one or more — no limit)</span></label>
              <div class="border rounded p-2" id="ms_team_box">
                <?php
                $groupLabels = re_ms_team_roles();
                foreach (['engineer','supervisor','technician','helper','other'] as $grp):
                    if (empty($empGroups[$grp])) continue; ?>
                <div class="mb-2">
                  <div class="fw-semibold small text-uppercase text-muted"><?= h($groupLabels[$grp]) ?>s</div>
                  <div class="row g-1">
                    <?php foreach ($empGroups[$grp] as $e): ?>
                    <div class="col-md-6">
                      <div class="d-flex align-items-center gap-2">
                        <div class="form-check mb-0 flex-grow-1">
                          <input class="form-check-input ms-emp-check" type="checkbox" name="employee_ids[]" value="<?= $e['id'] ?>" id="ms_emp_<?= $e['id'] ?>">
                          <label class="form-check-label" for="ms_emp_<?= $e['id'] ?>"><?= h($e['display_name']) ?><?php if (!empty($e['position_title'])): ?> <span class="text-muted small">· <?= h($e['position_title']) ?></span><?php endif; ?><?php if (!empty($e['is_other_company']) && !empty($e['company_name'])): ?> <span class="badge bg-light text-secondary border" style="font-weight:500;">@ <?= h($e['company_name']) ?></span><?php endif; ?></label>
                        </div>
                        <select name="employee_roles[<?= $e['id'] ?>]" class="form-select form-select-sm ms-role-select" style="width:108px" title="Role on this job">
                          <?php foreach ($groupLabels as $rk => $rv): ?>
                          <option value="<?= $rk ?>" <?= ($e['team_role'] === $rk) ? 'selected' : '' ?>><?= h($rv) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer justify-content-between">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="notify_team" id="ms_notify_team" value="1" checked>
            <label class="form-check-label small" for="ms_notify_team"><i class="bi bi-envelope"></i> Email the assigned team</label>
          </div>
          <div>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Work Order</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View / Status Modal -->
<div class="modal fade" id="msViewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="msv_title">Work Order</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <table class="table table-sm table-borderless mb-2">
          <tr><th width="38%">Task type</th><td id="msv_type">-</td></tr>
          <tr><th>Priority</th><td id="msv_priority">-</td></tr>
          <tr><th>Status</th><td id="msv_status">-</td></tr>
          <tr><th>Location</th><td id="msv_location">-</td></tr>
          <tr><th>Request</th><td><span id="msv_request">-</span> <a id="msv_req_link" href="#" class="ms-1" style="display:none"><i class="bi bi-box-arrow-up-right"></i></a></td></tr>
          <tr><th>Date</th><td id="msv_date">-</td></tr>
          <tr><th>Time</th><td id="msv_time">-</td></tr>
          <tr><th>Team</th><td id="msv_team">-</td></tr>
          <tr><th>Description</th><td id="msv_desc">-</td></tr>
          <tr><th>Notes</th><td id="msv_notes">-</td></tr>
        </table>

        <form method="POST" class="d-flex gap-2 align-items-end border-top pt-3">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="schedule_id" id="msv_status_sched_id">
          <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
          <div class="flex-grow-1">
            <label class="form-label small mb-1">Update status</label>
            <select name="status" id="msv_status_select" class="form-select form-select-sm">
              <?php foreach ($statuses as $k => $v): ?>
              <option value="<?= $k ?>"><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-sm btn-success">Update</button>
        </form>
      </div>
      <div class="modal-footer justify-content-between">
        <?php if ($canOverride): ?>
        <form method="POST" onsubmit="return confirm('Delete this work order?');">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="delete_schedule">
          <input type="hidden" name="schedule_id" id="msv_delete_sched_id">
          <input type="hidden" name="return_qs" value="<?= h($returnQs) ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete</button>
        </form>
        <?php else: ?><span></span><?php endif; ?>
        <button type="button" class="btn btn-sm btn-primary" id="msv_edit_btn" data-id="" onclick="msEditFromView()"><i class="bi bi-pencil"></i> Edit</button>
      </div>
    </div>
  </div>
</div>
