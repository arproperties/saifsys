-- ============================================================================
-- Real Estate Company Setup SQL Script
-- ============================================================================
-- This script helps you set up a Real Estate company and assign users
-- Replace the placeholder values with your actual data
-- Database: bestsys
-- ============================================================================

-- ============================================================================
-- STEP 1: Create Real Estate Company
-- ============================================================================
-- Replace 'Your Real Estate Company' and 'REC' with your actual company details

INSERT INTO companies (name, code, business_type, is_active) 
VALUES ('Your Real Estate Company', 'REC', 'realestate', 1);

-- Get the company ID (use this in the next steps)
-- SELECT id FROM companies WHERE business_type = 'realestate' ORDER BY id DESC LIMIT 1;

-- ============================================================================
-- STEP 2: Create Real Estate Roles
-- ============================================================================

INSERT IGNORE INTO roles (name, module, is_system) VALUES 
('Property Manager', 'realestate', 0),
('Leasing Agent', 'realestate', 0),
('Property Administrator', 'realestate', 0);

-- ============================================================================
-- STEP 3: Assign Users to Real Estate Company
-- ============================================================================
-- Replace USER_ID with the actual user ID you want to assign
-- Replace COMPANY_ID with the Real Estate company ID from Step 1
-- Set is_primary = 1 if this should be the user's primary company

-- Example: Assign user ID 1 to Real Estate company ID 2
-- INSERT INTO user_companies (user_id, company_id, is_primary) 
-- VALUES (1, 2, 0);

-- To assign multiple users, repeat the INSERT statement:
-- INSERT INTO user_companies (user_id, company_id, is_primary) VALUES (2, 2, 0);
-- INSERT INTO user_companies (user_id, company_id, is_primary) VALUES (3, 2, 0);

-- ============================================================================
-- STEP 4: Assign Real Estate Roles to Users
-- ============================================================================
-- Replace USER_ID with the actual user ID
-- The role_id is automatically selected based on the role name

-- Example: Assign Property Manager role to user ID 1
-- INSERT INTO user_roles (user_id, role_id) 
-- SELECT 1, id FROM roles WHERE name = 'Property Manager' AND module = 'realestate';

-- Example: Assign Leasing Agent role to user ID 2
-- INSERT INTO user_roles (user_id, role_id) 
-- SELECT 2, id FROM roles WHERE name = 'Leasing Agent' AND module = 'realestate';

-- ============================================================================
-- HELPER QUERIES
-- ============================================================================

-- View all Real Estate companies
-- SELECT id, name, code, business_type, is_active FROM companies WHERE business_type = 'realestate';

-- View all users
-- SELECT id, username, fullname FROM user ORDER BY username;

-- View all Real Estate roles
-- SELECT id, name, module FROM roles WHERE module = 'realestate';

-- View user-company assignments
-- SELECT uc.user_id, u.username, uc.company_id, c.name as company_name, uc.is_primary
-- FROM user_companies uc
-- JOIN user u ON u.id = uc.user_id
-- JOIN companies c ON c.id = uc.company_id
-- WHERE c.business_type = 'realestate';

-- View user roles
-- SELECT ur.user_id, u.username, r.name as role_name, r.module
-- FROM user_roles ur
-- JOIN user u ON u.id = ur.user_id
-- JOIN roles r ON r.id = ur.role_id
-- WHERE r.module = 'realestate';

-- ============================================================================
-- COMPLETE EXAMPLE (Uncomment and modify as needed)
-- ============================================================================

/*
-- Step 1: Create company
INSERT INTO companies (name, code, business_type, is_active) 
VALUES ('ABC Real Estate', 'ABC-RE', 'realestate', 1);
SET @company_id = LAST_INSERT_ID();

-- Step 2: Create roles (already done above, but included for completeness)
INSERT IGNORE INTO roles (name, module, is_system) VALUES 
('Property Manager', 'realestate', 0),
('Leasing Agent', 'realestate', 0);

-- Step 3: Assign user ID 1 to the company
INSERT INTO user_companies (user_id, company_id, is_primary) 
VALUES (1, @company_id, 0);

-- Step 4: Assign Property Manager role to user ID 1
INSERT INTO user_roles (user_id, role_id) 
SELECT 1, id FROM roles WHERE name = 'Property Manager' AND module = 'realestate';
*/

