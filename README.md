wp-downloader
=============

A Composer plugin that provides a zero effort way to solve a very specific issue for a very specific 
edge case: install WordPress plugins and themes, with Composer, _inside_ WordPress `/wp-content` folder.

--------

# Why?

By using Composer to manage both WordPress core and plugins and themes and at same to time tell Composer
to put  plugins and themes _inside_ WordPress default `/wp-content` folder is just **not** possible 
without issues.

Because, in short, every time WordPress is installed or updated, the _whole_ WordPress folder
 (so including `/wp-content` dir) is deleted the and so plugins and themes packages inside it are lost.

I have explored different ways to solve this issue, but it seems that the most simple way is just to
avoid to treat WordPress as a regular Composer package, because - guess what - it is *not* a 
regular Composer package.

Thanks to many people effort, nowadays we have ways to use Composer to manage entire WordPress websites
that works _in the large majority_ of the cases.

This plugin comes for that specific case that does not belong to that "large majority". But:

- it is **not** designed as a "general usage" WordPress installer for Composer
- it does **not** take into consideration (nor it will to take) other edge cases that is possible
  to encounter in all the crazy setups out there.
  
--------
  
# How it works?

WordPress will be downloaded from zip releases available at wordpress.org and unzipped in a folder
of choice.

When Composer is ran to update WordPress, old installation files are deleted, but `/wp-content` dir
and `wp-config.php` (if found in WP root folder) are not touched.

If Composer is setup to place plugins and themes inside `/wp-content`, they will be updated when needed,
by Composer.

--------

# Usage

In the **root** `composer.json` require `wecodemore/wp-downloader`.

That's it.

There are few optional configurations, explained below. 

--------

# Configuration

The plugin supports a `wp-downloader` object in `composer.json` (to be placed inside `extra` object
in root package) that allows to configure:

- the WordPress target path
- the WordPress desired version
- if to download the "no content" version of WordPress archive or the full version

An example:

```json
{
  "name": "you/your-awesome-website",
  "type": "project",
  "require": {
    "wecodemore/wp-downloader": "^1.0"
  },
  "extra": {
    "wp-downloader": {
      "version": ">=4.5",
      "no-content": true,
      "target-dir": "public/wp"
    }
  }
}
```

**None** of the settings above are mandatory, in fact the `wp-downloader` object could not be there at all.

All settings have defaults and both target dir and WordPress version may be set in a different way.

## Configure WordPress installation path

As you can see above, the target path can be set via the `extra.wp-downloader.target-dir` setting.

However, the plugin also supports the path to be set in `extra.wordpress-install-dir` (used, 
for example, also by `johnpbloch/wordpress-core-installer`).

So if that settings is used, the path in there will be used, unless it is overridden by putting a 
different path in `extra.wp-downloader.target-dir`.

## Configure WordPress version

In the example above, the WordPress version to download is set using `extra.wp-downloader.version` config.

You can put there anything that [Composer understands](https://getcomposer.org/doc/articles/versions.md).

Well, not really _anything_ because **this package only work with stable versions**.

Additionally, it is possible to use the keyword `latest` that, as you can guess, will download the 
latest version of WordPress.

There's also another way to setup the WordPress version to download.

If the root `composer.json` requires any package of the type `wordpress-core`, the version used
in that requirement will be "discovered" and used by this plugin.
 
 For example, if a `composer.json` contains:
  
```json
{
  "name": "you/your-awesome-website",
  "type": "project",
  "require": {
    "wecodemore/wp-downloader": "^1.0",
    "johnpbloch/wordpress": ">=4.6"
  },
  "extra": {
    "wordpress-install-dir": "public/wp"
  }
}
```

wp-downloader will read the version of `johnpbloch/wordpress` and will download a version compatible
with the requirements of that package (in example above the version 4.6 or higher).

This works only if a WordPress core package is required in the **root** `composer.json`: core packages 
required as secondary dependencies will not be read, and **if no version is provided** (neither by package 
nor via `wp-downloader` object) **the latest WordPress version will be downloaded**.

Also note that using example above the WP installation folder will be `public/wp`, being it read from
the `wordpress-install-dir` setting.

**Please note**: using a `composer.json` like the one right above will **not** install WordPress
from the required package (`johnpbloch/wordpress`).

In fact, **wp-downloader prevents packages of type `wordpress-core` to be installed by Composer**, or 
the unique reason to exists of this plugin would be defeated.


## No-content setting

By default wp-downloader downloads the "no-content" version of WordPress archives.

This is usually fine when using Composer to manage the entire website, as the themes and plugins are
pulled with Composer.

However, if someone wants to use a default theme, it needs to be pulled separately using Composer,
or maybe, wp-downloader can be instructed to download a full WordPress archive of by setting
`extra.wp-downloader.no-content` setting to `false`.

--------

# Gotchas

Even if wp-downloader could be used when WP plugins and themes are placed outside of WordPress
folder, there's actually no benefit on using it in those cases.

Usage of this plugin is suggested only when plugins and themes really need to be inside WordPress folder.
 
Main plugin gotchas are:
 
- Downloaded WordPress zips does not go into Composer cache, so it needs to be downloaded from the repo
  anytime is needed. If more websites in the same server (or even locally) needs exact same version
  of WP, the plugin will download it every time.
- Not being a Composer package, WordPress version is not written into the `composer.lock` file.
  So the only way to do ensure exact same WP version among servers is to use an exact version requirement.
- This package does not work with trunk or WordPress development versions.
  If you need to develop for WordPress core or test things against trunk, and you want to use Composer... 
  then use Composer, and not this plugin, to download WordPress.
  
--------
  
# FAQ

> How do I tell Composer to place my plugin / themes inside WordPress `/wp-content` folder?

- That's something that does not depends on this plugin. Change the path of specific plugin themes
  is something provided by an official Composer plugin named "Composer installers".
  It can be installed by requiring `"composer/installers"` and it needs an `"installer-paths"`
  setting in `composer.json`. See [plugin docs](http://composer.github.io/installers/)
  
> I required this plugin, now the website show just WSOD!

- By default this plugin downloads the "no content" version of WordPress archive. It means that no
  default themes are downloaded. If you use a default theme (or maybe a child theme of one of them), 
  you won't be able to see the frontend of the website.
  See [**"No-content setting"**](#no-content-setting) section above.

> Do you wish to support [_insert crazy edge case here_]?

- No, I don't.

> Do you wish to implement [_feature here_]?

- Probably no.

> I found a bug, can you fix it?

- I can, but no warranty on when. Consider that contributions are welcome and PR open.

> I used a fixed required version for WP and it does not work. Why?

- Check that the URL `https://downloads.wordpress.org/release/wordpress-{$version}-no-content.zip` is there.
  If it is not, but `https://downloads.wordpress.org/release/wordpress-{$version}.zip` is, then set
  `no-content` setting to false.
  
> The package [_package here_] I am using requires WordPress package, but the plugin does not
  auto-discover which WP version to download, and always download latest version.
  
- This plugin auto-discovers WordPress version from required WordPress packages that are required only 
  from **root** `composer.json`. If WordPress is required from a package you require, that won't work.

> Does this work with [WP Starter](https://github.com/wecodemore/wpstarter)?

- Yes, it does.

--------

# License

MIT.