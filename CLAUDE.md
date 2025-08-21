# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Calling Sheet Generator** system - a comprehensive telecalling management platform built with PHP, MySQL, and Python. The system manages call centers with multiple user roles (superadmin, admin, team leaders, telecallers) and processes Excel data files for generating call sheets and tracking performance.

## Key Architecture Components

### Core User Roles & Access Levels
- **Superadmin**: System-wide management, creates admins and manages dispositions
- **Admin**: Manages batches, callers, and team leaders for their organization  
- **Team Leader**: Reviews "Interested" leads with advanced 2FA authentication
- **Telecaller**: Processes call data and marks dispositions

### Database Structure
- Uses MySQL database `caller_sheet3` with complex multi-table relationships
- Key tables: `admin_users`, `callers`, `file_batches`, `final_call_logs`, `team_leaders`, `team_leader_actions`
- All database access goes through `db_config.php` with centralized connection management

### Data Processing Pipeline
1. **Excel Upload**: Admins upload Excel files via `upload_batch.php`
2. **Python Processing**: `gemini_excel_parser.py` and `ocr_processor.py` handle data extraction using Google Gemini AI
3. **PDF Generation**: Multiple PDF generation methods using mPDF, TCPDF, and FPDF libraries
4. **Call Tracking**: Telecallers use generated sheets and update dispositions via dashboard

### Authentication & Security
- Role-based access control with session management
- Team Leader 2FA system with time-based authentication codes (5-minute windows)
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
```

### Python Scripts
```bash
# Install Python dependencies
pip install google-generativeai pillow opencv-python numpy

# Process uploaded files manually
python gemini_excel_parser.py [file_path]
python ocr_processor.py [image_path]
```

## Key File Locations

### Authentication & Access Control
- `db_config.php` - Database connection and role-based access functions
- `*_login.php` - Login pages for each user type
- `*_dashboard.php` - Role-specific dashboards

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

### Common Test Commands
```bash
# Test database connectivity
php -r "require 'db_config.php'; $conn = getDBConnection(); echo 'Connection successful';"

# Verify PDF generation
php test_pdf_no_auth.php

# Check batch processing
php debug_batch.php
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
- PDF generation includes multiple fallback methods
- Image preprocessing optimizes OCR accuracy while maintaining performance