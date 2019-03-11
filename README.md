# EDD Software Licensing Updates on Multisite
A must-use plugin for Easy Digital Downloads Software Licensing that makes updating plugins easier for multisite network admins

## Description
This is an add-on for the Easy Digital Downloads Software Licensing extension. When providing updates to plugins, EDD SL changes the WordPress updater to check for new versions of plugins on the sites where they were purchased instead of wordpress.org. This works great until deployed onto a multisite network. Since plugins are activated on a per site basis except when they are Network Activated, the network admin plugins page doesn't check for updates correctly. This plugin is a fix.

EDD provides some [workaround instructions](https://docs.easydigitaldownloads.com/article/937-why-do-my-plugin-updates-not-show-up), but I decided to work on this problem instead of logging into many sites on a network to make sure all plugin updates are triggered.

**This plugin requires that you write another** to extract the license keys before the update check is made. The only way I can see to make this plugin portable is to provide a filter hook with which you can provide license keys for the update. My plugins store EDD SL keys in an array of plugin settings in an option. Your plugins might do this differently, so I've also shared [this other plugin](https://gist.github.com/csalzano/621deacc33f2482da205f294b445485a) that delivers license keys to this plugin based on the plugin slug.

**Only certain plugins will be checked for updates.** Please reading the Installation instructions below.

## Installation
1. Make sure the `Plugin URI` value in your plugin's header is the site where Easy Digital Downloads is running. That's where we check for an update.
1. Make sure the plugin header has an `Updateable` line, a file named `edd_mu_updater.php` or a file name `/includes/class-updater.php`. These are the only plugins we will check for updates.
1. Upload the `edd-sl-updates-on-multisite` directory and the `edd-sl-updates-on-multisite.php` driver file to the `/wp-content/mu-plugins/` directory. This is the [must-use plugins](https://codex.wordpress.org/Must_Use_Plugins) directory, and no activation is necessary.
1. Create another must-use plugin that provides EDD Software Licensing keys to this plugin using these instructions.
1. Upload the plugin you created in the previous step to the `/wp-content/mu-plugins/` directory.


## Changelog

See [readme.txt](https://github.com/csalzano/edd-sl-updates-on-multisite/blob/master/edd-sl-updates-on-multisite/readme.txt)


## History
I built this plugin because I needed to begin using it immediately on ~150 sites. I found [this code](https://www.wproute.com/2013/09/edd-updater-for-wp-multisites/) that didn't work for me, but allowed me to learn about the challenges to making automatic updates on multisite work. This plugin is my fork of this code--I set out to create a must-use plugint that can be dropped into a multisite and facilitate updates to my Easy Digital Downloads store.