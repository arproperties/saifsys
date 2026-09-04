-- Add Firebase UID to client table for Firebase Authentication integration
-- This allows seamless authentication via Firebase while maintaining client records

ALTER TABLE `client`
ADD COLUMN `firebase_uid` VARCHAR(128) NULL AFTER `mobile_num`;

ALTER TABLE `client`
ADD UNIQUE INDEX `idx_firebase_uid` (`firebase_uid`);

-- Add Firebase UID to mobile_user table as well
ALTER TABLE `mobile_user`
ADD COLUMN `firebase_uid` VARCHAR(128) NULL AFTER `client_id`;

ALTER TABLE `mobile_user`
ADD UNIQUE INDEX `idx_mobile_firebase_uid` (`firebase_uid`);

-- Update existing records to ensure consistency
UPDATE `client` c
INNER JOIN `mobile_user` mu ON c.id = mu.client_id
SET c.firebase_uid = mu.firebase_uid
WHERE c.firebase_uid IS NULL AND mu.firebase_uid IS NOT NULL;

