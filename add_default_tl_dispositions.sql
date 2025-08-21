-- Add default Team Leader dispositions
-- These are specifically for Team Leaders to use when reviewing "Interested" leads

INSERT IGNORE INTO team_leader_dispositions (disposition_name, description, created_by) VALUES 
('Interested - Proceed to Payment', 'Customer confirmed interest and ready for payment processing', 'SUPER01'),
('Follow Up Required', 'Customer interested but needs follow-up call at specific time', 'SUPER01'),
('Not Qualified', 'Customer does not meet the qualification criteria', 'SUPER01'),
('Price Objection', 'Customer interested but has concerns about pricing', 'SUPER01'),
('Need More Information', 'Customer wants additional product details before deciding', 'SUPER01'),
('Call Back Later', 'Customer requested to be called back at a different time', 'SUPER01'),
('Wrong Contact Details', 'Phone number or contact information is incorrect', 'SUPER01'),
('Already Purchased', 'Customer has already purchased this or similar product', 'SUPER01'),
('Not Available', 'Customer was not available during the call attempt', 'SUPER01'),
('Do Not Call', 'Customer requested to be removed from calling list', 'SUPER01');

-- Verify the dispositions were created
SELECT COUNT(*) as total_tl_dispositions FROM team_leader_dispositions WHERE is_active = 1;