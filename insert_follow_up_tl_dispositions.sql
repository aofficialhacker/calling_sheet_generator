-- Script to insert team leader dispositions under Follow up bucket (bucket_id = 1)

-- Show current team leader dispositions
SELECT 'BEFORE INSERTION:' as status;
SELECT COUNT(*) as total_dispositions FROM team_leader_dispositions;

-- Insert team leader dispositions for Follow up bucket
INSERT INTO team_leader_dispositions (disposition_name, description, bucket_id, is_active, created_by) VALUES
('Follow up - Hot Lead', 'Customer showed strong interest, immediate follow up required', 1, 1, 'SA001'),
('Follow up - Warm Lead', 'Customer interested but needs more information', 1, 1, 'SA001'),
('Follow up - Cold Lead', 'Customer showed minimal interest, follow up later', 1, 1, 'SA001'),
('Follow up - Callback Requested', 'Customer specifically requested a callback at specific time', 1, 1, 'SA001'),
('Follow up - Information Sent', 'Information/brochure sent, follow up required', 1, 1, 'SA001'),
('Follow up - Price Inquiry', 'Customer inquired about pricing, follow up needed', 1, 1, 'SA001'),
('Follow up - Product Demo', 'Customer wants product demonstration', 1, 1, 'SA001'),
('Follow up - Decision Pending', 'Customer is considering, follow up for decision', 1, 1, 'SA001'),
('Follow up - Family Discussion', 'Customer needs to discuss with family/spouse', 1, 1, 'SA001'),
('Follow up - Budget Planning', 'Customer planning budget, follow up later', 1, 1, 'SA001');

-- Show after insertion
SELECT 'AFTER INSERTION:' as status;
SELECT COUNT(*) as total_dispositions FROM team_leader_dispositions;

-- Show inserted dispositions
SELECT 'INSERTED FOLLOW UP DISPOSITIONS:' as status;
SELECT id, disposition_name, description, bucket_id 
FROM team_leader_dispositions 
WHERE bucket_id = 1 
ORDER BY id;