== INSTALLATION ==

= Method 1 - Manual Upload =
1. Download the plugin ZIP file (optistate-main.zip)
2. In WordPress: Go to Plugins → Add New → Upload Plugin
3. Choose the ZIP file and click Install Now
4. Click Activate after installation
5. Look for "Optimal State" in you admin menu

= Method 2 - FTP Upload =
1. Download the plugin ZIP file (optistate-main.zip)
2. Extract the ZIP file
3. Upload the 'optistate-main' folder to /wp-content/plugins/
4. Activate through Plugins menu in WordPress
5. Look for "Optimal State" in you admin menu

================================================
================================================

=== WP Optimal State ===
Developer: Luke Garrison
Tags: optimize, caching, performance, backup, clean, database, database-optimization, database-cleanup, wordpress-speed
Requires at least: 5.5
Tested up to: 6.9
Stable tag: 1.1.7
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==

WP Optimal State is a comprehensive database optimization and maintenance plugin designed to keep your WordPress website running at peak performance. Over time, WordPress databases accumulate unnecessary data such as post revisions, spam comments, orphaned metadata, and expired transients. This bloat can slow down your site and increase hosting costs.

WP Optimal State provides an intuitive interface to safely identify, review, and remove this unnecessary data while optimizing your database tables for maximum efficiency. Key features include one-click optimization, detailed cleanup options, advanced table optimization and repair, an integrated database backup and restore manager with enhanced safety features, a database health score, database structure analysis, an automated cleaning scheduler with email notifications, and a performance features manager to enable or disable WordPress core functions.

The plugin offers a range of performance-enhancing features in the fine-tuning section to speed up your site’s loading time. These include a dual caching system that relies upon both the server and the browser, along with the ability to remove unused WordPress components.

Essentially, WP Optimal State Pro is four plugins in one:
1. Database Cleanup and Optimization
2. Database Backup and Restore
3. Database Search & Replace
4. Caching and Performance Tuning

It can easily replace the following plugins, saving you a considerable amount of money:
UpdraftPlus, WP Database Backup, WP Rocket, WP Super Cache, WP-Optimize, Better Search Replace, Heartbeat Control, Perfmatters, WP Revisions Control, Clearfy, Advanced Database Cleaner.

====================================================================================================
⚠️ Important:*This plugin is designed for single-site WordPress installations only. It is not compatible with WordPress Multisite networks.
====================================================================================================

Key Features:
🎯 One-click database optimization
💾 Secure database backup & restore system
📊 Database health score & recommendations
🔄 Automatic scheduled optimization
⚙️ Performance Features Manager
📧 Email notifications for scheduled tasks
🛡️ Safety backup before restore operations
🔍 Detailed database structure analysis
🧊 Efficient page caching + browser caching

Full Features List - Free vs. Pro
Database Backup & Restore
Create Database Backups
Maximum Backups to Keep - Free: 1 • Pro: 10
Download Backups
Restore from Existing Backups
Restore Database from Uploaded File - Free: ✗ No
Backup Verification (Checksum)
Database Cleanup & Optimization
One-Click Optimization
Database Health Score
Database Statistics
Detailed Database Cleanup (18 types)
Optimize All Tables 
Analyze & Repair Tables - Free: ✗ No
Optimize Autoloaded Options - Free: ✗ No
Database Structure Analysis
Database Search & Replace - Free: ✗ No
Delete Unused Tables - Free: ✗ No
Automation Features
Automatic Backup and Cleaning (Scheduled Tasks) - Free: ✗ No
Email Notifications for Scheduled Tasks - Free: ✗ No
Customizable Schedule (Every X Days at Specific Time) - Free: ✗ No
Performance Features
Server-Side Page Caching
Browser Caching (.htaccess Rules)
Cache Purging
Cache Statistics
Automatic Cache Preload (Sitemap-Based) - Free: ✗ No
Mobile-Specific Cache - Free: ✗ No
Custom Consent Cookie Support - Free: ✗ No
Query String Handling Modes (3 Options)
Smart Cache Invalidation on Content Updates
Database Query Caching - Free: ✗ No
Lazy Load Images & Iframes
Post Revisions Limit Control
Trash Auto-Empty Control
Heartbeat API Control
Disable XML-RPC
Remove Emoji Scripts
Remove Unused WordPress Headers
Integrated PageSpeed Metrics
Security & Safety
Automatic Safety Backup Before Restore
Emergency Rollback on Restore Failure
Temporary Table Swap (Zero-Downtime Restore)
Database Validation Before Restore
Maintenance Mode During Restore
Protected Backup Directory (.htaccess)
User Management (Restrict Access) - Free: ✗ No
Settings Export & Import
Logging & Monitoring
Optimization History Log (Last 80 Operations)
Detailed Operation Results
Real-Time Progress Tracking
Support & Documentation
Comprehensive Plugin Manual
Multi-Language Interface Support
In-Dashboard Help & Tooltips

