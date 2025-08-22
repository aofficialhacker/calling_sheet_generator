# Project Overview

This project is a comprehensive, web-based **Calling Sheet Generator and Campaign Management System**. Built primarily with **PHP** and a **MySQL** database, it empowers businesses to streamline their telemarketing and sales operations.

The system is designed with a multi-user architecture, providing distinct roles and permissions for:

*   **Superadmins:** Have complete control over the system, including managing admins, products, and system-wide settings.
*   **Admins:** Can manage telecallers, upload customer data, generate calling sheets, and monitor team performance through a detailed analytics dashboard.
*   **Telecallers:** Can access their assigned calling sheets, log call dispositions, and track their individual performance.
*   **Team Leaders:** Can review leads, manage follow-up actions, and handle payment requests.

## Core Features

*   **Dynamic PDF Calling Sheet Generation:** The heart of the application is its ability to generate highly customized PDF calling sheets. It leverages the **TCPDF** and **mPDF** libraries to create professional, well-formatted documents tailored to specific campaigns, products, or call dispositions. The system intelligently selects relevant data columns and processes large datasets efficiently using chunked processing to prevent performance bottlenecks.
*   **Advanced Analytics Dashboard:** The admin dashboard provides a rich, interactive interface for monitoring campaign performance in real-time. It features a variety of charts and graphs, powered by **Chart.js**, to visualize key metrics such as:
    *   Call connectivity and conversion rates
    *   Call disposition breakdowns
    *   Telecaller performance leaderboards
    *   Time-slot analysis to identify peak calling times
    *   Performance insights by business unit
*   **Data Management:** The application supports importing customer data from Excel spreadsheets using the **PhpSpreadsheet** library. It also includes a robust system for managing call logs, dispositions, and user data.
*   **OCR Integration:** The system incorporates Optical Character Recognition (OCR) capabilities using the **Tesseract OCR** library, allowing it to process scanned documents and extract relevant data automatically.

## Key Technologies

*   **Backend:** PHP
*   **Database:** MySQL
*   **Frontend:** HTML, CSS, JavaScript, Bootstrap, Chart.js
*   **PHP Libraries:**
    *   [TCPDF](https://tcpdf.org/): For generating PDF documents.
    *   [mPDF](https://mpdf.github.io/): An alternative PDF generation library.
    *   [PhpSpreadsheet](https://phpspreadsheet.readthedocs.io/): For reading and writing spreadsheet files.
    *   [Tesseract OCR](https://github.com/thiagoalessio/tesseract-ocr-for-php): For Optical Character Recognition.
*   **Dependency Management:** Composer

# Building and Running

This is a standard PHP web application. To run it, you will need a web server (like Apache or Nginx) with PHP and a MySQL database.

1.  **Web Server:**
    *   Place the project files in the web root of your server (e.g., `/var/www/html` or `C:\xampp\htdocs`).
2.  **Database:**
    *   Import the database schema and any necessary data from the provided SQL files (e.g., `add_default_tl_dispositions.sql`, `setup_team_leader.sql`, `update_team_leader_actions_table.sql`, `update_team_leaders_table.sql`).
    *   Configure the database connection in `db_config.php`.
3.  **Dependencies:**
    *   Run `composer install` to download the required PHP libraries.
4.  **Access:**
    *   Open the application in your web browser.

# Development Conventions

*   **Database Interaction:** The application uses prepared statements to prevent SQL injection vulnerabilities.
*   **Error Handling:** The code includes error logging and debugging features to help identify and resolve issues.
*   **Code Style:** The code is generally well-structured and follows standard PHP conventions.
*   **Modularity:** The application is divided into multiple files based on functionality, which makes it easier to maintain and extend.
