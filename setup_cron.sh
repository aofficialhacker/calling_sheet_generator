#!/bin/bash

echo "Setting up Admin Follow-up Processor Cron Job..."

# Create the cron job entry
CRON_JOB="0 * * * * /usr/bin/php /var/www/html/calling_sheet_generator11/admin_follow_up_processor.php >> /var/log/admin_followup.log 2>&1"

# Add to crontab
(crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -

echo "Cron job added successfully!"
echo "The processor will run every hour at the top of the hour"
echo ""
echo "To verify the cron job was added:"
echo "crontab -l | grep admin_follow_up_processor"
echo ""
echo "To view logs:"
echo "tail -f /var/log/admin_followup.log"
echo ""
echo "To remove the cron job:"
echo "crontab -e"
echo "Then delete the line with admin_follow_up_processor.php"