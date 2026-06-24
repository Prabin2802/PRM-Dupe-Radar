=== PRM Dupe Radar ===
Contributors: prm
Tags: duplicates, custom post types, admin, content audit
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find duplicate posts across all public custom post types with a detailed admin report.

== Description ==

PRM Dupe Radar scans your WordPress site for duplicate content and presents the results in a grouped admin interface.

Each duplicate set shows:

* Post ID
* Name (title and slug)
* Post type
* Status
* Categories
* All assigned taxonomies
* Published date
* Author name
* Edit and View links

Match duplicates by title, slug, or title + slug. Filter by post type or scan all public CPTs at once.

== Installation ==

1. Upload the `prm-dupe-radar` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open **PRM Dupe Radar** in the admin menu and click **Run Radar Scan**.

== Frequently Asked Questions ==

= What counts as a duplicate? =

By default, posts with the same title (case-insensitive) are grouped together. You can also match by slug or by both title and slug.

= Which post types are scanned? =

All public post types except attachments, revisions, and other internal WordPress types.

== Changelog ==

= 1.0.0 =
* Initial release.
