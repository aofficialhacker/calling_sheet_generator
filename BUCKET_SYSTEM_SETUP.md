# Bucket-Based Disposition System Setup Guide

## Overview

This document provides setup instructions for the new bucket-based disposition system with calendar functionality and automated follow-up notifications.

## Features Implemented

1. **Disposition Buckets Management** (Superadmin)
   - Create and manage disposition buckets
   - Enable/disable calendar functionality per bucket
   - View usage statistics

2. **Enhanced Disposition Management** (Superadmin)
   - Assign dispositions to specific buckets
   - Visual indicators for calendar-enabled dispositions

3. **Calendar-Enabled Dispositions** (Team Leaders)
   - Schedule follow-up dates and times
   - Real-time validation for future scheduling
   - Grouped disposition selection by buckets

4. **Follow-up Calendar Interface** (Team Leaders)
   - View all scheduled follow-ups
   - Filter by status and date
   - Update status, reschedule, or cancel follow-ups

5. **Real-time Notification System**
   - Browser notifications for due/overdue follow-ups
   - AJAX-based notification checking
   - Quick action capabilities from notifications

## Installation Steps

### 1. Database Setup

Run the database setup script:

```bash
mysql -u root -p123456 caller_sheet3 < setup_bucket_system.sql
```

This creates:
- `disposition_buckets` table
- `follow_up_schedules` table  
- `follow_up_notifications` table
- Updates `team_leader_dispositions` table with bucket relationships
- Creates triggers for automated notification scheduling
- Inserts default bucket categories

### 2. File Structure

The following files have been added/modified:

**New Files:**
- `manage_buckets.php` - Superadmin bucket management interface
- `follow_up_calendar.php` - Team Leader calendar interface
- `ajax_followup_notifications.php` - AJAX endpoint for notifications
- `notification_processor.php` - Background notification processor
- `js/followup-notifications.js` - Client-side notification handling
- `setup_bucket_system.sql` - Database schema setup
- `BUCKET_SYSTEM_SETUP.md` - This setup guide

**Modified Files:**
- `superadmin_sidebar.php` - Added Disposition Buckets navigation
- `manage_tl_dispositions.php` - Added bucket selection and display
- `team_leader_dashboard.php` - Enhanced with calendar functionality
- `process_team_leader_action.php` - Added follow-up scheduling logic

### 3. Notification System Setup

#### Option A: Cron Job (Recommended for Production)

Add this cron job to run every 5 minutes:

```bash
*/5 * * * * /usr/bin/php /path/to/calling_sheet_generator11/notification_processor.php >> /var/log/followup_notifications.log 2>&1
```

#### Option B: Manual Execution (Testing)

```bash
cd /path/to/calling_sheet_generator11
php notification_processor.php
```

#### Option C: Windows Task Scheduler

Create a scheduled task to run:
```
php.exe "C:\xampp\htdocs\calling_sheet_generator11\notification_processor.php"
```

### 4. Directory Permissions

Ensure proper permissions for the logs directory:

```bash
mkdir -p logs
chmod 755 logs
```

On Windows with XAMPP, the directory should be automatically writable.

### 5. Email Configuration (Optional)

To enable email notifications, modify the `sendEmailNotification()` function in `notification_processor.php`:

```php
function sendEmailNotification($to, $subject, $message) {
    // Example with PHPMailer
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'your-smtp-server.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'your-email@domain.com';
    $mail->Password = 'your-password';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    
    $mail->setFrom('system@yourcompany.com', 'Calling Sheet System');
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->Body = $message;
    
    return $mail->send();
}
```

## Usage Instructions

### For Superadmins

1. **Create Disposition Buckets:**
   - Navigate to "Disposition Buckets" in the sidebar
   - Click "Create New Bucket"
   - Enter bucket name and description
   - Enable calendar functionality if follow-ups are needed
   - Save the bucket

2. **Manage Team Leader Dispositions:**
   - Go to "Team Leader Dispositions"
   - Create new dispositions and assign them to appropriate buckets
   - Calendar-enabled buckets show a 📅 icon

### For Team Leaders

