=== SQLite Object Cache ===
Author: Oliver Jones
Contributors: OllieJones
Tags: cache, sqlite, performance
Requires at least: 5.9
Requires PHP: 5.6
Tested up to: 6.1.1
Version: 1.0.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Github Plugin URI: https://github.com/OllieJones/sqlite-object-cache
Primary Branch: trunk
Text Domain: sqlite-object-cache
Domain Path: /languages/

A persistent object cache backend for the rest of us, powered by SQLite.

== Description ==

A [persistent object cache](https://developer.wordpress.org/reference/classes/wp_object_cache/#persistent-cache-plugins) helps your site perform well. This one uses the widely available [SQLite3](https://www.php.net/manual/en/book.sqlite3.php) extension to php. Many hosting services offer it. If your hosting service does not provide memcached or redis, you may be able to use this plugin instead and get the benefit of object caching.

[Caches](https://en.wikipedia.org/wiki/Cache_(computing)) are ubiquitous in computing, and WordPress has its own caching subsystem. Caches contain short-term copies of the results of expensive database lookups or computations, and allow software to use the copy rather than repeating the expensive operation. This plugin (like other object-caching plugins) extends WordPress's caching subsystem to save those short-term copies from page view to page view. WordPress's cache happens to be a [memoization](https://en.wikipedia.org/wiki/Cache_(computing)#Memoization) cache.

Without a persistent object cache, every WordPress page view must use your MariaDB or MySQL database server to retrieve everything about your site. When a user requests a page, WordPress starts from scratch and gets everything it needs from your database server. Only then can it deliver content to your user. With a persistent object cache, WordPress has immediate access to much of the information it needs. This lightens the load on your  database server and delivers content to your users faster.

Thanks to [Till Kr??ss](https://profiles.wordpress.org/tillkruess/). His [Redis Object Cache](https://wordpress.org/plugins/redis-cache/) plugin serves as a model for this one. And thanks to [Ari Stathopoulos](https://profiles.wordpress.org/aristath/) for reviewing this. (All defects are, of course, entirely the author's responsibility.)

== Installation ==

Installing "SQLite Object Cache" can be done either by searching for "SQLite Object Cache" via the "Plugins > Add New" screen in your WordPress dashboard, or by using the following steps:

1. Download the plugin via WordPress.org
1. Upload the ZIP file through the 'Plugins > Add New > Upload' screen in your WordPress dashboard
1. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ===

= Does this work with a multisite WordPress installation? =

**Yes**.  To see the Settings page, choose Settings > Object Cache from the first site, or any site, in the multisite installation.

= How much faster will this make my site? =

Exactly predicting each site's speedup is not possible. Still, benchmarking results are promising. Please see [this](https://www.plumislandmedia.net/wordpress-plugins/sqlite-object-cache/benchmarks/). If you run a benchmark, please let the author know by leaving a comment on that page or using the [support forum](https://wordpress.org/support/plugin/sqlite-object-cache/).

= What is SQLite? =

[SQLite](https://www.sqlite.org/about.html) is fast and efficient database software. It doesn't require a separate server. Instead, it is built into php using the [SQLite3](https://www.php.net/manual/en/book.sqlite3.php) extension. SQLite programs don't need to open network connections to send requests and wait for replies.

= Does this plugin replace MariaDB or MySQL with SQLite? =

**No.**  Your MariaDB or MySQL database sql server still holds all your content. All your site's imports, exports, backups and other database operations continue to function normally.  This plugin uses SQLite simply to hold named values. For example, a value named "post|3" will hold a temporary, easy-to-retrieve cached copy of post number 3. When it needs that post, WordPress can fetch it quickly from SQLite.

= Wait, what? Do I really need two different kinds of SQL database? =

No, you don't. This plugin doesn't use SQLite as a full-fledged database server.

A persistent object cache needs some kind of storage mechanism. SQLite serves this plugin as a fast and simple key / value storage mechanism.

Some hosting providers offer scalable high-performance [redis](https://redis.io/) cache servers. If your provider offers redis, it is a good choice. You can use it via [Redis Object Cache](https://wordpress.org/plugins/redis-cache/) plugin. Sites using redis have one SQL database and another non-SQL storage scheme: redis. Other hosting providers offer [memcached](https://memcached.org/), which has the [Memcached Object Cache](https://wordpress.org/plugins/memcached/).

But many hosting providers don't offer either redis or memcached, while they do offer SQLite. This plugin enables your site to use a persistent object cache even without a separate cache server.

= Is this plugin compatible with my version of MySQL or MariaDB? =

**Yes**. It does not require any specific database server version.

= Is this plugin compatible with my version of redis or memcached? =

Please **do not use** this plugin if you have access to redis or memcached. Instead, use the [Redis Object Cache](https://wordpress.org/plugins/redis-cache/) or [Memcached Object Cache](https://wordpress.org/plugins/memcached/) plugin.

= Why not use the site's main MariaDB or MySql database server for the object cache? =

In WordPress, as in many web frameworks, your database server is a performance bottleneck. Using some other mechanism for the object cache avoids adding to your database workload. Web servers serve pages using multiple php processes, and each process handles its own SQLite workload while updating a shared database file. That spreads the object-cache workload out over many processes rather than centralizing it.

= Do I have to back up the data in SQLite? =

**No.** It's a cache, and everything in it is ephemeral. When WordPress cannot find what it needs in the cache, it simply recomputes it or refetches it from the database.

= If I already have another persistent object cache, can I use this one? =

**No.** You only need one persistent object cache, and WordPress only supports one.

= If I operate a scaled-up load-balanced installation, can I use this? =

**No.** If you have more than one web server this doesn't work correctly. If you operate at that scale, use redis or some other cache server. (If you aren't sure whether you have a load-balanced installation, you almost certainly do not.)

= Can I use this with the Performance Lab plugin? =

**Yes, but** you must *activate this plugin first* before you activate Performance Lab. And, you must deactivate Performance Lab before *deactivating this plugin last*.

The [Performance Lab plugin](https://wordpress.org/plugins/performance-lab/) offers some advanced and experimental ways of making your site faster. One of its features uses object-cache initialization code to start tracking performance. So there's a required order of activation if you want both to work.

= How does this work? =

This plugin uses a [WordPress drop-in](https://developer.wordpress.org/reference/functions/get_dropins/) to extend the functionality of the WP_Cache class. When you activate the plugin it creates the dropin file `.../wp-content/object-cache.php`. Upon deactivation, it removes that file and the cached data.

= Where does the plugin store the cached data? =

It's in your site's `wp_content` directory, in the file named `.ht.object-cache.sqlite`. That file's name has the `.ht.` prefix to prevent your web server from allowing it to be downloaded. SQLite also sometimes uses the files named `.ht.object-cache.sqlite-shm` and `.ht.object-cache.sqlite-wal`, so you may see any of those files.

On Linux and other UNIX-derived operating systems, you must give the command `ls -a` to see files when their names begin with a dot.

If you define the constant `WP_SQLITE_OBJECT_CACHE_DB_FILE` in `wp_config.php` the plugin uses that for the file name instead. For example, if `wp_config.php` contains this line

`define( 'WP_SQLITE_OBJECT_CACHE_DB_FILE', '/var/tmp/mysite-object-cache.sqlite' );`

your object cache data goes into the `/var/tmp` folder in a file named `mysite-object-cache.sqlite`.

= Is there a joke somewhere in this? =

Q: What are the two hardest things to get right in computer science?

1. Caching things.
2. Naming things.
3. Coping with off-by-one errors.

Seriously, the core of WordPress has already worked out, over years of development and millions of sites, how to cache things and name them. This plugin simply extends that mechanism to make those things persistent.

= I have another question =

Please look for more questions and answers [here](https://www.plumislandmedia.net/wordpress-plugins/sqlite-object-cache/faq/). Or ask your question in the [support forum](https://wordpress.org/support/plugin/sqlite-object-cache/faq/).

== Screenshots ==

1. Settings panel. Access it with Settings > Object Cache.
2. Performance statistics panel.


== Changelog ==

= 1.0.0 =

1. Use `.ht.object-cache.sqlite` for cached data to prevent downloading it via the web server.
2. Add support for the `WP_SQLITE_OBJECT_CACHE_DB_FILE` constant.
3. It's possible for the sqlite cache file to become corrupt if a server process crashes. When detecting that kind of situation, the plugin now deletes and rebuilds it.

= 0.1.7 = First release

== Upgrade Notice ==

This release fixes a security vulnerability and adds support for the `WP_SQLITE_OBJECT_CACHE_DB_FILE` constant.
