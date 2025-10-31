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

	// Try to build a mapping of ACF field names -> labels when ACF is available so we can
	// show friendly labels next to meta keys where possible.
	$fields = array();
	if ( function_exists( 'acf_get_field_groups' ) ) {
		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
		if ( $groups ) {
			foreach ( $groups as $g ) {
				$gf = acf_get_fields( $g );
				if ( $gf ) {
					foreach ( $gf as $f ) {
						if ( isset( $f['name'] ) && ! isset( $fields[ $f['name'] ] ) ) {
							$fields[ $f['name'] ] = array(
								'label' => isset( $f['label'] ) ? $f['label'] : $f['name'],
								'name'  => $f['name'],
							);
						}
					}
				}
			}
		}
	}

	// Only show the meta key selector when a post type has been explicitly chosen by the user
	if ( isset( $_GET['uc_post_type'] ) && ! empty( $meta_keys ) ) {
		// When the post type form is submitted the page reloads and meta keys are refreshed.
		// The meta-key selector itself will submit the form and request action_view=show_meta.
		echo '<form method="get" action="' . esc_url( $page_base ) . '">';
		echo '<input type="hidden" name="page" value="acf-usage-checker" />';
		echo '<input type="hidden" name="uc_post_type" value="' . esc_attr( $post_type ) . '" />';
		echo '<label for="uc_meta_key">Post meta key: </label>';
		echo '<select id="uc_meta_key" name="uc_meta_key" onchange="this.form.submit()">';
		echo '<option value="">(choose a meta key)</option>';
		foreach ( $meta_keys as $mk ) {
			$sel = $uc_meta_key === $mk ? 'selected' : '';
			echo '<option value="' . esc_attr( $mk ) . '" ' . $sel . '>' . esc_html( $mk ) . '</option>';
		}
		echo '</select> ';
		// Preserve action_view when selecting meta key
		echo '<input type="hidden" name="action_view" value="show_meta" />';
		// reset to first page on meta key change
		echo '<input type="hidden" name="uc_page" value="1" />';
		echo '</form>';
	}

	// List available unique postmeta keys (as the main section). On selecting a post type,
	// this table will show all unique meta keys for that post type with counts and actions.
	if ( isset( $_GET['uc_post_type'] ) ) {
		if ( empty( $meta_keys ) ) {
			echo '<p>No meta keys found for this post type.</p>';
		} else {
			echo '<h2>Available post meta keys on ' . esc_html( $post_type ) . '</h2>';
			echo '<table class="widefat fixed">';
			echo '<thead><tr><th>Meta key</th><th>Label (if ACF)</th><th>Posts using it</th><th>Action</th></tr></thead><tbody>';

			foreach ( $meta_keys as $mk ) {
				$post_ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT pm.post_id
					 FROM {$wpdb->postmeta} pm
					 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					 WHERE p.post_type = %s AND pm.meta_key = %s",
					$post_type,
					$mk
				) );

				$used_count = 0;
				foreach ( $post_ids as $pid ) {
					$value = get_post_meta( $pid, $mk, true );
					if ( uc_acf_value_is_meaningful( $value ) ) {
						$used_count++;
					}
				}

				// If ACF provided a friendly label for this meta key, show it (best-effort)
				$label = isset( $fields[ $mk ] ) ? $fields[ $mk ]['label'] : '';

				$view_link = add_query_arg( array(
					'page' => 'acf-usage-checker',
					'uc_post_type' => $post_type,
					'uc_meta_key' => $mk,
					'action_view' => 'show_meta',
				), $page_base );

				echo '<tr>';
				echo '<td>' . esc_html( $mk ) . '</td>';
				echo '<td>' . esc_html( $label ) . '</td>';
				echo '<td>' . intval( $used_count ) . '</td>';
				echo '<td><a href="' . esc_url( $view_link ) . '">Show posts</a></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}
	}

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

	// If requested, show posts using a selected postmeta key (uc_meta_key) with pagination
	if ( 'show_meta' === $action && $uc_meta_key ) {
		echo '<h2>Posts using meta key ' . esc_html( $uc_meta_key ) . '</h2>';

		// Pagination params
		$per_page = isset( $_GET['uc_per_page'] ) ? max( 1, intval( $_GET['uc_per_page'] ) ) : 20;
		$paged = isset( $_GET['uc_page'] ) ? max( 1, intval( $_GET['uc_page'] ) ) : 1;

		// Get candidate post IDs that have this meta_key
		$candidate_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE p.post_type = %s AND pm.meta_key = %s
			 ORDER BY pm.post_id ASC",
			$post_type,
			$uc_meta_key
		) );

		// Filter by meaningful values
		$used_post_ids = array();
		foreach ( $candidate_ids as $pid ) {
			$value = get_post_meta( $pid, $uc_meta_key, true );
			if ( uc_acf_value_is_meaningful( $value ) ) {
				$used_post_ids[] = $pid;
			}
		}

		$total_count = count( $used_post_ids );
		if ( 0 === $total_count ) {
			echo '<p>No posts have this meta key populated with a meaningful value.</p>';
		} else {
			$total_pages = (int) ceil( $total_count / $per_page );
			if ( $paged > $total_pages ) {
				$paged = $total_pages;
			}
			$offset = ( $paged - 1 ) * $per_page;
			$page_ids = array_slice( $used_post_ids, $offset, $per_page );

			// Fetch posts for this page preserving order
			$posts = get_posts( array(
				'post_type' => $post_type,
				'post__in' => $page_ids,
				'orderby' => 'post__in',
				'numberposts' => $per_page,
			) );

			// Summary and table
			echo '<p>Total posts: <strong>' . intval( $total_count ) . '</strong></p>';
			echo '<table class="widefat fixed striped">';
			echo '<thead><tr><th style="width:8%">ID</th><th>Post</th><th style="width:25%">Action</th></tr></thead><tbody>';

			foreach ( $posts as $post ) {
				$view_url = get_permalink( $post->ID );
				$edit_url = get_edit_post_link( $post->ID );
				$delete_url = get_delete_post_link( $post->ID );

				echo '<tr>';
				echo '<td>' . intval( $post->ID ) . '</td>';
				echo '<td>' . esc_html( get_the_title( $post->ID ) ? get_the_title( $post->ID ) : "(ID {$post->ID})" ) . '</td>';
				echo '<td>';
				if ( $view_url ) {
					echo '<a href="' . esc_url( $view_url ) . '" target="_blank">View</a> ';
				}
				if ( $edit_url ) {
					echo '<a href="' . esc_url( $edit_url ) . '">Edit</a> ';
				}
				if ( current_user_can( 'delete_post', $post->ID ) ) {
					echo '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'Are you sure you want to delete this post?\');">Delete</a>';
				}
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';

			// Pagination links
			$base = add_query_arg( array(
				'page' => 'acf-usage-checker',
				'uc_post_type' => $post_type,
				'uc_meta_key' => $uc_meta_key,
				'action_view' => 'show_meta',
				'uc_per_page' => $per_page,
			), $page_base );

			echo '<div class="tablenav"><div class="tablenav-pages">';
			// Prev link
			if ( $paged > 1 ) {
				$prev_url = add_query_arg( 'uc_page', $paged - 1, $base );
				echo '<a class="prev-page" href="' . esc_url( $prev_url ) . '">&laquo; Prev</a> ';
			}

			// Page numbers (simple)
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				if ( $i === $paged ) {
					echo '<span class="paging-input"><strong>' . $i . '</strong></span> ';
				} else {
					$url = add_query_arg( 'uc_page', $i, $base );
					echo '<a href="' . esc_url( $url ) . '">' . $i . '</a> ';
				}
			}

			// Next link
			if ( $paged < $total_pages ) {
				$next_url = add_query_arg( 'uc_page', $paged + 1, $base );
				echo '<a class="next-page" href="' . esc_url( $next_url ) . '">Next &raquo;</a>';
			}

			echo '</div></div>';
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