1. **Process Leads with Follow-ups:**
   - Open the Team Leader Dashboard
   - Click "Take Action" on an interested lead
   - Select a disposition from the grouped dropdown
   - If the disposition has a calendar icon, date/time fields will appear
   - Schedule the follow-up and submit

2. **Manage Follow-ups:**
   - Navigate to "Follow-up Calendar"
   - View all scheduled follow-ups with status indicators
   - Use filters to find specific follow-ups
   - Update status, reschedule, or cancel as needed

3. **Handle Notifications:**
   - Browser notifications appear for due/overdue follow-ups
   - Click the notification bell icon in the navigation
   - Use quick actions to complete or snooze follow-ups
   - Grant notification permissions when prompted

## System Architecture

### Database Schema

```
disposition_buckets
├── id (Primary Key)
├── bucket_name (Unique)
├── description
├── has_calendar_enabled
├── created_by
├── created_at
├── updated_at
└── is_active

team_leader_dispositions
├── ... (existing columns)
└── bucket_id (Foreign Key -> disposition_buckets.id)

follow_up_schedules
├── id (Primary Key)
├── schedule_id (Unique)
├── lead_id (Foreign Key)
├── leader_id
├── disposition_name
├── bucket_id (Foreign Key)
├── follow_up_datetime
├── status (scheduled|completed|cancelled|overdue)
├── remarks
├── created_at
└── updated_at

follow_up_notifications
├── id (Primary Key)
├── schedule_id (Foreign Key)
├── notification_type
├── scheduled_time
├── sent_at
├── status (pending|sent|failed)
├── next_attempt
├── attempt_count
└── created_at
```

### API Endpoints

- `ajax_followup_notifications.php?action=check_notifications` - Get pending notifications
- `ajax_followup_notifications.php?action=get_summary` - Get daily summary
- POST to `ajax_followup_notifications.php` with actions:
  - `quick_update_status` - Mark follow-up as completed/cancelled
  - `snooze_notification` - Snooze notification for specified minutes

### Security Considerations

- All AJAX endpoints validate team leader authentication
- Database queries use prepared statements
- Follow-up scheduling validates future datetime
- Notification permissions requested from user
- Input sanitization on all form submissions

## Troubleshooting

### Common Issues

1. **Notifications not appearing:**
   - Check browser notification permissions
   - Verify `ajax_followup_notifications.php` is accessible
   - Check browser console for JavaScript errors

2. **Follow-ups not being created:**
   - Verify database triggers are installed correctly
   - Check that bucket has `has_calendar_enabled = 1`
   - Ensure future datetime validation is passing

3. **Email notifications not working:**
   - Configure the `sendEmailNotification()` function
   - Check SMTP settings and credentials
   - Verify email addresses are correct in team_leaders table

4. **Cron job issues:**
   - Check cron job syntax and timing
   - Verify PHP path and permissions
   - Monitor log files for errors

### Log Files

- `logs/notification_processor.log` - Background notification processing
- PHP error logs - Check XAMPP/Apache logs for PHP errors
- Browser console - Client-side JavaScript errors

### Database Maintenance

The system includes automatic cleanup:
- Old notifications (30+ days) are automatically deleted
- Overdue follow-ups are marked as 'overdue' status
- Notification retry logic with 3 attempt limit

## Testing

1. **Create Test Buckets:**
   ```sql
   INSERT INTO disposition_buckets (bucket_name, description, has_calendar_enabled, created_by) 
   VALUES ('Test Follow Up', 'Testing calendar functionality', 1, 'SUPER');
   ```

2. **Create Test Disposition:**
   - Use Superadmin interface to create disposition in test bucket

3. **Schedule Test Follow-up:**
   - Use Team Leader dashboard to schedule a follow-up 5 minutes in the future

4. **Verify Notification System:**
   - Wait for notification to appear in browser
   - Check database for notification records
   - Test quick actions from notification dropdown

## Support

For issues or questions:
1. Check the troubleshooting section above
2. Review log files for error messages
3. Verify database integrity and relationships
4. Test with minimal data to isolate issues

## Version History

- v1.0 - Initial bucket system implementation
- v1.1 - Added real-time notifications
- v1.2 - Enhanced calendar interface with filtering
- v1.3 - Added notification snoozing and quick actions