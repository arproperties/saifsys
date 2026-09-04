-- ============================================================================
-- Fix Floor Names - Remove duplicate "Floor X" names
-- ============================================================================
-- This migration removes duplicate floor names where name = "Floor {floor_number}"
-- Only keeps meaningful names like "Ground Floor" for floor 1
-- ============================================================================

-- Update floors where name is just "Floor {floor_number}" to NULL
UPDATE re_floors 
SET name = NULL 
WHERE name IS NOT NULL 
AND name = CONCAT('Floor ', floor_number);

-- Keep "Ground Floor" for floor 1, but set others to NULL if they're just "Floor X"
UPDATE re_floors 
SET name = NULL 
WHERE floor_number > 1 
AND name IS NOT NULL 
AND name = CONCAT('Floor ', floor_number);

SELECT 'Floor names cleaned up successfully!' AS message;

