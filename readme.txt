=== WP Optimal State ===
Contributors: Luke Garrison
Tags: optimize, caching, performance, backup, clean, database, database-optimization, database-cleanup, wordpress-speed
Requires at least: 5.5
Tested up to: 6.8
Stable tag: 1.1.7
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

This is an advanced optimization and cleaning plugin: It cleans your database, optimizes your tables, backs up your database, and enables page caching for maximum performance.

== Description ==

WP Optimal State is a comprehensive database optimization and maintenance plugin designed to keep your WordPress website running at peak performance. Over time, WordPress databases accumulate unnecessary data such as post revisions, spam comments, orphaned metadata, and expired transients. This bloat can slow down your site and increase hosting costs.

WP Optimal State provides an intuitive interface to safely identify, review, and remove this unnecessary data while optimizing your database tables for maximum efficiency. Key features include one-click optimization, detailed cleanup options, advanced table optimization and repair, an integrated database backup and restore manager with enhanced safety features, a database health score, database structure analysis, an automated cleaning scheduler with email notifications, and a performance features manager to enable or disable WordPress core functions.

The plugin offers a range of performance-enhancing features in the fine-tuning section to speed up your site’s loading time. These include a dual caching system that relies upon both the server and the browser, along with the ability to remove unused WordPress components.

Essentially, WP Optimal State is three plugins in one:
* Database Cleanup and Optimization
* Database Backup and Restore
* Caching and Performance Tuning

**⚠️ Important:** This plugin is designed for single-site WordPress installations only. It is not compatible with WordPress Multisite networks.

**Key Features:**

* 🎯 One-click database optimization
* 💾 Secure database backup & restore system
* 📊 Database health score & recommendations
* 🔄 Automatic scheduled optimization
* ⚙️ Performance Features Manager
* 📧 Email notifications for scheduled tasks
* 🛡️ Safety backup before restore operations
* 🔍 Detailed database structure analysis
* 🧊 Efficient page caching + browser caching

Keep your WordPress database lean, fast, and healthy with WP Optimal State.

= Why Use WP Optimal State? =

Over time, WordPress databases accumulate unnecessary data like post revisions, spam comments, orphaned metadata, and expired transients. This bloat slows down queries, increases backup sizes, and degrades performance. WP Optimal State removes this clutter safely while providing integrated backup protection. Unlike other optimization plugins, it combines cleaning, analysis, backup, and restore in one secure solution with automated scheduling and email notifications.

== Installation ==

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

== Frequently Asked Questions ==

= What's the difference between the FREE and PRO versions? =

The FREE version includes basic optimization features like cleaning post revisions, spam comments, and expired transients. The PRO version adds advanced features including automated scheduling, extended backup management, autoload optimization, database repair tools, comprehensive caching options, and priority support. Try the FREE version first to experience the core functionality.

= How do I upgrade from FREE to PRO version? =

The upgrade process is straightforward. Simply uninstall the free version, then install the PRO version.

Follow these steps:
Generate and download a database backup (just in case).
From your dashboard, go to Plugins > Installed Plugins.
Find WP Optimal State FREE and deactivate it.
After deactivating it, click Delete.
Now, install the PRO version as usual.
Visit the plugin admin panel and reconfigure your settings and options.
If you don't feel confident enough, please purchase PRO Version + Installation and we'll handle everything for you.

= Is WP Optimal State safe to use? = 

Yes, when used correctly and cautiously.
Always create a backup before any operation (especially cleanup, restore, or changing performance features). Start with low-risk operations like "One-Click Optimization" or cleaning expired transients/spam. Understand what operations marked with ⚠️ do before running them. Test on a staging site if possible.

= What makes WP Optimal State different from other database optimization plugins? =

WP Optimal State combines database cleanup, performance optimization, and backup management in a single interface. It features intelligent safety mechanisms including automated backup creation, operation warnings, exclusion lists for critical data, and a safe restore process with atomic table swapping. The plugin provides detailed statistics before operations and maintains optimization logs for transparency.

= Will this plugin significantly speed up my website's front-end? = 

It primarily improves backend performance (admin dashboard speed, query times) and reduces database size. Front-end speed improvements are often secondary and depend on how database-intensive your theme/plugins are. You might see a modest front-end speed boost (e.g., 5-15%), but major front-end gains usually require caching, image optimization, and code optimization. WP Optimal State complements these efforts by ensuring the database itself is efficient.

= How much database space can I realistically expect to save? = 

