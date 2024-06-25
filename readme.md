WP To Markdown
=======

Convert WordPress posts to markdown files, in a format and folder structure friendly to my `tjm/wiki.php` project.  Puts various values in "front-matter" (YAML at top of markdown file).  Prefers `post_content_filtered` field for post content, which is used by JetPack markdown plugin, which I use for my newer posts.  Still has problems converting certain post content:  Check the results before using on a live site.  Can be run repeatedly, will only update files if content has changed.  Does not remove posts removed in WordPress.

To run, install package, run `composer install`, then run `bin/run` at the command line.  You must either pass the non-empty values for these arguments:

1. output destination path
2. database DSN for PDO (see PDO docs if needed)
3. database user
4. database password
5. database table prefix
6. ssh id (user@server) if on a remote system

or, better, modify the `bin/run` file to add those values.

Can also be used in PHP code with `(new TJM\WPToMarkdown($opts))->run()`.  See `src/WPToMarkdown.php` for options.

License
------

<footer>
<p>SPDX-License-Identifier: 0BSD</p>
</footer>
