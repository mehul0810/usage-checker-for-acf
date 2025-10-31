<?php
/**
 * Plugin Name: Usage Checker for ACF
 * Description: Admin tool to find which ACF/post meta fields are actually in use for a post type and which posts have them populated.
 * Version: 1.0.0
 * Author: Automated Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function() {
	add_management_page(
		'ACF Usage Checker',
		'ACF Usage Checker',
		'manage_options',
		'acf-usage-checker',
		'uc_acf_admin_page'
	);
} );

/**
 * Render admin page
 */
function uc_acf_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;

	// Use a namespaced query var to avoid colliding with WP admin's `post_type` handler
	// If `uc_post_type` isn't provided, fall back to legacy `post_type` for direct links.
	if ( isset( $_GET['uc_post_type'] ) ) {
		$post_type = sanitize_text_field( wp_unslash( $_GET['uc_post_type'] ) );
	} elseif ( isset( $_GET['post_type'] ) ) {
		// legacy support for direct URLs
		$post_type = sanitize_text_field( wp_unslash( $_GET['post_type'] ) );
	} else {
		$post_type = 'post';
	}
	$show_field = isset( $_GET['field'] ) ? sanitize_text_field( wp_unslash( $_GET['field'] ) ) : ''; 
	$action = isset( $_GET['action_view'] ) ? sanitize_text_field( wp_unslash( $_GET['action_view'] ) ) : '';
	$uc_meta_key = isset( $_GET['uc_meta_key'] ) ? sanitize_text_field( wp_unslash( $_GET['uc_meta_key'] ) ) : '';

	$post_types = get_post_types( array( 'public' => true ), 'objects' );

	echo '<div class="wrap">';
	echo '<h1>ACF / Meta Usage Checker</h1>';

	// Post type selector - force the admin base to tools.php so WP routing stays consistent
	$page_base = admin_url( 'tools.php' );
	echo '<form method="get" action="' . esc_url( $page_base ) . '">';
	echo '<input type="hidden" name="page" value="acf-usage-checker" />';
	// Use uc_post_type to avoid WP admin interference
	echo '<input type="hidden" name="uc_post_type" value="' . esc_attr( $post_type ) . '" />';
	echo '<label for="post_type">Post type: </label>';
	echo '<select id="post_type" name="uc_post_type">';
	foreach ( $post_types as $pt ) {
		$sel = $post_type === $pt->name ? 'selected' : '';
		echo "<option value=\"" . esc_attr( $pt->name ) . "\" $sel>" . esc_html( $pt->label ) . "</option>";
	}
	echo '</select> ';
	submit_button( 'Load fields', 'secondary', '', false );
	echo '</form>';

	// Build a list of distinct meta keys present on posts of this post type so the user
	// can directly check any postmeta key (not just ACF-declared fields).
	$meta_keys = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT pm.meta_key
		 FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
		 WHERE p.post_type = %s
		 ORDER BY pm.meta_key ASC",
		$post_type
	) );
	// Include underscore-prefixed meta keys as well, but exclude known core/internal keys
	$core_underscore_keys = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_thumbnail_id',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup',
		'_wp_trash_meta_time',
	);
	$meta_keys = array_values( array_filter( (array) $meta_keys, function( $k ) use ( $core_underscore_keys ) {
		if ( '' === $k ) {
			return false;
		}
		// allow underscore keys unless they're in the core blacklist
		if ( '_' === substr( $k, 0, 1 ) ) {
			return ! in_array( $k, $core_underscore_keys, true );
		}
		return true;
	} ) );

	if ( ! empty( $meta_keys ) ) {
		echo '<form method="get" action="' . esc_url( $page_base ) . '">';
		echo '<input type="hidden" name="page" value="acf-usage-checker" />';
		echo '<input type="hidden" name="uc_post_type" value="' . esc_attr( $post_type ) . '" />';
		echo '<label for="uc_meta_key">Post meta key: </label>';
		echo '<select id="uc_meta_key" name="uc_meta_key">';
		echo '<option value="">(choose a meta key)</option>';
		foreach ( $meta_keys as $mk ) {
			$sel = $uc_meta_key === $mk ? 'selected' : '';
			echo '<option value="' . esc_attr( $mk ) . '" ' . $sel . '>' . esc_html( $mk ) . '</option>';
		}
		echo '</select> ';
		if ( $uc_meta_key ) {
			$meta_view_link = add_query_arg( array(
				'page' => 'acf-usage-checker',
				'uc_post_type' => $post_type,
				'uc_meta_key' => $uc_meta_key,
				'action_view' => 'show_meta',
			), $page_base );
			echo '<a class="button" href="' . esc_url( $meta_view_link ) . '">Show posts for meta key</a>';
		} else {
			submit_button( 'Refresh meta keys', 'secondary', '', false );
		}
		echo '</form>';
	}

	// Determine candidate fields
	$fields = array();

	if ( function_exists( 'acf_get_field_groups' ) ) {
		// Try to get ACF fields associated with this post type via field groups
		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
		$keys = array();
		foreach ( $groups as $g ) {
			$gf = acf_get_fields( $g );
			if ( $gf ) {
				foreach ( $gf as $f ) {
					if ( ! isset( $fields[ $f['name'] ] ) ) {
						$fields[ $f['name'] ] = array(
							'label' => isset( $f['label'] ) ? $f['label'] : $f['name'],
							'name'  => $f['name'],
						);
					}
				}
			}
		}
	}

	// Fallback: list distinct meta_keys present on posts of this post type
	if ( empty( $fields ) ) {
		$meta_keys = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.meta_key
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE p.post_type = %s
			 ORDER BY pm.meta_key ASC",
			$post_type
		) );

		foreach ( $meta_keys as $mk ) {
			if ( substr( $mk, 0, 1 ) === '_' ) {
				// skip internal meta keys (ACF stores _fieldname pointing to field key)
				continue;
			}
			$fields[ $mk ] = array( 'label' => $mk, 'name' => $mk );
		}
	}

	if ( empty( $fields ) ) {
		echo '<p>No candidate fields/meta keys found for this post type.</p>';
		echo '</div>';
		return;
	}

	// For each field, count posts where the meta is meaningfully populated
	echo '<h2>Fields on ' . esc_html( $post_type ) . '</h2>';
	echo '<table class="widefat fixed">';
	echo '<thead><tr><th>Field name</th><th>Label</th><th>Posts using it</th><th>Action</th></tr></thead><tbody>';

	foreach ( $fields as $fname => $f ) {
		// get candidate post IDs that have this meta_key
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE p.post_type = %s AND pm.meta_key = %s",
			$post_type,
			$fname
		) );

		$used_count = 0;
		foreach ( $post_ids as $pid ) {
			$value = get_post_meta( $pid, $fname, true );
			if ( uc_acf_value_is_meaningful( $value ) ) {
				$used_count++;
			}
		}

		$view_link = add_query_arg( array(
			'page' => 'acf-usage-checker',
			'uc_post_type' => $post_type,
			'field' => $fname,
			'action_view' => 'show_posts',
		), $page_base );

		echo '<tr>';
		echo '<td>' . esc_html( $fname ) . '</td>';
		echo '<td>' . esc_html( $f['label'] ) . '</td>';
		echo '<td>' . intval( $used_count ) . '</td>';
		echo '<td><a href="' . esc_url( $view_link ) . '">Show posts</a></td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	// If requested, show posts using a specific field
	if ( 'show_posts' === $action && $show_field ) {
		echo '<h2>Posts using ' . esc_html( $show_field ) . '</h2>';
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE p.post_type = %s AND pm.meta_key = %s",
			$post_type,
			$show_field
		) );

		if ( empty( $post_ids ) ) {
			echo '<p>No posts have this meta key present.</p>';
		} else {
			echo '<ul>'; 
			foreach ( $post_ids as $pid ) {
				$value = get_post_meta( $pid, $show_field, true );
				if ( ! uc_acf_value_is_meaningful( $value ) ) {
					continue;
				}
				$post = get_post( $pid );
				if ( ! $post ) {
					continue;
				}
				$permalink = get_edit_post_link( $pid );
				echo '<li>' . sprintf( '<a href="%s">%s</a> — %s', esc_url( $permalink ), esc_html( get_the_title( $pid ) ? get_the_title( $pid ) : "(ID $pid)" ), esc_html( uc_acf_value_summary( $value ) ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	// If requested, show posts using a selected postmeta key (uc_meta_key)
	if ( 'show_meta' === $action && $uc_meta_key ) {
		echo '<h2>Posts using meta key ' . esc_html( $uc_meta_key ) . '</h2>';
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE p.post_type = %s AND pm.meta_key = %s",
			 $post_type,
			 $uc_meta_key
		) );

		if ( empty( $post_ids ) ) {
			echo '<p>No posts have this meta key present.</p>';
		} else {
			echo '<ul>'; 
			foreach ( $post_ids as $pid ) {
				$value = get_post_meta( $pid, $uc_meta_key, true );
				if ( ! uc_acf_value_is_meaningful( $value ) ) {
					continue;
				}
				$post = get_post( $pid );
				if ( ! $post ) {
					continue;
				}
				$permalink = get_edit_post_link( $pid );
				echo '<li>' . sprintf( '<a href="%s">%s</a> — %s', esc_url( $permalink ), esc_html( get_the_title( $pid ) ? get_the_title( $pid ) : "(ID $pid)" ), esc_html( uc_acf_value_summary( $value ) ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	echo '</div>';
}

/**
 * Determine whether a stored meta value is 'meaningfully' populated.
 * Returns true if value is not empty string, not an empty array, not null.
 */
function uc_acf_value_is_meaningful( $value ) {
	if ( is_null( $value ) ) {
		return false;
	}
	if ( is_string( $value ) ) {
		$trim = trim( $value );
		if ( $trim === '' ) {
			return false;
		}
		return true;
	}
	if ( is_array( $value ) ) {
		if ( empty( $value ) ) {
			return false;
		}
		// If array contains only empty values, consider not meaningful
		foreach ( $value as $v ) {
			if ( uc_acf_value_is_meaningful( $v ) ) {
				return true;
			}
		}
		return false;
	}
	// For objects/other scalars, consider it meaningful if not empty
	return ! empty( $value );
}

/**
 * Create a short one-line summary of the value for display.
 */
function uc_acf_value_summary( $value ) {
	if ( is_null( $value ) ) {
		return '(empty)';
	}
	if ( is_string( $value ) ) {
		$s = wp_trim_words( $value, 10, '...' );
		return $s;
	}
	if ( is_array( $value ) ) {
		return 'Array(' . count( $value ) . ')';
	}
	if ( is_object( $value ) ) {
		return 'Object';
	}
	return (string) $value;
}

