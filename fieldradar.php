<?php

/**
 * FieldRadar
 *
 * Admin tool to find which ACF / post meta fields are actually in use for a post type
 * and which posts have them populated.
 *
 * @package FieldRadar
 * @author  Mehul Gohil
 * @version 1.0.0
 */
/**
 * Plugin Name: FieldRadar
 * Description: Admin tool to find which ACF/post meta fields are actually in use for a post type and which posts have them populated.
 * Version: 1.0.0
 * Author: Mehul Gohil
 * Author URI: https://mehulgohil.com
 * Text Domain: fieldradar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the complete admin page as an HTML string.
 *
 * This function keeps rendering logic in one place and returns a string so the
 * caller can echo it once. This improves testability and readability.
 *
 * @param array $data Data required for rendering.
 * @return string HTML for the admin page (escaped where appropriate).
 */
function fieldradar_get_admin_page_html( array $data ) {
	$post_type   = $data['post_type'];
	$show_field  = $data['show_field'];
	$action      = $data['action'];
	$uc_meta_key = $data['uc_meta_key'];
	$post_types  = $data['post_types'];
	$page_slug   = $data['page_slug'];
	$page_base   = $data['page_base'];
	/** @var wpdb $wpdb */
	$wpdb        = $data['wpdb'];

	$html = '';
	$html .= '<div class="wrap">';
	$html .= '<h1>' . esc_html__( 'FieldRadar', 'fieldradar' ) . '</h1>';
	$html .= '<p class="fieldradar-description">' . esc_html__( 'Lightweight admin tool to discover which ACF/post-meta fields for a post type are actually populated and which posts use them.', 'fieldradar' ) . '</p>';

	// Post type selector form
	$html .= fieldradar_build_post_type_form_html( $post_types, $post_type, $page_base, $page_slug );

	// Get meta keys for the selected post type
	$meta_keys = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT pm.meta_key
		 FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
		 WHERE p.post_type = %s
		 ORDER BY pm.meta_key ASC",
		$post_type
	) );

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
		if ( '_' === substr( $k, 0, 1 ) ) {
			return ! in_array( $k, $core_underscore_keys, true );
		}
		return true;
	} ) );

	// ACF fields mapping (if available)
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
	$fields = (array) apply_filters( 'fieldradar_acf_fields', $fields, $post_type );

	// Meta key selector
	if ( isset( $_GET['uc_post_type'] ) && ! empty( $meta_keys ) ) {
		$html .= fieldradar_build_meta_key_form_html( $page_base, $page_slug, $post_type, $uc_meta_key, $meta_keys );
	}

	// Main meta keys table
	if ( isset( $_GET['uc_post_type'] ) ) {
		if ( empty( $meta_keys ) ) {
			$html .= '<p>' . esc_html__( 'No meta keys found for this post type.', 'fieldradar' ) . '</p>';
		} else {
			$html .= fieldradar_build_meta_keys_table_html( $meta_keys, $fields, $post_type, $page_base, $page_slug, $wpdb );
		}
	}

	// If requested, show posts using a specific field
	if ( 'show_posts' === $action && $show_field ) {
		$html .= fieldradar_build_posts_list_html( $show_field, $post_type, $wpdb );
	}

	// If requested, show posts using a selected postmeta key (uc_meta_key) with pagination
	if ( 'show_meta' === $action && $uc_meta_key ) {
		$html .= fieldradar_build_posts_table_with_pagination_html( $uc_meta_key, $post_type, $page_base, $page_slug, $wpdb );
	}

	$html .= '</div>';

	return $html;
}

/**
 * Build the post type selector form HTML.
 *
 * @return string HTML
 */
function fieldradar_build_post_type_form_html( $post_types, $post_type, $page_base, $page_slug ) {
	$html  = '';
	$html .= '<form method="get" action="' . esc_url( $page_base ) . '">';
	$html .= '<input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '" />';
	$html .= '<div class="fieldradar-row">';
	$html .= '<label for="post_type">' . esc_html__( 'Post type:', 'fieldradar' ) . '</label>';
	// Controls wrapper ensures select and button sit on the same line and align nicely.
	$html .= '<span class="fieldradar-controls">';
	$html .= '<select id="post_type" name="uc_post_type">';
	foreach ( $post_types as $pt ) {
		$sel = $post_type === $pt->name ? 'selected' : '';
		$html .= '<option value="' . esc_attr( $pt->name ) . '" ' . $sel . '>' . esc_html( $pt->label ) . '</option>';
	}
	$html .= '</select>';
	// Return the button HTML (don't wrap in a <p>) so layout stays inline.
	$html .= ' ' . get_submit_button( esc_html__( 'Load fields', 'fieldradar' ), 'secondary', '', false );
	$html .= '</span>';
	$html .= '</div>';
	$html .= '</form>';
	return $html;
}

