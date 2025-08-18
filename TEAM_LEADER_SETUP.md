# Team Leader System Implementation

## Overview
The Team Leader Panel system has been successfully implemented with advanced security features and comprehensive functionality.

## Database Setup

### 1. Run SQL Setup
Execute the setup file to create required tables:
```sql
-- Run: setup_team_leader.sql
```

The following tables will be created:
- `team_leaders`: Stores team leader information
- `team_leader_logins`: Logs all login attempts with IP tracking
- `team_leader_dispositions`: Configurable dispositions for team leaders
- `team_leader_actions`: Records all actions taken by team leaders

### 2. Default Dispositions
The following default dispositions are automatically created:
- Not Interested
- Call Back Later
- Wrong Number
- No Response
- Busy
- Interested - Proceed to Payment
- Need More Information
- Already Purchased

## Security Features Implemented

### 1. Time-Based Authentication
- Primary username/password authentication
- Secondary time-based authentication code (changes every 5 minutes)
- Codes are generated using SHA-256 hash of leader ID + time window
- 5-minute tolerance window for clock drift

### 2. Account Security
- Maximum 5 failed login attempts per hour
- Account lockout after failed attempts
- IP address logging for all login attempts
- Session timeout after inactivity

### 3. Access Control
- Team leaders can only see leads from their admin's team
- Actions are logged with IP addresses
- Secure session management with proper logout

## User Flow

### 1. Admin Creates Team Leader
1. Admin logs into admin panel
2. Goes to "Team Leaders" menu
3. Selects a caller from dropdown
4. Sets username and password
5. System generates unique Team Leader ID (TL001, TL002, etc.)

### 2. Team Leader Login Process
1. Team leader visits `team_leader_login.php`
2. Enters username and password
3. System generates time-based authentication token
4. Team leader enters the displayed authentication code
5. On successful verification, gains access to dashboard

### 3. Team Leader Dashboard
1. View all "Interested" leads from their admin's team
2. See statistics: Total Interested, Pending Review, Payment Ready, Today Processed
3. Take actions on leads by selecting appropriate dispositions
4. View action history and performance

### 4. Disposition Management
1. Superadmin can manage team leader dispositions
2. Create, edit, activate/deactivate dispositions
3. View usage statistics for each disposition

## Files Created/Modified

### New Files
1. `setup_team_leader.sql` - Database setup
2. `manage_team_leaders.php` - Admin team leader management
3. `team_leader_login.php` - Secure login with 2FA
4. `team_leader_dashboard.php` - Main dashboard
5. `process_team_leader_action.php` - Action processing
6. `payment_request.php` - Payment form redirect
7. `team_leader_history.php` - Action history
8. `manage_tl_dispositions.php` - Superadmin disposition management
9. `superadmin_sidebar.php` - Superadmin navigation

### Modified Files
1. `db_config.php` - Added team leader authentication functions
2. `admin_sidebar.php` - Added Team Leaders menu
3. `logout.php` - Added team leader logout handling

## Access URLs

### For Admins
- Team Leader Management: `/manage_team_leaders.php`

### For Team Leaders
- Login: `/team_leader_login.php`
- Dashboard: `/team_leader_dashboard.php`
- History: `/team_leader_history.php`

### For Superadmins
- Disposition Management: `/manage_tl_dispositions.php`

## Security Recommendations

### 1. HTTPS Required
- Always use HTTPS in production
- Time-based codes are sensitive to interception

### 2. Session Security
- Configure secure session settings
- Use secure cookies
- Implement proper session timeout

### 3. Password Policy
- Enforce strong passwords for team leaders
- Regular password rotation recommended
- Consider implementing password complexity requirements

### 4. IP Filtering (Optional)
- Consider implementing IP whitelisting for team leaders
- Monitor login patterns for suspicious activity

## Monitoring and Maintenance

### 1. Login Monitoring
Check `team_leader_logins` table for:
- Failed login attempts
- Suspicious IP addresses
- Unusual login patterns

### 2. Performance Monitoring
Monitor `team_leader_actions` table for:
- Action frequency
- Disposition usage patterns
- Performance metrics

### 3. Regular Cleanup
- Archive old login logs
- Clean up expired authentication tokens
- Review and update dispositions

## Testing Checklist

### 1. Admin Functions
- [ ] Create team leader from caller list
- [ ] Deactivate/reactivate team leader
- [ ] View team leader statistics

### 2. Team Leader Login
- [ ] Username/password authentication
- [ ] Time-based code generation and verification
- [ ] Account lockout after failed attempts
- [ ] IP address logging

### 3. Dashboard Functions
- [ ] View interested leads
- [ ] Take actions on leads
- [ ] View statistics
- [ ] Access payment requests

### 4. Superadmin Functions
- [ ] Create/edit dispositions
- [ ] Activate/deactivate dispositions
- [ ] View usage statistics

### 5. Security Tests
- [ ] Session timeout works
- [ ] Logout clears session
- [ ] IP logging works
- [ ] Failed attempt lockout

## Support and Troubleshooting

### Common Issues
1. **Time-based codes not working**: Check server time synchronization
2. **Account locked**: Clear failed attempts or wait 1 hour
3. **Can't see leads**: Verify admin-caller mapping
4. **Payment link not working**: Check CIF form integration

### Database Queries for Debugging
```sql
-- Check team leader status
SELECT * FROM team_leaders WHERE username = 'username';

-- Check failed login attempts
SELECT * FROM team_leader_logins WHERE leader_id = 'TL001' AND login_status = 'failed';

-- Check actions taken
SELECT * FROM team_leader_actions WHERE leader_id = 'TL001';
```

## Integration Notes

### Payment Integration
The system redirects to `cif_form.php` for payment processing. Ensure this page exists and handles the following parameters:
- `lead_id`: The ID of the lead
- `source`: Set to 'team_leader'

### Future Enhancements
Consider implementing:
1. SMS/Email notifications for time-based codes
2. Mobile app for team leaders
3. Advanced reporting and analytics
4. Bulk action capabilities
5. Lead assignment algorithms