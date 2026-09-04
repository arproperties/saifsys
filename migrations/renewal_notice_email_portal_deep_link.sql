-- Optional: add Tenant Portal deep link to existing renewal notice email templates.
-- Placeholders supported in subject/body: {RENEWAL_PORTAL_URL}, {RENEWAL_PORTAL_LINK}
-- (Also applied automatically in code when sending from lease_renewal_workflow_view.php.)
-- Run once per database if your stored template does not yet mention the portal.

SET NAMES utf8mb4;

UPDATE email_templates
SET body_html = CONCAT(
    TRIM(body_html),
    '<p style="margin-top:16px;font-family:Arial,sans-serif;">You can view this renewal and respond in the Tenant Portal:<br>{RENEWAL_PORTAL_LINK}</p>'
)
WHERE template_type = 'renewal_notice'
  AND is_active = 1
  AND body_html NOT LIKE '%{RENEWAL_PORTAL_URL}%'
  AND body_html NOT LIKE '%{RENEWAL_PORTAL_LINK}%';

SELECT 'renewal_notice_email_portal_deep_link: templates updated where missing placeholders.' AS status;
