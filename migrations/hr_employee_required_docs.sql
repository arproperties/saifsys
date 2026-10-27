-- Required documents check (HR > Employee > Overview).
-- An employee should hold at least one Passport, Visa, LaborCard, Contract and
-- National ID in employee_documents. When one is missing, HR notes why here.
-- A note stays after the document is uploaded, but is no longer shown.
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS `employee_required_doc_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `doc_type` varchar(50) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erdn_emp_type` (`employee_id`, `doc_type`),
  CONSTRAINT `fk_erdn_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