/**
 * Build meta key selector form HTML.
 *
 * @return string HTML
 */
function fieldradar_build_meta_key_form_html( $page_base, $page_slug, $post_type, $uc_meta_key, $meta_keys ) {
	$html  = '';
	$html .= '<form method="get" action="' . esc_url( $page_base ) . '">';
	$html .= '<input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '" />';
	$html .= '<input type="hidden" name="uc_post_type" value="' . esc_attr( $post_type ) . '" />';
	$html .= '<div class="fieldradar-row">';
	$html .= '<label for="uc_meta_key">' . esc_html__( 'Post meta key:', 'fieldradar' ) . '</label>';
	$html .= '<select id="uc_meta_key" name="uc_meta_key" onchange="this.form.submit()">';
	$html .= '<option value="">' . esc_html__( '(choose a meta key)', 'fieldradar' ) . '</option>';
	foreach ( $meta_keys as $mk ) {
		$sel = $uc_meta_key === $mk ? 'selected' : '';
		$html .= '<option value="' . esc_attr( $mk ) . '" ' . $sel . '>' . esc_html( $mk ) . '</option>';
	}
	$html .= '</select> ';
	// Preserve action_view when selecting meta key
	$html .= '<input type="hidden" name="action_view" value="show_meta" />';
	// reset to first page on meta key change
	$html .= '<input type="hidden" name="uc_page" value="1" />';
	$html .= '</div>';
	$html .= '</form>';
	return $html;
}

/**
 * Build the main meta keys table HTML.
 *
 * @return string HTML
 */
function fieldradar_build_meta_keys_table_html( $meta_keys, $fields, $post_type, $page_base, $page_slug, $wpdb ) {
	$html = '';
	$html .= '<h2>' . sprintf( esc_html__( 'Available meta keys for post type: %s', 'fieldradar' ), esc_html( $post_type ) ) . '</h2>';
	$html .= '<table class="widefat fixed">';
	$html .= '<thead><tr><th>' . esc_html__( 'Meta key', 'fieldradar' ) . '</th><th>' . esc_html__( 'Label (if ACF)', 'fieldradar' ) . '</th><th>' . esc_html__( 'Posts using it', 'fieldradar' ) . '</th><th>' . esc_html__( 'Action', 'fieldradar' ) . '</th></tr></thead><tbody>';

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
			if ( fieldradar_value_is_meaningful( $value ) ) {
				$used_count++;
			}
		}

		$label = isset( $fields[ $mk ] ) ? $fields[ $mk ]['label'] : '';

		$view_link = add_query_arg( array(
			'page' => $page_slug,
			'uc_post_type' => $post_type,
			'uc_meta_key' => $mk,
			'action_view' => 'show_meta',
		), $page_base );

		$html .= '<tr>';
		$html .= '<td>' . esc_html( $mk ) . '</td>';
		$html .= '<td>' . esc_html( $label ) . '</td>';
		$html .= '<td>' . intval( $used_count ) . '</td>';
		$html .= '<td><a href="' . esc_url( $view_link ) . '">' . esc_html__( 'Show posts', 'fieldradar' ) . '</a></td>';
		$html .= '</tr>';
	}

	$html .= '</tbody></table>';
	return $html;
}

/**
 * Build a simple list of posts that use a specific field.
 *
 * @return string HTML
 */
