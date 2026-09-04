-- Ensure a usable email template exists for Lease Renewal Notices
-- (template_type = 'renewal_notice')

INSERT INTO email_templates
    (template_name, template_type, subject, body_html, body_plain, is_active, created_at, updated_at)
SELECT
    'Renewal Notice (Default)',
    'renewal_notice',
    '[Real Estate] Renewal Notice for {UNIT_NUMBER}',
    CONCAT(
        '<html><body>',
        '<p>Dear {TENANT_NAME},</p>',
        '<p>Please find attached your lease renewal notice for unit <strong>{UNIT_NUMBER}</strong>.</p>',
        '<p><strong>Proposed New Rent:</strong> {NEW_RENT} AED / year</p>',
        '<p><strong>Lease Period:</strong> {PROPOSED_START_DATE} to {PROPOSED_END_DATE}</p>',
        '<p>If you have any questions, please contact the Property Management office.</p>',
        '<p>You can also view this renewal and respond in the Tenant Portal:<br>{RENEWAL_PORTAL_LINK}</p>',
        '<p style="font-size:12px;color:#555;">Direct link: {RENEWAL_PORTAL_URL}</p>',
        '<p>Thank you.</p>',
        '</body></html>'
    ),
    'Dear {TENANT_NAME},\n\nPlease find attached your lease renewal notice for unit {UNIT_NUMBER}.\n\nProposed New Rent: {NEW_RENT} AED / year\nLease Period: {PROPOSED_START_DATE} to {PROPOSED_END_DATE}\n\nTenant Portal: {RENEWAL_PORTAL_URL}\n\nThank you.',
    1,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates
    WHERE template_type = 'renewal_notice'
    LIMIT 1
);

