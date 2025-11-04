# FieldRadar

A lightweight WordPress plugin to discover which meta fields for a post type are actually populated and which posts use them.

## Features

- Finds the relevant post meta fields for the selected post type
- Finds the list of posts that have the selected meta key along with total count.

## Installation

1. Copy the `fieldradar` folder to your site's `wp-content/plugins` directory.
2. Activate the plugin on the Plugins screen.

## Usage

In WordPress Admin go to: Tools → FieldRadar

- Select a post type (the plugin lists public post types).
- Click "Load fields" to list candidate fields/meta keys for that post type.
- Click "Show posts" for any field to see which posts have that field populated.

## Limitations & caveats

- Large sites: scanning postmeta and loading values for many posts can be slow and memory-heavy. Create issue or reach out for any questions using [contact page](https://mehulgohil.com/contact/).
- The plugin only checks postmeta; if your field values are stored elsewhere (e.g. embedded JSON in post content, remote storage, or custom tables) they will not be detected.

## Other WordPress Plugins

- [OneCaptcha](https://onecaptchawp.com): Connects popular Captcha providers with WordPress forms and other integrations.
- [WP Theme Switcher](https://wpthemeswitcher.com): Use multiple themes at the same time on your website. Useful for Theme Migration, marketing, and more.
- [Perform](https://performwp.com): Reduce unnecessary bloat and optimize your WordPress site.