function fieldradar_build_posts_list_html( $show_field, $post_type, $wpdb ) {
	$html = '';
	$html .= '<h2>' . sprintf( esc_html__( 'Posts using %s', 'fieldradar' ), esc_html( $show_field ) ) . '</h2>';

	$post_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT pm.post_id
		 FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
		 WHERE p.post_type = %s AND pm.meta_key = %s",
		$post_type,
		$show_field
	) );

	if ( empty( $post_ids ) ) {
		$html .= '<p>' . esc_html__( 'No posts have this meta key present.', 'fieldradar' ) . '</p>';
		return $html;
	}

	$html .= '<ul>';
	foreach ( $post_ids as $pid ) {
		$value = get_post_meta( $pid, $show_field, true );
		if ( ! fieldradar_value_is_meaningful( $value ) ) {
			continue;
		}
		$post = get_post( $pid );
		if ( ! $post ) {
			continue;
		}
		$permalink = get_edit_post_link( $pid );
		$title     = get_the_title( $pid ) ? get_the_title( $pid ) : sprintf( esc_html__( '(ID %d)', 'fieldradar' ), $pid );
		$html     .= '<li>' . sprintf( '<a href="%s">%s</a> — %s', esc_url( $permalink ), esc_html( $title ), esc_html( fieldradar_value_summary( $value ) ) ) . '</li>';
	}
	$html .= '</ul>';
	return $html;
}

/**
 * Build posts table with pagination for a given meta key.
 *
 * @return string HTML
 */
function fieldradar_build_posts_table_with_pagination_html( $uc_meta_key, $post_type, $page_base, $page_slug, $wpdb ) {
	$html = '';
	$html .= '<h2>' . sprintf( esc_html__( 'Posts with meta key: %s', 'fieldradar' ), esc_html( $uc_meta_key ) ) . '</h2>';

	$per_page = isset( $_GET['uc_per_page'] ) ? max( 1, intval( $_GET['uc_per_page'] ) ) : (int) apply_filters( 'fieldradar_per_page', 20 );
	$paged    = isset( $_GET['uc_page'] ) ? max( 1, intval( $_GET['uc_page'] ) ) : 1;

	$candidate_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT pm.post_id
		 FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
		 WHERE p.post_type = %s AND pm.meta_key = %s
		 ORDER BY pm.post_id ASC",
		$post_type,
		$uc_meta_key
	) );

	$used_post_ids = array();
	foreach ( $candidate_ids as $pid ) {
		$value = get_post_meta( $pid, $uc_meta_key, true );
		if ( fieldradar_value_is_meaningful( $value ) ) {
			$used_post_ids[] = $pid;
		}
	}

	$total_count = count( $used_post_ids );
	if ( 0 === $total_count ) {
		$html .= '<p>' . esc_html__( 'No posts have this meta key populated with a meaningful value.', 'fieldradar' ) . '</p>';
		return $html;
	}

	$total_pages = (int) ceil( $total_count / $per_page );
	if ( $paged > $total_pages ) {
		$paged = $total_pages;
	}
	$offset   = ( $paged - 1 ) * $per_page;
	$page_ids = array_slice( $used_post_ids, $offset, $per_page );

	$posts = get_posts( array(
		'post_type'   => $post_type,
		'post__in'    => $page_ids,
		'orderby'     => 'post__in',
		'numberposts' => $per_page,
	) );

	$html .= '<p>' . esc_html__( 'Total posts:', 'fieldradar' ) . ' <strong>' . intval( $total_count ) . '</strong></p>';
	$html .= '<table class="widefat fixed striped">';
	$html .= '<thead><tr><th style="width:8%">' . esc_html__( 'ID', 'fieldradar' ) . '</th><th>' . esc_html__( 'Post', 'fieldradar' ) . '</th><th style="width:25%">' . esc_html__( 'Action', 'fieldradar' ) . '</th></tr></thead><tbody>';

	foreach ( $posts as $post ) {
		$view_url   = get_permalink( $post->ID );
		$edit_url   = get_edit_post_link( $post->ID );
		$delete_url = get_delete_post_link( $post->ID );

		$html .= '<tr>';
		$html .= '<td>' . intval( $post->ID ) . '</td>';
		$html .= '<td>' . esc_html( get_the_title( $post->ID ) ? get_the_title( $post->ID ) : sprintf( esc_html__( '(ID %d)', 'fieldradar' ), $post->ID ) ) . '</td>';
		$html .= '<td>';
		if ( $view_url ) {
			$html .= '<a href="' . esc_url( $view_url ) . '" target="_blank">' . esc_html__( 'View', 'fieldradar' ) . '</a> ';
		}
		if ( $edit_url ) {
			$html .= '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'fieldradar' ) . '</a> ';
		}
		if ( current_user_can( 'delete_post', $post->ID ) ) {
			$confirm = esc_js( __( 'Are you sure you want to delete this post?', 'fieldradar' ) );
			$html   .= '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_attr( $confirm ) . '\');">' . esc_html__( 'Delete', 'fieldradar' ) . '</a>';
		}
		$html .= '</td>';
		$html .= '</tr>';
	}

	$html .= '</tbody></table>';

	// Pagination links
	$base = add_query_arg( array(
		'page' => $page_slug,
		'uc_post_type' => $post_type,
		'uc_meta_key' => $uc_meta_key,
		'action_view' => 'show_meta',
		'uc_per_page' => $per_page,
	), $page_base );

	$html .= '<div class="tablenav"><div class="tablenav-pages">';
	if ( $paged > 1 ) {
		$prev_url = add_query_arg( 'uc_page', $paged - 1, $base );
		$html    .= '<a class="prev-page" href="' . esc_url( $prev_url ) . '">' . esc_html__( '« Prev', 'fieldradar' ) . '</a> ';
	}

	for ( $i = 1; $i <= $total_pages; $i++ ) {
		if ( $i === $paged ) {
			$html .= '<span class="paging-input"><strong>' . esc_html( $i ) . '</strong></span> ';
		} else {
			$url  = add_query_arg( 'uc_page', $i, $base );
			$html .= '<a href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a> ';
		}
	}

	if ( $paged < $total_pages ) {
		$next_url = add_query_arg( 'uc_page', $paged + 1, $base );
		$html    .= '<a class="next-page" href="' . esc_url( $next_url ) . '">' . esc_html__( 'Next »', 'fieldradar' ) . '</a>';
	}

	$html .= '</div></div>';

	return $html;
}

