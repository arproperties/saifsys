-- Recurrent Income > rooms: each tenant's start date and end date (shown on the room and
-- partition cards, edited on the Tenant details tab). Safe to run twice.
-- Run on live before uploading the files; until then the pages simply do not show the dates.
ALTER TABLE rpay_room_beds
  ADD COLUMN IF NOT EXISTS start_date DATE NULL COMMENT 'the day the tenant moved in',
  ADD COLUMN IF NOT EXISTS end_date DATE NULL COMMENT 'the day the stay ends';
