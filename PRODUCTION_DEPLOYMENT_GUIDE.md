# 🚀 Production Deployment Guide

This guide will help you securely deploy the Calling Sheet Generator system to a production environment.

## 🔒 Critical Security Updates Applied

### ✅ Completed Security Fixes:
- **API Key Security**: Moved all hardcoded API keys to environment variables
- **Database Security**: Replaced hardcoded credentials with environment-based configuration  
- **Password Security**: Implemented proper password hashing with backwards compatibility
- **Session Security**: Added secure session configuration with HTTPOnly, Secure, SameSite flags
- **HTTPS Enforcement**: Automatic HTTPS redirect and security headers
- **Error Handling**: Production-safe error reporting (no sensitive data exposure)
- **File Security**: Comprehensive .gitignore and file upload validation
- **Rate Limiting**: Built-in protection against brute force attacks

## 📋 Pre-Deployment Checklist

### 1. Environment Configuration
```bash
# Copy environment template
cp .env.example .env

# Edit .env with your production values
nano .env
```

**Required Environment Variables:**
```env
# Database (Use strong credentials!)
DB_HOST=your_db_host
DB_USER=your_secure_db_user  
DB_PASS=your_very_secure_password
DB_NAME=caller_sheet3

# API Keys (Get from Google Cloud Console)
GEMINI_API_KEY=your_primary_api_key
GEMINI_API_KEY_1=your_first_api_key
GEMINI_API_KEY_2=your_second_api_key
GEMINI_API_KEY_3=your_third_api_key

# Production Settings
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# Session Security
SESSION_SECURE=true
SESSION_HTTPONLY=true
SESSION_SAMESITE=Strict
```

### 2. Database Setup
```bash
# Run production database setup
mysql -u root -p < setup_production_database.sql

# Create dedicated database user (recommended)
mysql -u root -p -e "
CREATE USER 'callflow_app'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON caller_sheet3.* TO 'callflow_app'@'localhost';
FLUSH PRIVILEGES;
"
```

### 3. Web Server Configuration

#### Apache (.htaccess)
```apache
# Force HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Security Headers
Header always set X-Frame-Options "DENY"
Header always set X-Content-Type-Options "nosniff"
Header always set X-XSS-Protection "1; mode=block"
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"

# Hide sensitive files
<FilesMatch "^\.env">
    Order allow,deny
    Deny from all
</FilesMatch>

<FilesMatch "\.(log|sql|md)$">
    Order allow,deny
    Deny from all
</FilesMatch>
```

#### Nginx
```nginx
server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com;
    
    # SSL Configuration
    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;
    
    # Security Headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
    
    root /var/www/calling_sheet_generator;
    index index.php;
    
    # Hide sensitive files
    location ~ /\. {
        deny all;
    }
    
    location ~* \.(log|sql|md)$ {
        deny all;
    }
    
    # PHP handling
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## 🔥 Deployment Process

### Automated Deployment (Recommended)
```bash
# Run deployment script
php deploy_production.php

# Or via web interface (with confirmation)
https://yourdomain.com/deploy_production.php
```

### Manual Deployment Steps

1. **Backup Current Installation**
```bash
tar -czf backup_$(date +%Y%m%d_%H%M%S).tar.gz /path/to/current/installation
```

2. **Set Environment Variables**
```bash
# Verify .env file exists and is properly configured
cat .env
```

3. **Remove Development Files**
```bash
# Remove debug files
rm debug_*.php *_debug.php

# Remove test files  
rm test_*.php *_test.php

# Remove documentation
rm *.txt *.md *.docx
```

4. **Set Proper Permissions**
```bash
# Application files
chmod 644 *.php
chmod 600 .env
chmod 755 index.php

# Directories
chmod 750 logs/
chmod 750 uploads/ 
chmod 755 vendor/
```

5. **Database Migration**
```bash
mysql -u your_user -p your_database < setup_production_database.sql
```

## 🛡️ Post-Deployment Security Checklist

### Immediate Actions:
- [ ] **Change Default Passwords**: Update all default superadmin passwords
- [ ] **Test HTTPS**: Verify SSL certificate and HTTPS redirect
- [ ] **Verify Environment**: Confirm APP_ENV=production 
- [ ] **Check Error Logging**: Ensure errors go to log files, not display
- [ ] **Test Authentication**: Verify all user types can login securely
- [ ] **API Key Rotation**: Generate new API keys and rotate old ones

### Security Monitoring:
- [ ] **Log Monitoring**: Set up monitoring for `/logs/` directory
- [ ] **Failed Logins**: Monitor `security_events` table for suspicious activity
- [ ] **Rate Limiting**: Test login rate limiting works
- [ ] **Session Security**: Verify sessions expire properly
- [ ] **File Upload Security**: Test upload restrictions

## 📊 Production Monitoring

### Database Health Checks
```sql
-- Check system status
SELECT * FROM security_events WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- Monitor failed login attempts
SELECT user_type, user_id, COUNT(*) as failed_attempts 
FROM security_events 
WHERE event_type = 'login_attempt' AND success = FALSE 
AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY user_type, user_id;

-- Check active sessions
SELECT user_type, COUNT(*) as active_sessions 
FROM active_sessions 
WHERE expires_at > NOW() 
GROUP BY user_type;
```

### Log File Monitoring
```bash
# Monitor error logs
tail -f logs/php_errors.log

# Monitor security events
tail -f logs/security.log

# Monitor deployment logs
tail -f logs/deployment.log
```

## 🚨 Emergency Procedures

### Security Incident Response
1. **Immediate Actions:**
   - Disable affected user accounts
   - Change API keys and database passwords
   - Check `security_events` table for breach indicators
   - Enable maintenance mode if needed

2. **Investigation:**
   - Review access logs and security events
   - Check for unauthorized file modifications
   - Verify database integrity

3. **Recovery:**
   - Restore from clean backup if compromised
   - Update all credentials
   - Apply additional security measures

### Rollback Procedure
```bash
# Restore from backup
tar -xzf backup_YYYYMMDD_HHMMSS.tar.gz -C /path/to/installation/

# Restore database
mysql -u your_user -p your_database < backup_database.sql

# Verify functionality
php -l index.php
```

## ⚠️ Important Reminders

1. **Never commit .env to version control**
2. **Regularly rotate API keys and passwords**
3. **Keep backups of production data**
4. **Monitor logs for security events**
5. **Update dependencies regularly**
6. **Test security configurations after updates**

## 📞 Support Information

- **Security Issues**: Contact system administrator immediately
- **API Limits**: Monitor Google Gemini API quotas
- **Database Issues**: Check MySQL error logs and connection limits
- **Performance**: Monitor server resources and optimize queries

---

**⚠️ CRITICAL**: This system now handles sensitive customer data. Ensure compliance with applicable privacy laws (GDPR, CCPA, etc.) and implement appropriate data retention policies.