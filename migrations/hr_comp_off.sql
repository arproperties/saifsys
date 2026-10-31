-- Comp off: a day off earned by working on a holiday or an off day.
-- An approved credit adds to the employee's "Comp Off" leave balance (accrued);
-- taking it is an ordinary leave request of that type (taken).

INSERT INTO leave_types (name, code, is_paid, annual_quota_days, requires_attachment, carryover_limit_days, color_hex, created_at)
SELECT 'Comp Off', 'COMPOFF', 1, 0, 0, NULL, '#9333ea', NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE code = 'COMPOFF');

CREATE TABLE IF NOT EXISTS hr_comp_off_credits (
  id INT(11) NOT NULL AUTO_INCREMENT,
  employee_id INT(11) NOT NULL,
  work_date DATE NOT NULL,
  days DECIMAL(3,1) NOT NULL DEFAULT 1.0,
  eligible_as VARCHAR(20) NOT NULL,
  time_from TIME DEFAULT NULL,
  time_to TIME DEFAULT NULL,
  hours_worked DECIMAL(5,2) DEFAULT NULL,
  reason VARCHAR(500) DEFAULT NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  requester_id INT(11) DEFAULT NULL,
  approver_id INT(11) DEFAULT NULL,
  decided_at DATETIME DEFAULT NULL,
  company_id INT(11) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_coc_employee_date (employee_id, work_date),
  KEY idx_coc_status (status),
  CONSTRAINT fk_coc_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
