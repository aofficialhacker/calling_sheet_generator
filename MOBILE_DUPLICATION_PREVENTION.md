# Mobile Duplication Prevention System

## Overview

This system prevents duplicate mobile numbers from being stored across the entire calling sheet generator system. When any admin uploads a batch containing mobile numbers that already exist in the system (from any admin), those duplicates are automatically rejected and excluded from the batch.

## Features

- **Universal Duplication Check**: Prevents duplicates across all admins system-wide
- **Real-time Filtering**: Duplicates are detected and excluded during batch upload
- **Detailed Reporting**: Shows count of duplicate numbers found and excluded
- **Performance Optimized**: Uses database indexes for fast duplicate checking
- **Non-breaking**: Existing functionality remains unchanged

## Installation

### 1. Database Setup
Run the database setup script once:
```
http://your-domain/setup_duplication_database.php
```

Or manually execute the SQL file:
```sql
mysql -u root -p123456 caller_sheet3 < setup_mobile_duplication_prevention.sql
```

### 2. File Dependencies
The following files are required:
- `mobile_duplication_utils.php` - Core utility functions
- `setup_mobile_duplication_prevention.sql` - Database setup script

### 3. Integration
The system is automatically integrated into:
- `upload_batch.php` - Batch upload process (modified)

## How It Works

### Upload Process Flow
1. Admin uploads Excel/CSV/Image file
2. System processes each row:
   - Validates mobile number format
   - Checks if number is in blocklist (existing feature)
   - **NEW**: Checks if number already exists in system
   - Excludes duplicates and continues with unique numbers only
3. Creates batch with unique numbers only
4. Shows summary with duplicate count

### Database Structure
- Uses existing `final_call_logs` table
- Adds optimized indexes on `mobile_no` column
- No schema changes required

## Usage Examples

### Success Messages
- **No duplicates**: "Batch ABC123B1 created successfully with 150 records."
- **With blocked numbers**: "Batch ABC123B2 created successfully with 140 records. (10 numbers were blocked and excluded)"
- **With duplicates**: "Batch ABC123B3 created successfully with 130 records. (20 duplicate numbers found and excluded)"
- **Both blocked and duplicates**: "Batch ABC123B4 created successfully with 120 records. (10 numbers were blocked, 20 duplicate numbers found and excluded)"

## API Functions

### Core Functions (`mobile_duplication_utils.php`)

#### `isMobileNumberDuplicate($mobile_no)`
Checks if a mobile number already exists in the system.
```php
$is_duplicate = isMobileNumberDuplicate('9876543210');
// Returns: true/false
```

#### `getMobileDuplicateDetails($mobile_no)`
Gets details about where a duplicate number exists.
```php
$details = getMobileDuplicateDetails('9876543210');
// Returns: array with batch_id, admin_id, filename, upload_date, etc.
```

#### `filterDuplicateMobileNumbers($mobile_array)`
Filters an array of mobile numbers to remove duplicates.
```php
$result = filterDuplicateMobileNumbers(['9876543210', '8765432109', '7654321098']);
// Returns: ['allowed' => [...], 'duplicates' => [...]]
```

#### `countDuplicateMobileNumbers($mobile_array)`
Counts how many numbers in array are duplicates.
```php
$count = countDuplicateMobileNumbers(['9876543210', '8765432109']);
// Returns: integer count
```

#### `cleanAndValidateMobile($mobile_no)`
Cleans and validates Indian mobile number format.
```php
$clean = cleanAndValidateMobile('+91-987-654-3210');
// Returns: '9876543210' or null if invalid
```

## Testing

### Test Script
Use the test script to verify functionality:
```
http://your-domain/test_mobile_duplication.php
```

### Test Scenarios
1. **Basic duplication check** - Test individual numbers
2. **Bulk filtering** - Test array of numbers
3. **System statistics** - View duplicate counts
4. **Number validation** - Test various formats
5. **Performance testing** - Large datasets

## Performance Considerations

### Database Indexes
The system adds these indexes for optimal performance:
- `idx_mobile_no_system_wide` on `final_call_logs(mobile_no)`
- `idx_mobile_batch` on `final_call_logs(mobile_no, batch_id)`
- `idx_upload_time` on `file_batches(upload_time)`

### Query Optimization
- Uses prepared statements for security
- Bulk operations for efficiency
- Minimal database connections

## Impact on Existing Features

### ✅ No Impact (Safe)
- PDF generation
- Call tracking and dispositions
- Team leader authentication
- Analytics and reporting
- Blocklist system
- Batch management
- User management

### ✅ Positive Impact
- Cleaner data quality
- Better telecaller productivity
- Accurate analytics
- Reduced storage usage
- No wasted calls on duplicates

### ⚠️ Behavior Changes
- **Upload Process**: May result in fewer records in batch if duplicates found
- **Success Messages**: Now includes duplicate count information
- **Data Integrity**: System-wide uniqueness enforced

## Monitoring and Maintenance

### Statistics Queries
```sql
-- View duplicate analysis
SELECT * FROM mobile_duplicate_analysis LIMIT 10;

-- Count total duplicates
SELECT COUNT(*) - COUNT(DISTINCT mobile_no) as duplicates FROM final_call_logs;

-- Top duplicate numbers
SELECT mobile_no, COUNT(*) as count 
FROM final_call_logs 
GROUP BY mobile_no 
HAVING count > 1 
ORDER BY count DESC;
```

### Maintenance Tasks
- Monitor system performance
- Clean up old duplicate analysis data
- Review duplicate statistics regularly
- Update indexes if needed

## Troubleshooting

### Common Issues

#### 1. Database Connection Errors
- Check database credentials in `db_config.php`
- Ensure MySQL service is running
- Verify database exists

#### 2. Performance Issues
- Check if indexes are properly created
- Monitor query execution times
- Consider database optimization

#### 3. False Positives
- Verify mobile number cleaning logic
- Check for formatting inconsistencies
- Review validation rules

### Debug Mode
Enable debug logging by modifying functions to log SQL queries and execution times.

## Security Considerations

- All functions use prepared statements
- Input validation on all mobile numbers
- Admin authentication required for setup/testing
- No direct SQL injection vulnerabilities

## Future Enhancements

### Potential Features
1. **Admin-specific duplicates**: Option to check duplicates per admin only
2. **Duplicate resolution**: Interface to manage existing duplicates
3. **Import logs**: Track what duplicates were found during imports
4. **Batch comparison**: Compare two batches for overlaps
5. **API endpoints**: REST API for external integrations

### Configuration Options
Consider adding settings for:
- Enable/disable system-wide vs admin-specific checking
- Duplicate handling strategies (reject/merge/flag)
- Performance tuning parameters

## Support

For issues or questions:
1. Check this documentation
2. Review test results from `test_mobile_duplication.php`
3. Check database setup status
4. Review application logs

---

**Version**: 1.0  
**Last Updated**: 2025-01-09  
**Compatible With**: Calling Sheet Generator v11