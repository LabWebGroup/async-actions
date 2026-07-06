=== Async Actions ===
Contributors: labweb
Tags: async, background, queue, jobs, cron
Requires at least: 5.3
Tested up to: 6.8 < 7.0
Stable tag: 1.0.8
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight background job queue and async task dispatcher for WordPress.

== Description ==

Async Actions provides two ways to run tasks in the background:

* **lab_async_dispatch()** — fires the task immediately in a non-blocking HTTP request (fire-and-forget).
* **lab_async_queue_dispatch()** — adds the task to a persistent database queue, processed by WP-Cron every 30 seconds (supports retries).

== Installation ==

1. Upload the `async-actions` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the "Plugins" menu in WordPress.

== Changelog ==

= 1.0.8 =
* Improvements and bug fixes.

= 1.0.0 =
* Initial release.

== License ==

Async Actions is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

Async Actions is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with Async Actions. If not, see https://www.gnu.org/licenses/.