add_action(
	'admin_menu',
	function() {
		$page_slug  = trim( (string) apply_filters( 'fieldradar_page_slug', 'fieldradar' ) );
		if ( '' === $page_slug ) {
			// Ensure we always have a valid slug to avoid empty page= in URLs.
			$page_slug = 'fieldradar';
		}

		$menu_title = trim( (string) apply_filters( 'fieldradar_menu_title', __( 'FieldRadar', 'fieldradar' ) ) );
		if ( '' === $menu_title ) {
			$menu_title = __( 'FieldRadar', 'fieldradar' );
		}

		$page_title = trim( (string) apply_filters( 'fieldradar_page_title', __( 'FieldRadar', 'fieldradar' ) ) );
		if ( '' === $page_title ) {
			$page_title = __( 'FieldRadar', 'fieldradar' );
		}

		$capability = trim( (string) apply_filters( 'fieldradar_menu_capability', 'manage_options' ) );
		if ( '' === $capability ) {
			$capability = 'manage_options';
		}

		add_management_page(
			$page_title,
			$menu_title,
			$capability,
			$page_slug,
			'fieldradar_admin_page'
		);
	}
);

/**
 * Enqueue admin assets for FieldRadar only on the plugin admin page.
 *
 * @since 1.0.0
 *
 * @return void
 */
function fieldradar_enqueue_admin_assets() {
	$page_slug = trim( (string) apply_filters( 'fieldradar_page_slug', 'fieldradar' ) );
	if ( '' === $page_slug ) {
		$page_slug = 'fieldradar';
	}

	// Only enqueue when viewing our tools page.
	if ( ! isset( $_GET['page'] ) || $page_slug !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
		return;
	}

	wp_enqueue_style(
		'fieldradar-admin',
		plugin_dir_url( __FILE__ ) . 'css/admin.css',
		array(),
		'1.0.0'
	);
}
add_action( 'admin_enqueue_scripts', 'fieldradar_enqueue_admin_assets' );

/**
 * Render admin page
 */
