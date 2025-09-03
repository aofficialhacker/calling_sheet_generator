# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Calling Sheet Generator** system - a comprehensive telecalling management platform built with PHP, MySQL, and Python. The system manages call centers with multiple user roles (superadmin, admin, team leaders, telecallers) and processes Excel data files for generating call sheets and tracking performance.

## Key Architecture Components

### Core User Roles & Access Levels
- **Superadmin**: System-wide management, creates admins and manages dispositions
- **Admin**: Manages batches, callers, and team leaders for their organization  
- **Team Leader**: Reviews "Interested" leads with advanced 2FA authentication and secure data masking
- **Telecaller**: Processes call data and marks dispositions

### Database Structure
- Uses MySQL database `caller_sheet3` with complex multi-table relationships
- Key tables: `admin_users`, `callers`, `file_batches`, `final_call_logs`, `team_leaders`, `team_leader_actions`, `team_leader_view_logs`
- All database access goes through `db_config.php` with centralized connection management
- Database connection: localhost, user: root, password: 123456, database: caller_sheet3

### Data Processing Pipeline
1. **Excel Upload**: Admins upload Excel files via `upload_batch.php`
2. **Python Processing**: `gemini_excel_parser.py` and `ocr_processor.py` handle data extraction using Google Gemini AI
3. **PDF Generation**: Multiple PDF generation methods using mPDF, TCPDF, and FPDF libraries
4. **Call Tracking**: Telecallers use generated sheets and update dispositions via dashboard

### Authentication & Security
- Role-based access control with session management
- Team Leader 2FA system with time-based authentication codes (4-hour refresh cycles)
- Advanced security features including console detection, screen recording prevention
- Customer data masking/unmasking system for Team Leaders with time-limited access
- IP logging for all login attempts and account lockout after failed attempts
- Secure session handling across all user types

## Common Development Commands

### PHP Development
```bash
# Start local development server (if not using XAMPP)
php -S localhost:8000

# Composer dependency management
composer install
composer update
```

### Database Management
```bash
# Setup team leader system (run once)
mysql -u root -p123456 caller_sheet3 < setup_team_leader.sql

# Database optimization
mysql -u root -p123456 caller_sheet3 < optimize_database.sql

# Test database connectivity
php -r "require 'db_config.php'; $conn = getDBConnection(); echo 'Connection successful';"
```

### Python Scripts
```bash
# Install Python dependencies
pip install google-generativeai pillow opencv-python numpy

# Process uploaded files manually
python gemini_excel_parser.py [file_path]
python ocr_processor.py [image_path]
```

### Composer Dependencies
```bash
# Install PHP packages (defined in composer.json)
composer install
composer update

# Key packages: phpspreadsheet, mpdf, tcpdf, fpdf, tesseract_ocr
```

## Key File Locations

### Authentication & Access Control
- `db_config.php` - Database connection, role-based access functions, and timezone-aware access code management
- `*_login.php` - Login pages for each user type
- `*_dashboard.php` - Role-specific dashboards
- `masking_utils.php` - Customer data masking/unmasking utilities for Team Leaders
- `team_leader_auth_view.php` - Authentication endpoint for secure data viewing

### Data Processing
- `upload_batch.php` - Excel file upload handler
- `ajax_process_image.php` - AJAX endpoint for image processing
- `gemini_excel_parser.py` - Google Gemini AI Excel data extraction
- `ocr_processor.py` - OCR processing for scanned documents

### PDF Generation
- `generate_pdf.php` - Main PDF generation logic (multiple backup versions exist)
- Uses mPDF library as primary PDF generator
- Fallback to TCPDF and FPDF for compatibility

### Management Interfaces
- `manage_*.php` - Admin interfaces for managing batches, products, dispositions
- `*_sidebar.php` - Navigation components for each user role

## Development Environment Setup

### Database Configuration
- Host: localhost
- User: root  
- Password: 123456
- Database: caller_sheet3

### Required PHP Extensions
- mysqli (database connectivity)
- json (data processing)
- curl (external API calls)
- gd (image processing)

### Python Dependencies
- google-generativeai (Gemini AI integration)
- opencv-python (image preprocessing)
- pillow (image handling)
- numpy (array operations)

