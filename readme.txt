=== SQLite Object Cache ===
Author: Oliver Jones
Contributors: OllieJones
Tags: cache, object cache, sqlite, performance, database
Requires at least: 5.5
Requires PHP: 5.6
Tested up to: 6.7.1
Version: 1.4.1
Stable tag: 1.4.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Github Plugin URI: https://github.com/OllieJones/sqlite-object-cache
Primary Branch: trunk
Text Domain: sqlite-object-cache
Domain Path: /languages/

A fast persistent object cache backend for the rest of us, powered by SQLite.

== Description ==

A [persistent object cache](https://developer.wordpress.org/reference/classes/wp_object_cache/#persistent-cache-plugins) helps your site perform well. This one uses the widely available [SQLite3](https://www.php.net/manual/en/book.sqlite3.php) extension to php. Many hosting services offer it, and it's easy to install on a server you control.

[Caches](https://en.wikipedia.org/wiki/Cache_(computing)) are ubiquitous in computing, and WordPress has its own caching subsystem. Caches contain short-term copies of the results of expensive database lookups or computations, and allow software to use the copy rather than repeating the expensive operation. This plugin (like other object-caching plugins) extends WordPress's caching subsystem to save those short-term copies from page view to page view. WordPress's cache happens to be a [memoization](https://en.wikipedia.org/wiki/Cache_(computing)#Memoization) cache.

Without a persistent object cache, every WordPress page view must use your MariaDB or MySQL database server to retrieve everything about your site. When a user requests a page, WordPress starts from scratch and loads everything it needs from your database server. Only then can it deliver content to your user. With a persistent object cache, WordPress immediately loads much of the information it needs. This lightens the load on your  database server and delivers content to your users faster.

<h4>Who should use this?</h4>

If your site runs on a single web server machine, and that server provides the [SQLite3](https://www.php.net/manual/en/book.sqlite3.php) and [igbinary](https://www.php.net/manual/en/intro.igbinary.php) extensions to php, this plugin will almost certainly make your site work better.

Some hosting providers offer [redis](https://redis.io/) cache servers. If your provider offers redis, it may be a good choice. You can use it via tbe [Redis Object Cache](https://wordpress.org/plugins/redis-cache/) plugin. Sites using redis have one SQL database and another non-SQL storage scheme: redis. Other hosting providers offer [memcached](https://memcached.org/), which has the [Memcached Object Cache](https://wordpress.org/plugins/memcached/) plugin. And some large multipurpose cache plugins, such as the [LiteSpeed Cache](https://wordpress.org/plugins/litespeed-cache/), also offer object caching based on one of those cache server software packages.

The cache-server approach to object caching comes into its own when you have multiple load-balanced web server machines handling your site. SQLite doesn't work correctly in a multiple-web-server environment.

But, for single-server site configurations, SQLite performs well. And the vast majority of sites are single-server.

<h4>WP-CLI</h4>

You can control this plugin via WP-CLI. Please type this command into your shell for details.

`wp help sqlite-object-cache`

<h4>Credits</h4>

Thanks to [Till Krüss](https://profiles.wordpress.org/tillkruess/). His [Redis Object Cache](https://wordpress.org/plugins/redis-cache/) plugin serves as a model for this one. And thanks to [Ari Stathopoulos](https://profiles.wordpress.org/aristath/) and [Jonny Harris](https://profiles.wordpress.org/spacedmonkey/) for reviewing this. Props to Matt Jones for finding and fixing a bug that appeared on a heavily loaded system. All defects are, of course, entirely the author's responsibility.

And thanks to Jetbrains for the use of their software development tools, especially [PhpStorm](https://www.jetbrains.com/phpstorm/). It's hard to imagine how a plugin like this one could be developed without PhpStorm's tools for exploring epic code bases like WordPress's.

<h4>How can I learn more about making my WordPress site more efficient?</h4>

We offer several plugins to help with your site's database efficiency. You can [read about them here](https://www.plumislandmedia.net/wordpress/performance/optimizing-wordpress-database-servers/).

== Installation ==

Installing "SQLite Object Cache" can be done either by searching for "SQLite Object Cache" via the "Plugins > Add New" screen in your WordPress dashboard, or by using the following steps:

1. Download the plugin via WordPress.org
1. Upload the ZIP file through the 'Plugins > Add New > Upload' screen in your WordPress dashboard
1. Activate the plugin through the 'Plugins' menu in WordPress

Or, use these WP-CLI commands to install the plugin, activate it, and set the cache size to 32MiB.

```bash
wp plugin install sqlite-object-cache
wp plugin activate sqlite-object-cache
wp sqlite-object-cache size 32
```

The plugin offers optional settings for your `wp-config.php` file. If you change them, deactivate the plugin first, then change them, then reactivate the plugin.

1. WP_SQLITE_OBJECT_CACHE_DB_FILE. This is the SQLite file pathname. The default is …/wp-content/.ht.object_cache.sqlite. Use this if you want to place the SQLite cache file outside your document root.
1. WP_SQLITE_OBJECT_CACHE_TIMEOUT. This is the SQLite timeout in *milliseconds*. Default: 5000. (Notice that the times shown in the Statistics tab are in *microseconds* if you compare them to this timeout setting.)
1. WP_SQLITE_OBJECT_CACHE_JOURNAL_MODE This is the [SQLite journal mode](https://www.sqlite.org/pragma.html#pragma_journal_mode). Default: ‘WAL’. Possible values DELETE | TRUNCATE | PERSIST | MEMORY | WAL | WAL2 | NONE. (Not all SQLite3 implementations handle WAL2.)


== Frequently Asked Questions ===

= Does this work with a multisite WordPress installation? =

**Yes**.  To see the Settings page, choose Settings > Object Cache from the first site, or any site, in the multisite installation.

= How much faster will this make my site? =

Exactly predicting each site's speedup is not possible. Still, benchmarking results are promising. Please see [this](https://www.plumislandmedia.net/wordpress-plugins/sqlite-object-cache/benchmarks/). If you run a benchmark, please let the author know by leaving a comment on that page or using the [support forum](https://wordpress.org/support/plugin/sqlite-object-cache/).

= What Cached Data Size should I use for my site? =

The default setting for Cached Data Size is 16 megabytes (MiB). This plugin allows the actual cached data size to grow larger than that, and occasionally removes old -- the least recently updated -- data to trim back the size to the setting. Take a look at the Statistics page. If your actual cached data usage under All Groups is consistently larger than the setting, double the setting.

If you operate a large and busy site, try an initial setting of 32 MiB, then adjust it based on the growth of the actual size.

Notice that this setting controls the size of the data in the cache. That is the size shown under All Groups. The data file used by SQLite is larger than that.

= What is SQLite? =

[SQLite](https://www.sqlite.org/about.html) is fast and efficient database software. It doesn't require a separate server. Instead, it is built into php using the [SQLite3](https://www.php.net/manual/en/book.sqlite3.php) extension. SQLite programs don't need to open network connections to send requests and wait for replies.

= What is igbinary? =

[igbinary](https://www.php.net/manual/en/ref.igbinary.php) is a php extension that provides an efficient way to serialize and compress data. It, in some cases, uses less than half the storage space of the built-in php data [serializer](https://www.php.net/manual/en/function.serialize.php). If igbinary is available in your php configuration, this plugin uses it. 

= Does this plugin replace MariaDB or MySQL with SQLite? =

**No.**  Your MariaDB or MySQL database sql server still holds all your content. All your site's imports, exports, backups and other database operations continue to function normally.  This plugin uses SQLite simply to hold named values. For example, a value named "post|3" will hold a temporary, easy-to-retrieve cached copy of post number 3. When it needs that post, WordPress can fetch it quickly from SQLite.

= Wait, what? Do I really need two different kinds of SQL database? =

No, you don't. This plugin doesn't use SQLite as a full-fledged database server.

A persistent object cache needs some kind of storage mechanism. SQLite serves this plugin as a fast and simple key / value storage mechanism.

Some hosting providers offer scalable high-performance [redis](https://redis.io/) cache servers.  You can use it via [Redis Object Cache](https://wordpress.org/plugins/redis-cache/) plugin. Sites using redis have one SQL database and another non-SQL storage scheme: redis. Other hosting providers offer [memcached](https://memcached.org/), which has the [Memcached Object Cache](https://wordpress.org/plugins/memcached/).

But many hosting providers don't offer either redis or memcached, while they do offer SQLite. This plugin enables your site to use a persistent object cache even without a separate cache server. And, because everything happens within your web server, the performance is good.

= Is this plugin compatible with my page-caching plugin? =

**Probably**. Page caching and persistent object caching are completely separate functions in WordPress. But, some multi-feature caching plugins support optional object caching using redis or memcached. If that feature is enabled in your page cache plugin, you should not use this plugin.

You can find out whether your page-cache plugin also enables persistent object caching. Look at the Drop-in section of the Plugins -> Installed Plugins page. If it mentions the drop-in called "object-cache.php" then your page cache plugin is handling persistent object caching. You should disable that feature before using  this plugin.

Users of this plugin have found that it works well with WP Rocket, LiteSpeed Cache, WP Total Cache, WP Super Cache, and WP Fastest Cache. It also works with Cloudflare and other content delivery networks. The author has not learned of any incompatibilities in this area. (If you find one *please* start a support topic!)

= Can I disable object caching for my backend dashboard or other pages or REST API operations I choose? =

**No.**

That's not how object caching works. It's different from page caching. It works at the level of individual database operations in the WordPress code, not at the level of whole pages.

= Is this plugin compatible with my version of MySQL or MariaDB? =

**Yes**. It does not require any specific database server version.

= Is this plugin compatible with my version of redis or memcached? =

It does not use either. 

= This cache uses a file on my server HDD/SSD, while redis and memcached use RAM. Isn't RAM faster?  =

RAM is indeed faster. Modern server operating systems offer extensive page caching for files, and SQLite is designed to take advantage of that, so the data needed may already be in RAM. Using a separate cache server require the web server to issue network requests and wait for responses, whereas using SQLite does not. Many users have found this caching methodology to be fast.

= Why not use the site's main MariaDB or MySql database server for the object cache? =

In WordPress, as in many web frameworks, your database server is a performance bottleneck. Using some other mechanism for the object cache avoids adding to your database workload. Web servers serve pages using multiple php processes, and each process handles its own SQLite workload while updating a shared database file. That spreads the object-cache workload out over many processes rather than centralizing it.

= Do I have to back up the data in SQLite? =

**No.** It's a cache, and everything in it is ephemeral. When WordPress cannot find what it needs in the cache, it simply recomputes it or refetches it from the database.

To avoid backing up SQLite files, tell your backup plugin to skip the files named `*.sqlite`, `*.sqlite-wal`,  `*.sqlite-wal2`, and `*.sqlite-shm`.

This plugin automatically suppresses backing up your SQLite data when you use the [Updraft Plus](https://wordpress.org/plugins/updraftplus/), [BackWPUp](https://wordpress.org/plugins/backwpup/), or [WP STAGING](https://wordpress.org/plugins/wp-staging/) plugins. The [Duplicator](https://wordpress.org/plugins/duplicator/) plugin does not offer an automated way to suppress copying those files.

If you use some other backup or cloning plugin, please let the author know by creating a [support topic](https://wordpress.org/support/plugin/sqlite-object-cache/).

= If I already have another persistent object cache plugin, can I use this one? =

**No.** You only need one persistent object cache plugin, and WordPress only supports one.

= If I operate a scaled-up load-balanced installation, can I use this? =

**No.** If you have more than one web server this doesn't work correctly. If you operate at that scale, use redis or some other cache server. (If you aren't sure whether you have a load-balanced installation, you almost certainly do not.)

Please notice that SQLite does not work correctly if you put its files on a shared network drive (via CIFS, SMB, NFS, or some other drive sharing protocol).

= Can I use this with the Performance Lab plugin? =

**Yes, but** you must *activate this plugin first* before you activate [Performance Lab](https://wordpress.org/plugins/performance-lab/). And, you must deactivate Performance Lab before *deactivating this plugin last*.

The [Performance Lab plugin](https://wordpress.org/plugins/performance-lab/) offers some advanced and experimental ways of making your site faster. One of its features uses object-cache initialization code to start tracking performance. So there's a required order of activation if you want both to work.

= How can I use this object cache to make my plugin or theme code run faster? =

Easy. Use transients to store your cacheable data. WordPress's [Transient API](https://developer.wordpress.org/apis/transients/) uses persistent object caching if it's available, and the MariaDB or MySQL database when it isn't. The [Metadata API](https://developer.wordpress.org/apis/metadata/) and [Options API](https://developer.wordpress.org/apis/options/) also use persistent object caching.

= How does this work? =

This plugin uses a [WordPress drop-in](https://developer.wordpress.org/reference/functions/get_dropins/) to extend the functionality of the WP_Cache class. When you activate the plugin it creates the dropin file `.../wp-content/object-cache.php`. Upon deactivation, it removes that file and the cached data.

= Where does the plugin store the cached data? =

It's in your site's `wp_content` directory, in the file named `.ht.object-cache.sqlite`. That file's name has the `.ht.` prefix to prevent your web server from allowing it to be downloaded. SQLite also sometimes uses the files named `.ht.object-cache.sqlite-shm` and `.ht.object-cache.sqlite-wal`, so you may see any of those files.

You can make the plugin put its files at some other location by putting the pathname you want in `WP_SQLITE_OBJECT_CACHE_DB_FILE` in your `wp-config.php`.

On Linux and other UNIX-derived operating systems, you must give the command `ls -a` to see files when their names begin with a dot.

= I want to store my cached data in a more secure place. How do I do that?

Putting your .sqlite files outside your site's document root is good security practice. This is how you do it. If you define the constant `WP_SQLITE_OBJECT_CACHE_DB_FILE` in `wp_config.php` the plugin uses that for sqlite's file pathname instead. For example, if `wp-config.php` contains this line

`define( 'WP_SQLITE_OBJECT_CACHE_DB_FILE', '/tmp/mysite-object-cache.sqlite' );`

your object cache data goes into the `/tmp` folder in a file named `mysite-object-cache.sqlite`.

You can also define `WP_CACHE_KEY_SALT` to be a text string. Continuing the example, this line

`define( 'WP_CACHE_KEY_SALT', 'qrstuv' );`

causes your object cache data to go into the `/tmp` folder in a file named `mysite-object-cache.qrstuv.sqlite`.

= Can this plugin use SQLite memory-mapped I/O?

**Yes**. You can use your OS's memory map feature to access and share cache data with [SQLite Memory-Mapped I/O](https://www.sqlite.org/mmap.html). On some server configurations this allows multiple php processes to share cached data more quickly. In the plugin this is disabled by default. You can enable it by telling the plugin how many MiB to use for memory mapping. For example, this wp-config setting tells the plugin to use 32MiB.

`define( 'WP_SQLITE_OBJECT_CACHE_MMAP_SIZE', 32 );`

Notice that using memory-mapped I/O may not help performance in the highly concurrent environment of a busy web server.

= I sometimes get timeout errors from SQLite. How can I fix them? =

Some sites occasionally generate error messages looking like this one:

`Unable to execute statement: database is locked in /var/www/wp-content/object-cache.php:1234`

This can happen if your server places your WordPress files on network-attached storage (that is, on a network drive). To solve this, store your cached data on a locally attached drive. See the question about storing your data in a more secure place. It also can happen in a very busy site.

= Why do I get errors when I use WP-CLI to administer my site? =

Sometimes [WP-CLI](https://wp-cli.org/) commands issued from a shell run with a different user from the web server. This plugin creates one or more object-cache files. An object-cache file may not be readable or writeable by the web server if it was created by the wp-cli user. Or the other way around.

On Linux, you can run your WP-CLI shell commands like this:  `sudo -u www-data wp config list`  This ensures they run with the same user as the web server.

= What do the Statistics mean? =

Please [read this](https://www.plumislandmedia.net/wordpress-plugins/sqlite-object-cache/statistics-from-sqlite-object-cache/).

= Is there a joke somewhere in this? =

Q: What are the two hardest things to get right in computer science?

1. Caching things.
2. Naming things.
3. Coping with off-by-one errors.

Seriously, the core of WordPress has already worked out, over years of development and millions of sites, how to cache things and name them. This plugin simply extends that mechanism to make those things persistent.

= I have another question =

Please look for more questions and answers [here](https://www.plumislandmedia.net/wordpress-plugins/sqlite-object-cache/faq/). Or ask your question in the [support forum](https://wordpress.org/support/plugin/sqlite-object-cache/).

== Screenshots ==

1. Settings panel. Access it with Settings > Object Cache.
2. Performance statistics panel.


== Changelog ==

= 1.4.1 =

* More efficient testing for drop-in validity.
* More accurate least-recently-updated cleanup.

= 1.4.0 =

* Add WP-CLI support.
* Make sure to close .sqlite files to avoid file-descriptor leaks in long-running processes. Props to Matt Jones.

= 1.3.8 =

Add some support for new SQLite WAL2 write-ahead logging.
Support WordPress 6.5.

= 1.3.7 =

Bug fix: Not all versions of SQLite can do DELETE ... LIMIT, so do transaction-size-limited DELETEs a different way.

= 1.3.6 =

* Clean up in chunks in an attempt to reduce contention delays and timeouts.
* Do PRAGMA wal_checkpoint(RESTART) when cleaning up, and also occasionally, to prevent the write-ahead log from growing without bound on busy systems.
* Retry three times if cache updates time out.
* Increase default cache size to 16MiB for new users.

= 1.3.5 =

* php 8.1, php 8.2 compatibility.
* Support for WordFence and other code using the object cache after shutdown.

= 1.3.4 =

* Support SQLite Memory-Mapped I/O.
* Reduce contention delays by limiting the number of get_multiple, set_multiple, add_multiple, and delete_multiple items in each transaction.
* Reduce index page fragmentation by using key order for set_multiple and add_multiple operations.
* Fix initialization defect in cache deletion. Props to @gRoberts84.

= 1.3.2 =

* Avoid VACUUM except on cache flush, and do it only with maintenance mode enabled.

== Upgrade Notice ==

This release offers WP-CLI support. Give the command `wp help sqlite-object-cache` for usage instructions.

It avoids file descriptor leaks in long-running php processes. Props to Matt Jones (no relation to the author).

It adds a VACUUM option, to defragment its database file and release unused SSD/HDD space.

This release attempts to reduce cache timeouts by doing cleanup operations in chunks, and by retrying timed-out cache update operations. It also does PRAGMA wal_checkpoint(RESTART) when cleaning up, and also occasionally, to prevent the write-ahead log from growing without bound on busy systems.

Thanks, dear users for letting me know about defects you found, and for your patience as I figure this out. All remaining errors are solely the responsibility of the author.