== Changelog ==

= 1.1.7 =
New feature: Database search & replace. Ideal for updating the database after a migration.
New user interface split into separate tabs for a smooth and easy browsing experience.
In the Database Structure Analysis, added an option to delete database tables that haven't been used for over a month.
Added new descriptions and recommendations to the admin interface to improve user experience.
Expanded and updated the user manual, added an advanced search functionality and other minor improvements.

== External Services ==

This plugin uses the GTranslate widget to provide translation functionality within the plugin's admin interface.

Service Details:
Service Name:*GTranslate
Purpose:*Provides a translation widget in the plugin's admin settings page to allow administrators to translate the interface into different languages
Data Transmitted:*The widget is loaded from GTranslate's CDN (`https://cdn.gtranslate.net/`). It may detect the browser's language preference to suggest appropriate translations. No personal data from your WordPress database or website content is transmitted to GTranslate.
When Active:*The widget is only loaded on the plugin's admin pages when viewed by administrators
User Data:*No user data, database information, or site content is sent to external servers
Terms of Service:*https://gtranslate.io/terms
Privacy Policy:*https://gtranslate.io/privacy-policy

By using this plugin's admin interface, you acknowledge that the GTranslate widget may be loaded from their CDN for translation purposes. This service is optional and only affects the admin interface appearance.

== Support ==

For support, feature requests, or bug reports, please visit: https://payhip.com/optistate/contact
Email: lukegarrison.dev@gmail.com

== Privacy Policy ==

WP Optimal State does not collect any user data or transmit any information to external servers. All operations are performed locally on your WordPress installation.

== Technical Details ==

= Minimum Requirements =

WordPress 5.5 or higher
WordPress Installation Type: Single-site only (Multisite not supported)
PHP 7.4 or higher
MySQL 5.6 or higher
User Permission: Administrator access required
File System: Writable `wp-content/uploads/` directory for backups, settings, and logs

= Recommended Environment =

PHP: 8.0 or higher for optimal performance
MySQL: 5.7+ or MariaDB 10.3+ for better table optimization
PHP Memory Limit: 256M or higher (512M recommended for large databases)
PHP Max Execution Time: 300 seconds or higher for large database operations
Available Disk Space: 3-5x your database size for multiple backups

= When This Plugin May Not Be Suitable =

Databases larger than 5GB (consider enterprise backup solutions)
Sites requiring real-time backup (consider incremental backup solutions)
Multi-server setups with load balancers (requires specialized backup strategies)
Sites where database downtime absolutely cannot be tolerated (consider master-slave replication)

= Security Features =

Nonce verification on all AJAX requests
Capability checks (manage_options required)
SQL injection protection via prepared statements
XSS protection via output escaping
CSRF protection
Input validation and sanitization

= Browser Compatibility =

Chrome 90+
Firefox 88+
Safari 14+
Edge 90+
Opera 76+