function fieldradar_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;

	// Request vars (sanitised)
	$post_type   = isset( $_GET['uc_post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['uc_post_type'] ) ) : ( isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : 'post' );
	$show_field  = isset( $_GET['field'] ) ? sanitize_text_field( wp_unslash( $_GET['field'] ) ) : '';
	$action      = isset( $_GET['action_view'] ) ? sanitize_text_field( wp_unslash( $_GET['action_view'] ) ) : '';
	$uc_meta_key = isset( $_GET['uc_meta_key'] ) ? sanitize_text_field( wp_unslash( $_GET['uc_meta_key'] ) ) : '';

	$post_types = get_post_types( array( 'public' => true ), 'objects' );

	// Determine page slug (filterable). Ensure it never ends up empty.
	$page_slug = trim( (string) apply_filters( 'fieldradar_page_slug', 'fieldradar' ) );
	if ( '' === $page_slug ) {
		$page_slug = 'fieldradar';
	}

	// Build data required for rendering and delegate to renderer which returns HTML.
	$data = array(
		'post_type'   => $post_type,
		'show_field'  => $show_field,
		'action'      => $action,
		'uc_meta_key' => $uc_meta_key,
		'post_types'  => $post_types,
		'page_slug'   => $page_slug,
		'page_base'   => admin_url( 'tools.php' ),
		'wpdb'        => $wpdb,
	);

	echo fieldradar_get_admin_page_html( $data );
	return;
}

/**
 * Determine whether a stored meta value is 'meaningfully' populated.
 * Returns true if value is not empty string, not an empty array, not null.
 */
/**
 * Determine whether a stored meta value is "meaningfully" populated.
 *
 * Returns true if the value should be considered populated (not empty string,
 * non-empty array, non-null). Filterable via {@see 'fieldradar_value_is_meaningful'}.
 *
 * @since 1.0.0
 *
 * @param mixed $value Value to test.
 * @return bool True when value is considered meaningful.
 */
if ( ! function_exists( 'fieldradar_value_is_meaningful' ) ) {
	/**
	 * Determine whether a stored meta value is "meaningfully" populated.
	 *
	 * Returns true if the value should be considered populated (not empty string,
	 * non-empty array, non-null). Filterable via {@see 'fieldradar_value_is_meaningful'}.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Value to test.
	 * @return bool True when value is considered meaningful.
	 */
	function fieldradar_value_is_meaningful( $value ) {
	if ( is_null( $value ) ) {
		$result = false;
		/** This filter allows plugins/themes to override the meaningfulness check. */
		return (bool) apply_filters( 'fieldradar_value_is_meaningful', $result, $value );
	}

	if ( is_string( $value ) ) {
		$trim   = trim( $value );
		$result = ( '' !== $trim );
		return (bool) apply_filters( 'fieldradar_value_is_meaningful', $result, $value );
	}

	if ( is_array( $value ) ) {
		if ( empty( $value ) ) {
			$result = false;
			return (bool) apply_filters( 'fieldradar_value_is_meaningful', $result, $value );
		}

		// If array contains at least one meaningful value, consider it meaningful.
		foreach ( $value as $v ) {
			if ( fieldradar_value_is_meaningful( $v ) ) {
				$result = true;
				return (bool) apply_filters( 'fieldradar_value_is_meaningful', $result, $value );
			}
		}

		$result = false;
		return (bool) apply_filters( 'fieldradar_value_is_meaningful', $result, $value );
	}

	// For objects/other scalars, consider it meaningful if not empty
	$result = ! empty( $value );
	return (bool) apply_filters( 'fieldradar_value_is_meaningful', $result, $value );
	}
}

/**
 * Create a short one-line summary of the value for display.
 */
/**
 * Create a short one-line summary of the value for display.
 *
 * @since 1.0.0
 *
 * @param mixed $value Value to summarise.
 * @return string Human friendly summary. Filterable via {@see 'fieldradar_value_summary'}.
 */
if ( ! function_exists( 'fieldradar_value_summary' ) ) {
	/**
	 * Create a short one-line summary of the value for display.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Value to summarise.
	 * @return string Human friendly summary. Filterable via {@see 'fieldradar_value_summary'}.
	 */
	function fieldradar_value_summary( $value ) {
	if ( is_null( $value ) ) {
		$summary = '(empty)';
		return (string) apply_filters( 'fieldradar_value_summary', $summary, $value );
	}

	if ( is_string( $value ) ) {
		$s       = wp_trim_words( $value, 10, '...' );
		$summary = $s;
		return (string) apply_filters( 'fieldradar_value_summary', $summary, $value );
	}

	if ( is_array( $value ) ) {
		$summary = 'Array(' . count( $value ) . ')';
		return (string) apply_filters( 'fieldradar_value_summary', $summary, $value );
	}

	if ( is_object( $value ) ) {
		$summary = 'Object';
		return (string) apply_filters( 'fieldradar_value_summary', $summary, $value );
	}

	$summary = (string) $value;
	return (string) apply_filters( 'fieldradar_value_summary', $summary, $value );
	}
}

