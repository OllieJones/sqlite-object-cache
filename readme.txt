=== SQLite Object Cache ===
Author: Oliver Jones
Contributors: OllieJones
Tags: cache, sqlite, performance
Requires at least: 5.9
Requires PHP: 5.6
Tested up to: 6.1.1
Version: 0.1.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Github Plugin URI: https://github.com/OllieJones/sqlite-object-cache
Primary Branch: trunk
Text Domain: sqlite-object-cache
Domain Path: /languages/

A persistent object cache backend powered by SQLite.

== Description ==

A [persistent object cache](https://developer.wordpress.org/reference/classes/wp_object_cache/#persistent-cache-plugins) helps your site perform well. This one uses the widely available [SQLite3](https://www.php.net/manual/en/book.sqlite3.php) extension to php. Many hosting services offer it. If your hosting service does not provide memcached or redis, you may be able to use this plugin instead and get the benefit of object caching.

[Caches](https://en.wikipedia.org/wiki/Cache_(computing)) are ubiquitous in computing, and WordPress has its own caching subsystem. Caches contain short-term copies of the results of expensive database lookups or computations, and allow software to use the copy rather than repeating the expensive operation. This plugin (like other object-caching plugins) extends WordPress's caching subsystem to save those short-term copies from page view to page view. WordPress's cache happens to be a [memoization](https://en.wikipedia.org/wiki/Cache_(computing)#Memoization) cache.

Without a persistent object cache, every WordPress page view must use your MariaDB or MySQL database server to retrieve everything about your site. When a user requests a page, WordPress starts from scratch and gets everything it needs from your database server. Only then can it deliver content to your user. With a persistent object cache, WordPress has immediate access to much of the information it needs. This lightens the load on your  database server and delivers content to your users faster.

Thanks to [Till Krüss](https://profiles.wordpress.org/tillkruess/). His [Redis Object Cache](https://wordpress.org/plugins/redis-cache/) plugin serves as a model for this one. And thanks to [Ari Stathopoulos](https://profiles.wordpress.org/aristath/) for reviewing this. (All defects are, of course, entirely the author's responsibility.)

== Installation ==

Installing "SQLite Object Cache" can be done either by searching for "SQLite Object Cache" via the "Plugins > Add New" screen in your WordPress dashboard, or by using the following steps:

1. Download the plugin via WordPress.org
1. Upload the ZIP file through the 'Plugins > Add New > Upload' screen in your WordPress dashboard
1. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ===

= What is SQLite? =

[SQLite](https://www.sqlite.org/about.html) is fast and efficient database software. It doesn't require a separate server. Instead, it is built into php using the [SQLite3](https://www.php.net/manual/en/book.sqlite3.php) extension. SQLite programs don't need to open network connections to send requests and wait for replies.

= Does this plugin replace MariaDB or MySQL with SQLite? =

**No.**  Your MariaDB or MySQL database server still holds all your content. All your site's imports, exports, backups and other database operations continue to function normally.  This plugin uses SQLite simply to hold named values. For example, a value named "post|3" will hold a temporary, easy-to-retrieve cached copy of post number 3. When it needs that post, WordPress can fetch it quickly from SQLite.

= Wait, what? Do I really need two different kinds of SQL database? =

This plugin doesn't use SQLite as a full-fledged database server.

SQLite serves this plugin as a very simple key / value storage mechanism. A persistent object cache needs some storage mechanism. Some hosting providers offer scalable high-performance [redis](https://redis.io/) cache servers. If your provider offers that, it is a good choice. You can use it via [Redis Object Cache](https://wordpress.org/plugins/redis-cache/) plugin. Sites using redis have one SQL database and another non-SQL storage scheme: redis.

But many hosting providers don't offer redis or any other separate cache server, while they do offer SQLite. This plugin enables your site to use a persistent object cache even without a separate cache server.

= Why not use the MariaDB or MySql database server for the object cache? =

In WordPress, as in many web frameworks, your database server is a performance bottleneck. Using some other mechanism for the object cache avoids adding to your database workload. Web servers serve pages using multiple php processes, and each process handles its own SQLite workload while updating a shared database file. That spreads the object-cache workload out over many processes rather than centralizing it.

= Do I have to back up the data in SQLite? =

**No.** It's a cache, and everything in it is ephemeral. When WordPress cannot find what it needs in the cache, it simply recomputes it or refetches it from the database.

= If I already have another persistent object cache, can I use this one? =

**No.** You only need one persistent object cache, and WordPress only supports one.

= If I operate a scaled-up load-balanced installation, can I use this? =

**No.** If you have more than one web server this doesn't work correctly. If you operate at that scale, use redis or some other cache server. (If you aren't sure whether you have a load-balanced installation, you almost certainly do not.)

= How does this work? =

This plugin uses a WordPress drop-in to extend the functionality of the WP_Cache class.

= Is there a joke somewhere in this? =

Q: What are the two hardest things to get right in computer science?

1. Caching things.
2. Naming things.
3. Coping with off-by-one errors.

Seriously, the core of WordPress has already worked out, over years of development and millions of sites, how to cache things and name them. This plugin simply extends that mechanism to make those things persistent.

== Screenshots ==

1. Settings panel. Access it with Settings > Object Cache.
2. Performance statistics panel.


== Changelog ==

= 0.1.1 =
* 2022-11-19 Post Plugin Review

= 0.1.0 =
* 2022-11-17 Initial release

== Upgrade Notice ==

* Initial release
