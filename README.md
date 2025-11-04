# FieldRadar

Lightweight admin tool to discover which ACF/post-meta fields for a post type are actually populated and which posts use them.

## Features

- Enumerates ACF fields (via ACF field groups) for a selected post type when ACF is active.
- Falls back to scanning postmeta keys present on posts of that post type when ACF is not available.
- Counts how many posts have a "meaningful" value for each field (non-empty string, non-empty array, non-null).
- Shows a list of posts that actually have the field populated with a short value summary and edit links.

## Installation

1. Copy the `fieldradar` folder to your site's `wp-content/plugins` directory.
2. Activate the plugin on the Plugins screen.

## Usage

In WordPress Admin go to: Tools → FieldRadar

- Select a post type (the plugin lists public post types).
- Click "Load fields" to list candidate fields/meta keys for that post type.
- Click "Show posts" for any field to see which posts have that field populated.

### Direct URL example

You can open the tool directly with a URL. The plugin uses a namespaced query parameter `uc_post_type` to avoid colliding with WP admin routing.

Examples:

- Open the tool for posts:

  https://example.test/wp-admin/tools.php?page=fieldradar&uc_post_type=post

- Open the tool for attachments (media):

  https://example.test/wp-admin/tools.php?page=fieldradar&uc_post_type=attachment

Note: legacy `post_type` is accepted for direct links but the UI uses `uc_post_type` to avoid routing collisions.

## How it detects usage

- If ACF is active, the plugin attempts to list fields from ACF field groups tied to the selected post type (uses `acf_get_field_groups()` and `acf_get_fields()`). This provides friendly labels where available.
- If no ACF groups are found (or ACF is not active), the plugin queries distinct `meta_key` values present on posts of the selected post type and treats those as candidate fields.
- For each candidate field, the plugin finds posts that have a meta entry for that key, then checks the meta value via `get_post_meta()` to determine whether it is meaningfully populated:
  - null or empty string -> not used
  - empty array -> not used
  - array with at least one meaningful element -> used
  - serialized values are read via `get_post_meta()` (WordPress returns them unserialized)

## Limitations & caveats

- Large sites: scanning postmeta and loading values for many posts can be slow and memory-heavy. For very large sites consider adding pagination, background indexing, or a WP-CLI implementation.
- Some ACF field types (deep nested repeaters, complex serialized structures) may be treated conservatively by the "meaningful" heuristic — this tool is best-effort.
- The plugin only checks postmeta; if your field values are stored elsewhere (e.g. embedded JSON in post content, remote storage, or custom tables) they will not be detected.

## Troubleshooting

 - If you see a "Cannot load FieldRadar" or similar error, it likely means WordPress admin attempted to resolve the menu parent incorrectly due to `post_type` being present on the request. Try opening the tool with `uc_post_type` instead of `post_type`, for example:

  https://your-site.test/wp-admin/tools.php?page=fieldradar&uc_post_type=attachment

- If fields do not appear when ACF is active, confirm ACF is enabled and that field groups are set to show for the selected post type.

## Recommended improvements

- Add AJAX pagination when listing posts to avoid timeouts / memory issues on large counts.
- Add a WP-CLI command for scripted scans and reporting.
- Optionally cache counts in a transient and provide a "Refresh" button.

## License

This plugin is provided as-is for convenience. Integrate into your project and adapt the behavior to your needs.