This varies greatly depending on site age, content volume, plugin usage, and past maintenance:
Sites with many post revisions or years of accumulated spam/transients can see significant reductions (20-50%+).
Well-maintained or newer sites might see smaller reductions (5-15%).
The biggest savings usually come from cleaning revisions, spam, transients, and optimizing table overhead.
Focus on improved performance and efficiency rather than just size reduction.

== Screenshots ==

1. Database Backup and Restore
2. One-Click Optimization & Database Health Score
3. Database Statistics Panel
4. Database Cleanup Section
5. Advanced Database Optimization Section
6. Automatic Backup and Cleaning
7. Performance Features Manager

== Changelog ==

= 1.1.7 =
* New feature: Database search & replace. Ideal for updating the database after a migration.
* New user interface split into separate tabs for a smooth and easy browsing experience.
* In the Database Structure Analysis, added an option to delete database tables that haven't been used for over a month.
* Added new descriptions and recommendations to the admin interface to improve user experience.
* Expanded and updated the user manual, added an advanced search functionality and other minor improvements.

== External Services ==

This plugin uses the GTranslate widget to provide translation functionality within the plugin's admin interface.

**Service Details:**
* **Service Name:** GTranslate
* **Purpose:** Provides a translation widget in the plugin's admin settings page to allow administrators to translate the interface into different languages
* **Data Transmitted:** The widget is loaded from GTranslate's CDN (`https://cdn.gtranslate.net/`). It may detect the browser's language preference to suggest appropriate translations. No personal data from your WordPress database or website content is transmitted to GTranslate.
* **When Active:** The widget is only loaded on the plugin's admin pages when viewed by administrators
* **User Data:** No user data, database information, or site content is sent to external servers
* **Terms of Service:** https://gtranslate.io/terms
* **Privacy Policy:** https://gtranslate.io/privacy-policy

By using this plugin's admin interface, you acknowledge that the GTranslate widget may be loaded from their CDN for translation purposes. This service is optional and only affects the admin interface appearance.

== Support ==

For support, feature requests, or bug reports, please visit: [https://spiritualseek.com/wp-optimal-state-wordpress-plugin/](https://spiritualseek.com/wp-optimal-state-wordpress-plugin/)

Email: lukegarrison.dev@gmail.com

== Privacy Policy ==

WP Optimal State does not collect any user data or transmit any information to external servers. All operations are performed locally on your WordPress installation.

== Technical Details ==

= Minimum Requirements =

* WordPress 5.0 or higher
* WordPress Installation Type: Single-site only (Multisite not supported)
* PHP 7.4 or higher
* MySQL 5.6 or higher
* User Permission: Administrator access required
* File System: Writable `wp-content/uploads/` directory for backups, settings, and logs

= Recommended Environment =

* PHP: 8.0 or higher for optimal performance
* MySQL: 5.7+ or MariaDB 10.3+ for better table optimization
* PHP Memory Limit: 256M or higher (512M recommended for large databases)
* PHP Max Execution Time: 300 seconds or higher for large database operations
* Available Disk Space: 3-5x your database size for multiple backups

= When This Plugin May Not Be Suitable =

* Databases larger than 800MB (consider enterprise backup solutions)
* Sites requiring real-time backup (consider incremental backup solutions)
* Multi-server setups with load balancers (requires specialized backup strategies)
* Sites where database downtime absolutely cannot be tolerated (consider master-slave replication)

= Security Features =

* Nonce verification on all AJAX requests
* Capability checks (manage_options required)
* SQL injection protection via prepared statements
* XSS protection via output escaping
* CSRF protection
* Input validation and sanitization

== Credits ==

Developed by Luke Garrison (username "sparkerm" on WordPress.org) at The Spiritual Seek for the WordPress community.

Special thanks to all our beta testers and contributors who helped make this plugin better.

== Developer Notes ==

= Code Quality =

* Follows WordPress Coding Standards
* PSR-4 autoloading compatible structure
* Modular and extensible architecture

= Hooks & Filters =

* `optistate_before_cleanup_complete` - Action hook before cleanup completes
* `optistate_after_uninstall` - Action hook after plugin uninstallation

= Browser Compatibility =

* Chrome 90+
* Firefox 88+
* Safari 14+
* Edge 90+
* Opera 76+

= Performance =

* Lightweight footprint (~490KB total)
* Only loads on admin pages where needed
* Optimized database queries

* Efficient AJAX operations