## Security Considerations

### Database Security
- All queries use prepared statements to prevent SQL injection
- User input sanitization throughout the application
- Role-based access validation on every protected page

### File Upload Security
- Uploaded files are validated for type and size
- Files stored in protected directories with generated names
- Image preprocessing before OCR to prevent malicious content

### Session Management
- Secure session configuration required for production
- Session timeout implementation
- Proper logout handling clears all session data

## Testing & Debugging

### Test Files Available
- `test_pdf_no_auth.php` - Test PDF generation without authentication
- `test_auth_simple.php` - Test team leader authentication without AJAX
- `test_view_ajax.php` - Test AJAX authentication for view functionality
- `check_timezone.php` - Debug timezone differences between PHP and MySQL
- `debug_*.php` - Various debugging utilities

### Common Test Commands
```bash
# Test database connectivity
php -r "require 'db_config.php'; $conn = getDBConnection(); echo 'Connection successful';"

# Verify PDF generation
php test_pdf_no_auth.php

# Test team leader authentication
php test_auth_simple.php

# Debug timezone issues
php check_timezone.php
```

### Log Files
- `php_server.log` - PHP error logging
- `pdf_debug.log` - PDF generation debugging
- `ocr_extraction_log.txt` - OCR processing logs

## Integration Points

### External APIs
- Google Gemini AI for intelligent data extraction
- Payment processing integration via `cif_form.php`

### File Processing
- Excel files processed through PhpSpreadsheet library
- Image processing via OpenCV and PIL
- Multi-format PDF generation support

## Performance Considerations

- Database queries optimized for large datasets
- File processing handled asynchronously where possible
- PDF generation includes multiple fallback methods (mPDF → TCPDF → FPDF)
- Image preprocessing optimizes OCR accuracy while maintaining performance
- Timezone calculations use database timestamps to avoid PHP/MySQL timezone mismatches
- Error handling includes automatic fallback methods for SQL query failures

## Team Leader Security Features

### Data Masking System
- Customer names and mobile numbers are masked by default in Team Leader interfaces
- Format: "ANTHONY DSOUZA" → "A*****Y D****A", "9876543210" → "98XXXXXX10"
- Unmasking requires re-authentication with team leader access codes
- Time-limited visibility (1-minute timeout) with countdown timer
- Only one entry can be unmasked at a time
- Complete audit trail of all view actions in `team_leader_view_logs` table

### Access Code Management
- 6-character alphanumeric codes refreshed every 4 hours
- Admin interface for monitoring code expiry and team leader activity
- Database-driven time calculations prevent timezone-related issues
- Rate limiting and failed attempt logging for security

## Development Notes

- Always use prepared statements for database queries to prevent SQL injection
- File uploads should be validated for type, size, and content
- When working with time-sensitive features, use database timestamps for consistency
- Test authentication flows with dedicated test pages before implementing in production
- Error handling should include both logging and graceful fallback methods

## Recent File Structure Updates

### New Files Added to System
- Multiple call analytics and performance tracking files (`admin_call_analytics.php`, `caller_performance.php`, `improved_caller_performance.php`)
- Data preservation and backup systems (`emergency_data_backup.php`, `enhanced_data_preservation.php`, `implement_complete_preservation.php`)
- Database migration and fixing utilities (`fix_collation_and_create_tables.php`, `migrate_call_history.php`)
- System status monitoring (`system_status_summary.php`)
- Complete preservation testing suite (`test_complete_preservation.php`, `test_complete_system.php`, `simple_preservation_test.php`)

### Modified Core Files (Git Status)
- `admin_sidebar.php` - Navigation updates
- `caller_panel.php` - Panel modifications  
- `generate_pdf.php` - PDF generation improvements
- `manage_batches.php` - Batch management enhancements
- `manage_dispositions.php` and `manage_tl_dispositions.php` - Disposition handling updates
- `save_final_log.php` - Call logging modifications (multiple backup versions exist)

### Call History System Enhancement
- New SQL schema: `setup_call_history_system.sql`
- Session management improvements with multi-device login blocking
- Enhanced data preservation across the entire call tracking pipeline