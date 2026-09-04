--
-- Structure for view `v_ar_ageing`
--
DROP TABLE IF EXISTS `v_ar_ageing`;

DROP VIEW IF EXISTS `v_ar_ageing`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_ageing`  AS SELECT sum(case when `v`.`days_overdue` <= 0 then `v`.`balance_due` else 0 end) AS `bucket_0`, sum(case when `v`.`days_overdue` between 1 and 30 then `v`.`balance_due` else 0 end) AS `bucket_30`, sum(case when `v`.`days_overdue` between 31 and 60 then `v`.`balance_due` else 0 end) AS `bucket_60`, sum(case when `v`.`days_overdue` between 61 and 90 then `v`.`balance_due` else 0 end) AS `bucket_90`, sum(case when `v`.`days_overdue` > 90 then `v`.`balance_due` else 0 end) AS `bucket_120` FROM `v_ar_invoices_open` AS `v` ;

-- --------------------------------------------------------

--
-- Structure for view `v_ar_ageing_by_client`
--
DROP TABLE IF EXISTS `v_ar_ageing_by_client`;

DROP VIEW IF EXISTS `v_ar_ageing_by_client`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_ageing_by_client`  AS SELECT `v`.`client_id` AS `client_id`, sum(case when `v`.`days_overdue` <= 0 then `v`.`balance_due` else 0 end) AS `bucket_0`, sum(case when `v`.`days_overdue` between 1 and 30 then `v`.`balance_due` else 0 end) AS `bucket_30`, sum(case when `v`.`days_overdue` between 31 and 60 then `v`.`balance_due` else 0 end) AS `bucket_60`, sum(case when `v`.`days_overdue` between 61 and 90 then `v`.`balance_due` else 0 end) AS `bucket_90`, sum(case when `v`.`days_overdue` > 90 then `v`.`balance_due` else 0 end) AS `bucket_120`, sum(`v`.`balance_due`) AS `total_due` FROM `v_ar_invoices_open` AS `v` GROUP BY `v`.`client_id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_ar_ageing_optimized`
--
DROP TABLE IF EXISTS `v_ar_ageing_optimized`;

