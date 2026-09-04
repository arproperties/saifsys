-- ============================================================================
-- Seed default Real Estate document types
-- ----------------------------------------------------------------------------
-- The Document Type dropdown (documents_upload.php) reads from
-- re_document_types. A fresh install has none, so the dropdown is empty.
-- This seeds a standard set for EVERY real-estate company.
--
-- Idempotent: uses INSERT IGNORE against the unique key (company_id,
-- document_type_code), so re-running will not create duplicates and will only
-- fill in any missing types.
-- ============================================================================

INSERT IGNORE INTO re_document_types
    (company_id, document_type_name, document_type_code, description, is_required, has_expiry, default_expiry_days, alert_days_before_expiry, display_order)
SELECT c.id, x.name, x.code, x.descr, x.is_required, x.has_expiry, x.default_expiry_days, x.alert_days, x.display_order
FROM companies c
JOIN (
    SELECT 'Ejari Certificate'        AS name, 'EJARI'            AS code, 'Registered tenancy contract (Ejari)' AS descr, 0 AS is_required, 1 AS has_expiry, 365  AS default_expiry_days, 30 AS alert_days, 1  AS display_order UNION ALL
    SELECT 'Tenancy Contract',             'TENANCY_CONTRACT',     'Signed lease/tenancy agreement',            0,    0, NULL, 30, 2  UNION ALL
    SELECT 'Tenant Passport',              'TENANT_PASSPORT',      'Tenant passport copy',                      0,    1, NULL, 30, 3  UNION ALL
    SELECT 'Tenant Visa',                  'TENANT_VISA',          'Tenant residence visa',                     0,    1, NULL, 30, 4  UNION ALL
    SELECT 'Emirates ID',                  'EMIRATES_ID',          'Tenant Emirates ID',                        0,    1, NULL, 30, 5  UNION ALL
    SELECT 'Trade License',                'TRADE_LICENSE',        'Company trade license (commercial tenant)', 0,    1, 365,  30, 6  UNION ALL
    SELECT 'Title Deed',                   'TITLE_DEED',           'Property title deed',                       0,    0, NULL, 30, 7  UNION ALL
    SELECT 'Cheque Copy',                  'CHEQUE_COPY',          'Copy of a payment cheque',                  0,    0, NULL, 30, 8  UNION ALL
    SELECT 'Security Cheque',              'SECURITY_CHEQUE',      'Security deposit cheque',                   0,    0, NULL, 30, 9  UNION ALL
    SELECT 'DEWA / Utility Bill',          'UTILITY_BILL',         'DEWA or utility account document',          0,    0, NULL, 30, 10 UNION ALL
    SELECT 'NOC',                          'NOC',                  'No Objection Certificate',                  0,    0, NULL, 30, 11 UNION ALL
    SELECT 'Insurance',                    'INSURANCE',            'Property or tenant insurance',              0,    1, 365,  30, 12 UNION ALL
    SELECT 'Move-in / Inspection Report',  'INSPECTION_REPORT',    'Move-in / move-out inspection report',      0,    0, NULL, 30, 13 UNION ALL
    -- Legal document types (Legal Department module)
    SELECT 'Court Filing',                 'COURT_FILING',         'Court / RDC filing or claim document',      0,    0, NULL, 30, 20 UNION ALL
    SELECT 'Judgment / Ruling',            'JUDGMENT',             'Court judgment or ruling',                  0,    0, NULL, 30, 21 UNION ALL
    SELECT 'Power of Attorney',            'POWER_OF_ATTORNEY',    'Power of attorney',                         0,    1, NULL, 30, 22 UNION ALL
    SELECT 'Police Report',                'POLICE_REPORT',        'Police report (e.g. bounced cheque)',       0,    0, NULL, 30, 23 UNION ALL
    SELECT 'Legal Notice Copy',            'LEGAL_NOTICE',         'Copy of a served legal notice',             0,    0, NULL, 30, 24 UNION ALL
    SELECT 'Legal Memo / Correspondence',  'LEGAL_MEMO',           'Legal memo or correspondence',              0,    0, NULL, 30, 25 UNION ALL
    SELECT 'Settlement Agreement',         'SETTLEMENT',           'Settlement / waiver agreement',             0,    0, NULL, 30, 26 UNION ALL
    SELECT 'Other',                        'OTHER',                'Other document',                            0,    0, NULL, 30, 99
) AS x
WHERE c.business_type = 'realestate';