DROP VIEW IF EXISTS `v_ar_ageing_optimized`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_ageing_optimized`  AS SELECT coalesce(sum(case when `i`.`due_date` is null or `i`.`due_date` >= curdate() then `i`.`total` - coalesce(`i`.`amount_paid`,0) else 0 end),0) AS `bucket_0`, coalesce(sum(case when `i`.`due_date` < curdate() and to_days(curdate()) - to_days(`i`.`due_date`) between 1 and 30 then `i`.`total` - coalesce(`i`.`amount_paid`,0) else 0 end),0) AS `bucket_30`, coalesce(sum(case when `i`.`due_date` < curdate() and to_days(curdate()) - to_days(`i`.`due_date`) between 31 and 60 then `i`.`total` - coalesce(`i`.`amount_paid`,0) else 0 end),0) AS `bucket_60`, coalesce(sum(case when `i`.`due_date` < curdate() and to_days(curdate()) - to_days(`i`.`due_date`) between 61 and 90 then `i`.`total` - coalesce(`i`.`amount_paid`,0) else 0 end),0) AS `bucket_90`, coalesce(sum(case when `i`.`due_date` < curdate() and to_days(curdate()) - to_days(`i`.`due_date`) > 90 then `i`.`total` - coalesce(`i`.`amount_paid`,0) else 0 end),0) AS `bucket_120` FROM `invoices` AS `i` WHERE `i`.`status` in ('issued','partially_paid') ;

-- --------------------------------------------------------

--
-- Structure for view `v_ar_client_credit`
--
DROP TABLE IF EXISTS `v_ar_client_credit`;

DROP VIEW IF EXISTS `v_ar_client_credit`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_client_credit`  AS SELECT `c`.`id` AS `client_id`, `c`.`client_name` AS `client_name`, `c`.`terms` AS `terms`, `c`.`credit_limit` AS `credit_limit`, coalesce(`ar`.`ar_total`,0) AS `ar_total`, CASE WHEN `c`.`credit_limit` is null THEN NULL ELSE `c`.`credit_limit`- coalesce(`ar`.`ar_total`,0) END AS `available_credit` FROM (`client` `c` left join (select `v_ar_invoices_open`.`client_id` AS `client_id`,sum(`v_ar_invoices_open`.`balance_due`) AS `ar_total` from `v_ar_invoices_open` group by `v_ar_invoices_open`.`client_id`) `ar` on(`ar`.`client_id` = `c`.`id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_ar_client_statement`
--
DROP TABLE IF EXISTS `v_ar_client_statement`;

DROP VIEW IF EXISTS `v_ar_client_statement`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_client_statement`  AS SELECT `c`.`id` AS `client_id`, `c`.`client_name` AS `client_name`, `i`.`id` AS `invoice_id`, `i`.`invoice_no` AS `invoice_no`, `i`.`issue_date` AS `issue_date`, `i`.`due_date` AS `due_date`, `i`.`total` AS `total`, `i`.`amount_paid` AS `amount_paid`, `i`.`balance_due` AS `balance_due`, (select group_concat(concat(`p`.`payment_date`,' ',`p`.`amount`) order by `p`.`payment_date` ASC separator ' | ') from `order_payment` `p` where `p`.`invoice_id` = `i`.`id`) AS `payments` FROM (`client` `c` join `invoices` `i` on(`i`.`client_id` = `c`.`id`)) WHERE `i`.`balance_due` > 0 AND `i`.`status` in ('issued','partially_paid') ORDER BY `c`.`client_name` ASC, `i`.`issue_date` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `v_ar_client_summary`
--
DROP TABLE IF EXISTS `v_ar_client_summary`;

DROP VIEW IF EXISTS `v_ar_client_summary`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_client_summary`  AS SELECT `c`.`id` AS `client_id`, `c`.`client_name` AS `client_name`, `c`.`terms` AS `terms`, `c`.`credit_limit` AS `credit_limit`, sum(`o`.`balance_due`) AS `ar_total`, sum(case when `o`.`aging_bucket` = 'current' then `o`.`balance_due` else 0 end) AS `bucket_current`, sum(case when `o`.`aging_bucket` = '1-30' then `o`.`balance_due` else 0 end) AS `bucket_1_30`, sum(case when `o`.`aging_bucket` = '31-60' then `o`.`balance_due` else 0 end) AS `bucket_31_60`, sum(case when `o`.`aging_bucket` = '61-90' then `o`.`balance_due` else 0 end) AS `bucket_61_90`, sum(case when `o`.`aging_bucket` = '90+' then `o`.`balance_due` else 0 end) AS `bucket_90_plus`, min(coalesce(`o`.`due_date`,`o`.`issue_date`)) AS `next_due_date`, max(`o`.`days_overdue`) AS `max_days_overdue`, count(0) AS `open_invoices` FROM (`client` `c` left join `v_ar_invoices_open` `o` on(`o`.`client_id` = `c`.`id`)) GROUP BY `c`.`id`, `c`.`client_name`, `c`.`terms`, `c`.`credit_limit` ;

-- --------------------------------------------------------

--
-- Structure for view `v_ar_invoices_open`
--
DROP TABLE IF EXISTS `v_ar_invoices_open`;

DROP VIEW IF EXISTS `v_ar_invoices_open`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_invoices_open`  AS SELECT `i`.`id` AS `id`, `i`.`client_id` AS `client_id`, `i`.`invoice_no` AS `invoice_no`, `i`.`issue_date` AS `issue_date`, `i`.`due_date` AS `due_date`, `i`.`terms` AS `terms`, `i`.`total` AS `total`, coalesce(`a`.`allocated`,0) AS `amount_paid`, `i`.`total`- coalesce(`a`.`allocated`,0) AS `balance_due`, CASE WHEN `i`.`due_date` is null THEN 0 WHEN `i`.`due_date` >= curdate() THEN 0 ELSE to_days(curdate()) - to_days(`i`.`due_date`) END AS `days_overdue`, CASE WHEN `i`.`due_date` is null OR `i`.`due_date` >= curdate() THEN 'current' WHEN to_days(curdate()) - to_days(`i`.`due_date`) between 1 and 30 THEN '1-30' WHEN to_days(curdate()) - to_days(`i`.`due_date`) between 31 and 60 THEN '31-60' WHEN to_days(curdate()) - to_days(`i`.`due_date`) between 61 and 90 THEN '61-90' ELSE '90+' END AS `aging_bucket` FROM (`invoices` `i` left join (select `receipt_allocations`.`invoice_id` AS `invoice_id`,sum(`receipt_allocations`.`amount_applied`) AS `allocated` from `receipt_allocations` group by `receipt_allocations`.`invoice_id`) `a` on(`a`.`invoice_id` = `i`.`id`)) WHERE `i`.`status` in ('issued','partially_paid') AND `i`.`total` > 0 ;

-- --------------------------------------------------------

--
-- Structure for view `v_ar_invoices_open_optimized`
--
DROP TABLE IF EXISTS `v_ar_invoices_open_optimized`;

DROP VIEW IF EXISTS `v_ar_invoices_open_optimized`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_invoices_open_optimized`  AS SELECT `i`.`id` AS `id`, `i`.`client_id` AS `client_id`, `i`.`invoice_no` AS `invoice_no`, `i`.`issue_date` AS `issue_date`, `i`.`due_date` AS `due_date`, `i`.`terms` AS `terms`, `i`.`total` AS `total`, coalesce(`pa`.`amount_paid`,0) AS `amount_paid`, greatest(round(`i`.`total` - coalesce(`pa`.`amount_paid`,0),2),0) AS `balance_due`, CASE WHEN `i`.`due_date` >= curdate() THEN 0 ELSE to_days(curdate()) - to_days(`i`.`due_date`) END AS `days_overdue`, CASE WHEN `i`.`due_date` >= curdate() THEN 'Current' WHEN to_days(curdate()) - to_days(`i`.`due_date`) between 1 and 30 THEN '1-30' WHEN to_days(curdate()) - to_days(`i`.`due_date`) between 31 and 60 THEN '31-60' WHEN to_days(curdate()) - to_days(`i`.`due_date`) between 61 and 90 THEN '61-90' ELSE '90+' END AS `aging_bucket` FROM (`invoices` `i` left join (select `ra`.`invoice_id` AS `invoice_id`,round(sum(`ra`.`amount_applied`),2) AS `amount_paid` from `receipt_allocations` `ra` group by `ra`.`invoice_id`) `pa` on(`pa`.`invoice_id` = `i`.`id`)) WHERE `i`.`status` in ('issued','partially_paid') AND `i`.`total` > coalesce(`pa`.`amount_paid`,0) ;

-- --------------------------------------------------------

--
-- Structure for view `v_ar_open_invoices`
--
DROP TABLE IF EXISTS `v_ar_open_invoices`;

DROP VIEW IF EXISTS `v_ar_open_invoices`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_open_invoices`  AS SELECT `i`.`id` AS `invoice_id`, `i`.`client_id` AS `client_id`, `i`.`invoice_no` AS `invoice_no`, `i`.`issue_date` AS `issue_date`, `i`.`due_date` AS `due_date`, `i`.`status` AS `status`, `i`.`total` AS `invoice_total`, coalesce(sum(`ra`.`amount_applied`),0) AS `allocated`, `i`.`total`- coalesce(sum(`ra`.`amount_applied`),0) AS `balance` FROM (`invoices` `i` left join `receipt_allocations` `ra` on(`ra`.`invoice_id` = `i`.`id`)) WHERE `i`.`status` in ('issued','partially_paid') GROUP BY `i`.`id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_ar_overall`
--
DROP TABLE IF EXISTS `v_ar_overall`;

DROP VIEW IF EXISTS `v_ar_overall`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_overall`  AS SELECT sum(`v_ar_invoices_open`.`balance_due`) AS `ar_total`, sum(case when `v_ar_invoices_open`.`aging_bucket` = 'current' then `v_ar_invoices_open`.`balance_due` end) AS `bucket_current`, sum(case when `v_ar_invoices_open`.`aging_bucket` = '1-30' then `v_ar_invoices_open`.`balance_due` end) AS `bucket_1_30`, sum(case when `v_ar_invoices_open`.`aging_bucket` = '31-60' then `v_ar_invoices_open`.`balance_due` end) AS `bucket_31_60`, sum(case when `v_ar_invoices_open`.`aging_bucket` = '61-90' then `v_ar_invoices_open`.`balance_due` end) AS `bucket_61_90`, sum(case when `v_ar_invoices_open`.`aging_bucket` = '90+' then `v_ar_invoices_open`.`balance_due` end) AS `bucket_90_plus`, count(0) AS `open_invoices` FROM `v_ar_invoices_open` ;

-- --------------------------------------------------------

--
-- Structure for view `v_ar_summary`
--
DROP TABLE IF EXISTS `v_ar_summary`;

DROP VIEW IF EXISTS `v_ar_summary`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_summary`  AS SELECT count(0) AS `open_count`, coalesce(sum(`v`.`balance_due`),0) AS `ar_total`, coalesce(sum(case when `v`.`days_overdue` > 0 then `v`.`balance_due` else 0 end),0) AS `overdue_total`, coalesce((select sum(`ra`.`amount_applied`) from (`receipt_allocations` `ra` join `receipts` `r` on(`r`.`id` = `ra`.`receipt_id`)) where year(`r`.`receipt_date`) = year(curdate()) and month(`r`.`receipt_date`) = month(curdate())),0) AS `paid_this_month` FROM `v_ar_invoices_open` AS `v` ;

-- --------------------------------------------------------

--
-- Structure for view `v_ar_summary_optimized`
--
DROP TABLE IF EXISTS `v_ar_summary_optimized`;

DROP VIEW IF EXISTS `v_ar_summary_optimized`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ar_summary_optimized`  AS SELECT count(`i`.`id`) AS `open_count`, coalesce(sum(`i`.`total` - coalesce(`i`.`amount_paid`,0)),0) AS `ar_total`, coalesce(sum(case when `i`.`due_date` < curdate() and `i`.`status` in ('issued','partially_paid') then `i`.`total` - coalesce(`i`.`amount_paid`,0) else 0 end),0) AS `overdue_total`, coalesce((select sum(`r`.`amount`) from `receipts` `r` where `r`.`receipt_date` >= date_format(curdate(),'%Y-%m-01')),0) AS `paid_this_month`, coalesce((select sum(`r`.`amount`) from `receipts` `r` where `r`.`receipt_date` >= curdate() - interval 30 day),0) AS `paid_last_30_days`, coalesce((select sum(`r`.`amount`) from `receipts` `r` where `r`.`receipt_date` >= curdate() - interval 7 day),0) AS `paid_last_7_days` FROM `invoices` AS `i` WHERE `i`.`status` in ('issued','partially_paid') ;

-- --------------------------------------------------------

--
-- Structure for view `v_attendance_daily_emp`
--
DROP TABLE IF EXISTS `v_attendance_daily_emp`;

DROP VIEW IF EXISTS `v_attendance_daily_emp`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_attendance_daily_emp`  AS SELECT `a`.`employee_id` AS `employee_id`, `a`.`work_date` AS `work_date`, `a`.`hours` AS `hours_worked`, (`a`.`status` = 'approved') * 1 AS `present_day`, (`a`.`status` = 'absent') * 1 AS `absent_day`, (`a`.`status` = 'half') * 1 AS `half_day`, (`a`.`status` = 'on_leave') * 1 AS `leave_day`, greatest(`a`.`hours` - 8.00,0) AS `overtime_hours` FROM `attendance` AS `a` ;

-- --------------------------------------------------------

--
-- Structure for view `v_attendance_period_emp`
--
DROP TABLE IF EXISTS `v_attendance_period_emp`;

DROP VIEW IF EXISTS `v_attendance_period_emp`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_attendance_period_emp`  AS SELECT `v_attendance_daily_emp`.`employee_id` AS `employee_id`, sum(`v_attendance_daily_emp`.`hours_worked`) AS `hours_worked`, sum(`v_attendance_daily_emp`.`present_day`) AS `present_days`, sum(`v_attendance_daily_emp`.`absent_day`) AS `absent_days`, sum(`v_attendance_daily_emp`.`half_day`) AS `half_days`, sum(`v_attendance_daily_emp`.`leave_day`) AS `leave_days`, round(sum(`v_attendance_daily_emp`.`overtime_hours`),2) AS `ot_hours` FROM `v_attendance_daily_emp` GROUP BY `v_attendance_daily_emp`.`employee_id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_bookable_workers`
--
DROP TABLE IF EXISTS `v_bookable_workers`;

DROP VIEW IF EXISTS `v_bookable_workers`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_bookable_workers`  AS SELECT `e`.`id` AS `id`, `e`.`employee_code` AS `employee_code`, `e`.`full_name` AS `full_name`, `e`.`email` AS `email`, `e`.`phone` AS `phone`, `e`.`position_title` AS `position_title`, `e`.`booking_skills` AS `booking_skills`, `e`.`status` AS `status`, `e`.`is_bookable` AS `is_bookable` FROM `employees` AS `e` WHERE `e`.`is_bookable` = 1 AND `e`.`status` = 'active' ;

-- --------------------------------------------------------

--
-- Structure for view `v_categories_tree`
--
DROP TABLE IF EXISTS `v_categories_tree`;

DROP VIEW IF EXISTS `v_categories_tree`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_categories_tree`  AS SELECT `c`.`id` AS `id`, `c`.`parent_id` AS `parent_id`, `c`.`name` AS `name`, `c`.`name_ar` AS `name_ar`, `c`.`description` AS `description`, `c`.`icon_url` AS `icon_url`, `c`.`image_url` AS `image_url`, `c`.`sort_order` AS `sort_order`, `c`.`is_active` AS `is_active`, `c`.`show_on_home` AS `show_on_home`, `p`.`name` AS `parent_name`, (select count(0) from `service_categories` where `service_categories`.`parent_id` = `c`.`id`) AS `children_count`, (select count(0) from `services` where `services`.`category_id` = `c`.`id` and `services`.`is_active` = 1) AS `services_count` FROM (`service_categories` `c` left join `service_categories` `p` on(`c`.`parent_id` = `p`.`id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_client_unbilled_summary`
--
DROP TABLE IF EXISTS `v_client_unbilled_summary`;

DROP VIEW IF EXISTS `v_client_unbilled_summary`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_client_unbilled_summary`  AS SELECT `mo`.`client_id` AS `client_id`, min(`mo`.`svc_date_calc`) AS `first_date`, max(`mo`.`svc_date_calc`) AS `last_date`, count(0) AS `orders_cnt`, round(sum(coalesce(`mo`.`hours`,0)),2) AS `hours`, round(sum(coalesce(`mo`.`total`,0)),2) AS `subtotal`, round(sum(coalesce(`mo`.`vat_amount`,0)),2) AS `vat`, round(sum(coalesce(`mo`.`grand_total`,0)),2) AS `grand_total`, (select `c`.`payment` from `client` `c` where `c`.`id` = `mo`.`client_id`) AS `cadence` FROM `make_order` AS `mo` WHERE coalesce(`mo`.`status`,'') <> 'cancelled' AND coalesce(`mo`.`invoice_id`,0) = 0 GROUP BY `mo`.`client_id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_driver`
--
DROP TABLE IF EXISTS `v_driver`;

DROP VIEW IF EXISTS `v_driver`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_driver`  AS SELECT `e`.`id` AS `id`, `e`.`emp_num` AS `emp_num`, `e`.`full_name` AS `driver_name`, `e`.`nickname` AS `nickname`, `e`.`email` AS `email`, `e`.`phone` AS `mobile_num`, `e`.`visa_d` AS `visa_d`, `e`.`visa_ex_d` AS `visa_ex_d`, `e`.`pass_num` AS `pass_num`, `e`.`pass_ex_d` AS `pass_ex_d`, `e`.`date_joined` AS `join_date`, NULL AS `end_lab_card`, `e`.`bank_account_no` AS `bank_acc_num`, `e`.`iban` AS `iban`, `e`.`basic_salary` AS `basic_salary`, `e`.`allowance` AS `allowance`, `e`.`bonus` AS `bonus`, `e`.`total_salary` AS `total_salary`, `e`.`address` AS `address`, 'Driver' AS `position`, NULL AS `comment` FROM `employees` AS `e` WHERE `e`.`position_title` = 'Driver' ;

-- --------------------------------------------------------

--
-- Structure for view `v_index_usage`
--
DROP TABLE IF EXISTS `v_index_usage`;

DROP VIEW IF EXISTS `v_index_usage`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_index_usage`  AS SELECT `information_schema`.`statistics`.`TABLE_NAME` AS `table_name`, `information_schema`.`statistics`.`INDEX_NAME` AS `index_name`, `information_schema`.`statistics`.`CARDINALITY` AS `cardinality`, `information_schema`.`statistics`.`SUB_PART` AS `sub_part`, `information_schema`.`statistics`.`PACKED` AS `packed`, `information_schema`.`statistics`.`NULLABLE` AS `nullable`, `information_schema`.`statistics`.`INDEX_TYPE` AS `index_type` FROM `information_schema`.`statistics` WHERE `information_schema`.`statistics`.`TABLE_SCHEMA` = database() ORDER BY `information_schema`.`statistics`.`TABLE_NAME` ASC, `information_schema`.`statistics`.`SEQ_IN_INDEX` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `v_leave_balances_current`
--
DROP TABLE IF EXISTS `v_leave_balances_current`;

DROP VIEW IF EXISTS `v_leave_balances_current`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_leave_balances_current`  AS SELECT `b`.`id` AS `id`, `b`.`employee_id` AS `employee_id`, `b`.`leave_type_id` AS `leave_type_id`, `b`.`year` AS `year`, `b`.`opening` AS `opening`, `b`.`accrued` AS `accrued`, `b`.`taken` AS `taken`, `b`.`carried` AS `carried`, `b`.`closing` AS `closing`, `b`.`updated_at` AS `updated_at`, `lt`.`name` AS `leave_type_name` FROM (`leave_balances` `b` join `leave_types` `lt` on(`lt`.`id` = `b`.`leave_type_id`)) WHERE `b`.`year` = year(curdate()) ;

-- --------------------------------------------------------

--
-- Structure for view `v_online_bookings_summary`
--
DROP TABLE IF EXISTS `v_online_bookings_summary`;

DROP VIEW IF EXISTS `v_online_bookings_summary`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_online_bookings_summary`  AS SELECT `ob`.`id` AS `id`, `ob`.`customer_name` AS `customer_name`, `ob`.`customer_phone` AS `customer_phone`, `ob`.`customer_email` AS `customer_email`, `s`.`name` AS `service_name`, `s`.`category` AS `service_category`, `e`.`full_name` AS `employee_name`, `ob`.`scheduled_date` AS `scheduled_date`, `ob`.`scheduled_time` AS `scheduled_time`, `ob`.`total_price` AS `total_price`, `ob`.`status` AS `status`, `ob`.`address` AS `address`, `ob`.`notes` AS `notes`, `ob`.`created_at` AS `created_at`, `ob`.`confirmed_at` AS `confirmed_at`, `ob`.`work_order_id` AS `work_order_id` FROM ((`online_bookings` `ob` left join `services` `s` on(`ob`.`service_id` = `s`.`id`)) left join `employees` `e` on(`ob`.`employee_id` = `e`.`id`)) ORDER BY `ob`.`created_at` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_profit_and_loss`
--
DROP TABLE IF EXISTS `v_profit_and_loss`;

DROP VIEW IF EXISTS `v_profit_and_loss`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_profit_and_loss`  AS SELECT `c`.`type` AS `type`, `c`.`account_no` AS `account_no`, `c`.`name` AS `account_name`, sum(`l`.`debit`) AS `debits`, sum(`l`.`credit`) AS `credits`, CASE WHEN `c`.`type` = 'Revenue' THEN sum(`l`.`credit`) - sum(`l`.`debit`) WHEN `c`.`type` = 'Expense' THEN sum(`l`.`debit`) - sum(`l`.`credit`) ELSE 0 END AS `amount` FROM ((`chart_of_accounts` `c` left join `gl_journal_lines` `l` on(`l`.`account_id` = `c`.`id`)) left join `gl_journals` `j` on(`j`.`id` = `l`.`journal_id` and `j`.`is_posted` = 1 and `j`.`is_reversed` = 0)) WHERE `c`.`type` in ('Revenue','Expense') GROUP BY `c`.`id`, `c`.`type`, `c`.`account_no`, `c`.`name` ;

-- --------------------------------------------------------

--
-- Structure for view `v_services_enhanced`
--
DROP TABLE IF EXISTS `v_services_enhanced`;

DROP VIEW IF EXISTS `v_services_enhanced`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_services_enhanced`  AS SELECT `s`.`id` AS `id`, `s`.`category_id` AS `category_id`, `c`.`name` AS `category_name`, `c`.`parent_id` AS `parent_category_id`, `pc`.`name` AS `parent_category_name`, `s`.`name` AS `service_name`, `s`.`description` AS `description`, `s`.`price` AS `price`, `s`.`base_price` AS `base_price`, `s`.`price_per_unit` AS `price_per_unit`, `s`.`min_price` AS `min_price`, `s`.`max_price` AS `max_price`, `s`.`duration_minutes` AS `duration_minutes`, `s`.`min_hours` AS `min_hours`, `s`.`max_hours` AS `max_hours`, `s`.`min_professionals` AS `min_professionals`, `s`.`max_professionals` AS `max_professionals`, `s`.`requires_materials` AS `requires_materials`, `s`.`allows_frequency` AS `allows_frequency`, `s`.`image_url` AS `image_url`, `s`.`is_active` AS `is_active`, `s`.`sort_order` AS `sort_order`, `pr`.`name` AS `pricing_rule_name`, `pr`.`calculation_type` AS `calculation_type`, `s`.`created_at` AS `created_at`, `s`.`updated_at` AS `updated_at` FROM (((`services` `s` left join `service_categories` `c` on(`s`.`category_id` = `c`.`id`)) left join `service_categories` `pc` on(`c`.`parent_id` = `pc`.`id`)) left join `pricing_rules` `pr` on(`s`.`pricing_rule_id` = `pr`.`id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_slow_queries`
--
DROP TABLE IF EXISTS `v_slow_queries`;

DROP VIEW IF EXISTS `v_slow_queries`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_slow_queries`  AS SELECT 'Enable slow query log to see performance data' AS `message` ;

-- --------------------------------------------------------

--
-- Structure for view `v_table_sizes`
--
DROP TABLE IF EXISTS `v_table_sizes`;

DROP VIEW IF EXISTS `v_table_sizes`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_table_sizes`  AS SELECT `information_schema`.`tables`.`TABLE_NAME` AS `table_name`, round((`information_schema`.`tables`.`DATA_LENGTH` + `information_schema`.`tables`.`INDEX_LENGTH`) / 1024 / 1024,2) AS `Size (MB)`, `information_schema`.`tables`.`TABLE_ROWS` AS `table_rows` FROM `information_schema`.`tables` WHERE `information_schema`.`tables`.`TABLE_SCHEMA` = database() ORDER BY `information_schema`.`tables`.`DATA_LENGTH`+ `information_schema`.`tables`.`INDEX_LENGTH` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_trial_balance`
--
DROP TABLE IF EXISTS `v_trial_balance`;

DROP VIEW IF EXISTS `v_trial_balance`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_trial_balance`  AS SELECT `c`.`id` AS `account_id`, `c`.`account_no` AS `account_no`, `c`.`name` AS `account_name`, `c`.`type` AS `type`, sum(`l`.`debit`) AS `debits`, sum(`l`.`credit`) AS `credits`, sum(`l`.`debit`) - sum(`l`.`credit`) AS `net` FROM ((`chart_of_accounts` `c` left join `gl_journal_lines` `l` on(`l`.`account_id` = `c`.`id`)) left join `gl_journals` `j` on(`j`.`id` = `l`.`journal_id` and `j`.`is_posted` = 1 and `j`.`is_reversed` = 0)) GROUP BY `c`.`id`, `c`.`account_no`, `c`.`name`, `c`.`type` ;

-- --------------------------------------------------------

--
-- Structure for view `v_workers`
--
DROP TABLE IF EXISTS `v_workers`;

DROP VIEW IF EXISTS `v_workers`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_workers`  AS SELECT `e`.`id` AS `id`, `e`.`emp_num` AS `emp_num`, `e`.`full_name` AS `worker_name`, `e`.`nickname` AS `nickname`, `e`.`daily_cap_hours` AS `daily_cap_hours`, `e`.`weekly_cap_hours` AS `weekly_cap_hours`, NULL AS `travel_gap_min`, `e`.`email` AS `email`, `e`.`phone` AS `mobile_num`, `e`.`visa_d` AS `visa_d`, `e`.`visa_ex_d` AS `visa_ex_d`, `e`.`pass_num` AS `pass_num`, `e`.`pass_ex_d` AS `pass_ex_d`, `e`.`date_joined` AS `join_date`, NULL AS `bank_acc_num`, NULL AS `iban_num`, NULL AS `basic_salary`, NULL AS `allowance`, NULL AS `bonus`, NULL AS `total_salary`, NULL AS `imageU`, NULL AS `imageM`, NULL AS `imageW`, NULL AS `imageE` FROM `employees` AS `e` ;

-- --------------------------------------------------------

--
-- Structure for view `v_worker_daily_perf`
--
DROP TABLE IF EXISTS `v_worker_daily_perf`;

DROP VIEW IF EXISTS `v_worker_daily_perf`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_worker_daily_perf`  AS SELECT `d`.`worker_id` AS `worker_id`, `d`.`work_date` AS `work_date`, round(sum(case when `voc`.`status` in ('confirmed','completed') then `voc`.`hours` else 0 end),2) AS `hours_from_orders`, round(sum(case when `voc`.`status` in ('confirmed','completed') then `voc`.`revenue_ex_vat` else 0 end),2) AS `revenue_ex_vat`, round(sum(case when `voc`.`status` in ('confirmed','completed') then `voc`.`revenue_incl_vat` else 0 end),2) AS `revenue_incl_vat`, max(case when `a`.`status` = 'approved' then 1 else 0 end) AS `present`, max(case when `a`.`status` in ('absent','on_leave') then 1 else 0 end) AS `absent`, round(sum(case when `a`.`status` = 'approved' then `a`.`hours` else 0 end),2) AS `att_hours` FROM (((select `ow`.`worker_id` AS `worker_id`,`mo`.`date` AS `work_date` from (`order_workers` `ow` join `make_order` `mo` on(`mo`.`id` = `ow`.`order_id`)) union select `attendance`.`employee_id` AS `worker_id`,`attendance`.`work_date` AS `work_date` from `attendance`) `d` left join `v_worker_order_contrib` `voc` on(`voc`.`worker_id` = `d`.`worker_id` and `voc`.`service_date` = `d`.`work_date`)) left join `attendance` `a` on(`a`.`employee_id` = `d`.`worker_id` and `a`.`work_date` = `d`.`work_date`)) GROUP BY `d`.`worker_id`, `d`.`work_date` ;

-- --------------------------------------------------------

--
-- Structure for view `v_worker_employee_map`
--
DROP TABLE IF EXISTS `v_worker_employee_map`;

DROP VIEW IF EXISTS `v_worker_employee_map`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_worker_employee_map`  AS SELECT `w`.`id` AS `worker_id`, `e`.`id` AS `employee_id` FROM (`workers` `w` join `employees` `e` on(`e`.`employee_code` = `w`.`emp_num` or `e`.`full_name` = `w`.`worker_name` or `e`.`nickname` = `w`.`nickname`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_worker_order_contrib`
--
DROP TABLE IF EXISTS `v_worker_order_contrib`;

DROP VIEW IF EXISTS `v_worker_order_contrib`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_worker_order_contrib`  AS SELECT `ow`.`worker_id` AS `worker_id`, `mo`.`id` AS `order_id`, `mo`.`client_id` AS `client_id`, `mo`.`client_name` AS `client_name`, `mo`.`date` AS `service_date`, coalesce(`mo`.`net_hours`,0) AS `hours`, coalesce(`mo`.`net_amount`,case when `owc`.`cnt` > 0 then `mo`.`amount_afc` / `owc`.`cnt` else 0 end) AS `revenue_ex_vat`, CASE WHEN `owc`.`cnt` > 0 THEN `mo`.`grand_total`/ `owc`.`cnt` ELSE 0 END AS `revenue_incl_vat`, `mo`.`status` AS `status` FROM ((`order_workers` `ow` join `make_order` `mo` on(`mo`.`id` = `ow`.`order_id`)) join (select `order_workers`.`order_id` AS `order_id`,count(0) AS `cnt` from `order_workers` group by `order_workers`.`order_id`) `owc` on(`owc`.`order_id` = `mo`.`id`)) ;
