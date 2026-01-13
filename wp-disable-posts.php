<?php

/*
 * Plugin Name:     Disable Posts
 * Plugin URI:      https://github.com/wvnderlab-agency/disable-posts/
 * Author:          Wvnderlab Agency
 * Author URI:      https://wvnderlab.com
 * Text Domain:     wvnderlab-disable-posts
 * Version:         0.1.0
 */

/*
 *  ################
 *  ##            ##    Copyright (c) 2025 Wvnderlab Agency
 *  ##
 *  ##   ##  ###  ##    ✉️ moin@wvnderlab.com
 *  ##    #### ####     🔗 https://wvnderlab.com
 *  #####  ##  ###
 */

declare(strict_types=1);

namespace WvnderlabAgency\DisablePosts;

use WP_Admin_Bar;
use WP_Query;

defined( 'ABSPATH' ) || die;

// Return early if running in WP-CLI context.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	return;
}

/**
 * Filter: Disable Posts Enabled
 *
 * @param bool $enabled Whether to enable the disable posts functionality. Default true.
 * @return bool
 */
if ( ! apply_filters( 'wvnderlab/disable-posts/enabled', true ) ) {
	return;
}

/**
 * Disable or redirects any post.
 *
 * @link   https://developer.wordpress.org/reference/hooks/template_redirect/
 * @hooked action template_redirect
 *
 * @return void
 */
function disable_or_redirect_post(): void {
	// return early if not an individual post.
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	// return early if in admin, ajax, cron, rest api or wp-cli context.
	if (
		is_admin()
		|| wp_doing_ajax()
		|| wp_doing_cron()
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
	) {
		return;
	}

	/**
	 * Filter: Disable Posts Status Code
	 *
	 * Supported:
	 * - 301 / 302 / 307 / 308  → redirect
	 * - 404 / 410              → no redirect, proper error response
	 *
	 * @param int $status_code The HTTP status code for the redirect. Default is 404 (Not Found).
	 * @return int
	 */
	$status_code = (int) apply_filters(
		'wvnderlab/disable-posts/status-code',
		404
	);

	// Handle 404 and 410 status codes separately.
	if ( in_array( $status_code, array( 404, 410 ), true ) ) {
		global $wp_query;

		$wp_query->set_404();
		status_header( $status_code );
		nocache_headers();

		$template = get_query_template( '404' );

		if ( $template ) {
			include $template;
		} else {
			wp_die(
				esc_html__( '404 Not Found', 'wvnderlab-disable-posts' ),
				esc_html__( 'Not Found', 'wvnderlab-disable-posts' ),
				array( 'response' => esc_html( $status_code ) )
			);
		}

		exit;
	}

	// Ensure the status code is a valid redirect code.
	if ( $status_code < 300 || $status_code > 399 ) {
		$status_code = 301;
	}

	/**
	 * Filter: Disable Posts Redirect URL
	 *
	 * Allows modification of the redirect URL for disabled posts.
	 *
	 * @param string $redirect_url The URL to redirect to. Default is the homepage.
	 * @return string
	 */
	$redirect_url = (string) apply_filters(
		'wvnderlab/disable-posts/redirect-url',
		home_url()
	);

	// Ensure the redirect URL is not empty.
	if ( empty( $redirect_url ) ) {
		$redirect_url = home_url();
	}

	wp_safe_redirect( $redirect_url, $status_code );

	exit;
}

add_action( 'template_redirect', __NAMESPACE__ . '\\disable_or_redirect_post', PHP_INT_MIN );

/**
 * Exclude Posts and Post Taxonomies from Frontend Queries
 *
 * @link   https://developer.wordpress.org/reference/hooks/pre_get_posts/
 * @hooked pre_get_posts
 *
 * @param WP_Query $query The WP_Query instance (passed by reference).
 * @return void
 */
function exclude_posts_and_taxonomies_from_queries( WP_Query $query ): void {
	// return early if in admin or not main query.
	if ( is_admin() || ! $query->is_main_query() ) {

		return;
	}

	if ( $query->is_search() || $query->is_archive() || $query->is_home() || $query->is_feed() ) {
		$post_types = $query->get( 'post_type' );
		// normalize post_types to array.
		if ( ! $post_types ) {
			$post_types = array( 'post' );
		} elseif ( is_string( $post_types ) ) {
			$post_types = array( $post_types );
		}

		// remove 'post' from the array of post types.
		$post_types = array_diff( $post_types, array( 'post' ) );

		if ( ! empty( $post_types ) ) {
			$query->set( 'post_type', $post_types );
		}
	}

	if ( $query->is_category() || $query->is_tag() || $query->is_tax() ) {
		$query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}

add_action( 'pre_get_posts', __NAMESPACE__ . '\\exclude_posts_and_taxonomies_from_queries', PHP_INT_MAX );

/**
 * Redirect Comments Templates
 *
 * @link   https://developer.wordpress.org/reference/hooks/admin_init/
 * @hooked action admin_init
 *
 * @return void
 * @global string $pagenow
 */
function redirect_admin_posts_templates(): void {
	global $pagenow;
	global $typenow;

	// return early if doing ajax.
	if ( wp_doing_ajax() ) {

		return;
	}

	$is_post_edit = ( 'post.php' === $pagenow && 'post' === get_post_type() );
	$is_post_list = ( 'edit.php' === $pagenow && in_array( $typenow, array( '', 'post' ), true ) );
	$is_post_new  = ( 'post-new.php' === $pagenow && in_array( $typenow, array( '', 'post' ), true ) );

	if (
		$is_post_edit
		|| $is_post_list
		|| $is_post_new
	) {
		wp_safe_redirect( admin_url(), 301 );
		exit;
	}
}

add_action( 'admin_init', __NAMESPACE__ . '\\redirect_admin_posts_templates', PHP_INT_MIN );

/**
 * Remove Dashboard Metaboxes for Posts
 *
 * @hooked action admin_init
 *
 * @return void
 */
function remove_dashboard_metaboxes(): void {
	remove_meta_box( 'dashboard_quick_press', 'dashboard', 'normal' );
}

add_action( 'admin_init', __NAMESPACE__ . '\\remove_dashboard_metaboxes', PHP_INT_MAX );

/**
 * Remove Posts Admin Bar Node
 *
 * @link   https://developer.wordpress.org/reference/hooks/admin_bar_menu/
 * @hooked action admin_bar_menu
 *
 * @param WP_Admin_Bar $admin_bar The WP_Admin_Bar instance.
 * @return  void
 */
function remove_posts_admin_bar_node( WP_Admin_Bar $admin_bar ): void {
	if ( is_admin_bar_showing() ) {
		$admin_bar->remove_node( 'new-post' );
	}
}

add_action( 'admin_bar_menu', __NAMESPACE__ . '\\remove_posts_admin_bar_node', PHP_INT_MAX );

/**
 * Remove Posts and Post Taxonomies Menu Pages
 *
 * @link   https://developer.wordpress.org/reference/hooks/admin_menu/
 * @hooked action admin_menu
 *
 * @return void
 */
function remove_posts_admin_menu_pages(): void {
	remove_menu_page( 'edit.php' );
	remove_menu_page( 'edit-tags.php?taxonomy=category' );
	remove_menu_page( 'edit-tags.php?taxonomy=post_tag' );
}

add_action( 'admin_menu', __NAMESPACE__ . '\\remove_posts_admin_menu_pages', PHP_INT_MAX );

/**
 * Remove REST Posts and Post Taxonomies Endpoints
 *
 * @link   https://developer.wordpress.org/reference/hooks/rest_endpoints/
 * @hooked filter rest_endpoints
 *
 * @param array<string,mixed> $endpoints The REST API endpoints.
 * @return array<string,mixed>
 */
function remove_posts_and_taxonomies_endpoint( array $endpoints ): array {
	if ( isset( $endpoints['/wp/v2/posts'] ) ) {
		unset( $endpoints['/wp/v2/posts'] );
	}
	if ( isset( $endpoints['/wp/v2/posts/(?P<id>[\d]+)'] ) ) {
		unset( $endpoints['/wp/v2/posts/(?P<id>[\d]+)'] );
	}
	if ( isset( $endpoints['/wp/v2/categories'] ) ) {
		unset( $endpoints['/wp/v2/categories'] );
	}
	if ( isset( $endpoints['/wp/v2/categories/(?P<id>[\d]+)'] ) ) {
		unset( $endpoints['/wp/v2/categories/(?P<id>[\d]+)'] );
	}
	if ( isset( $endpoints['/wp/v2/tags'] ) ) {
		unset( $endpoints['/wp/v2/tags'] );
	}
	if ( isset( $endpoints['/wp/v2/tags/(?P<id>[\d]+)'] ) ) {
		unset( $endpoints['/wp/v2/tags/(?P<id>[\d]+)'] );
	}

	return $endpoints;
}

add_filter( 'rest_endpoints', __NAMESPACE__ . '\\remove_posts_and_taxonomies_endpoint' );

/**
 * Remove XMLRPC Posts and Post Taxonomies Methods
 *
 * @link   https://developer.wordpress.org/reference/hooks/xmlrpc_methods/
 * @hooked filter xmlrpc_methods
 *
 * @param array<string,string> $methods The XMLRPC methods.
 * @return array<string,string>
 */
function remove_xmlrpc_posts_and_taxonomies_methods( array $methods ): array {
	unset(
		// WordPress API.
		$methods['wp.deletePost'],
		$methods['wp.editPost'],
		$methods['wp.getPosts'],
		$methods['wp.getPost'],
		$methods['wp.newPost'],
		$methods['wp.getTags'],
		$methods['wp.getCategories'],
		$methods['wp.newCategory'],
		$methods['wp.deleteCategory'],
		$methods['wp.suggestCategories'],
		// Blogger API.
		$methods['blogger.deletePost'],
		$methods['blogger.editPost'],
		$methods['blogger.getPost'],
		$methods['blogger.getRecentPosts'],
		$methods['blogger.newPost'],
		// MetaWeblog API (with MT extensions to structs).
		$methods['metaWeblog.deletePost'],
		$methods['metaWeblog.editPost'],
		$methods['metaWeblog.getPost'],
		$methods['metaWeblog.getRecentPosts'],
		$methods['metaWeblog.newPost'],
		$methods['metaWeblog.getCategories'],
		// MovableType API.
		$methods['mt.getCategoryList'],
		$methods['mt.getRecentPostTitles'],
		$methods['mt.getPostCategories'],
		$methods['mt.setPostCategories'],
		$methods['mt.publishPost']
	);

	return $methods;
}

add_filter( 'xmlrpc_methods', __NAMESPACE__ . '\\remove_xmlrpc_posts_and_taxonomies_methods', PHP_INT_MAX );


/**
 * Unregister Posts and Post Taxonomies Blocks
 *
 * @link   https://developer.wordpress.org/reference/hooks/init/
 * @hooked action init
 *
 * @return void
 */
function unregister_posts_and_taxonomies_blocks(): void {
	unregister_block_type( 'core/latest-posts' );
	unregister_block_type( 'core/post-title' );
	unregister_block_type( 'core/post-content' );
	unregister_block_type( 'core/post-excerpt' );
	unregister_block_type( 'core/post-featured-image' );
	unregister_block_type( 'core/post-date' );
	unregister_block_type( 'core/post-author' );
	unregister_block_type( 'core/post-terms' );
}

add_action( 'init', __NAMESPACE__ . '\\unregister_posts_and_taxonomies_blocks', PHP_INT_MAX );

/**
 * Unregister Posts and Post Taxonomies Widget
 *
 * @link   https://developer.wordpress.org/reference/hooks/widgets_init/
 * @hooked action widgets_init
 *
 * @return void
 */
function unregister_posts_and_taxonomies_widgets(): void {
	unregister_widget( 'WP_Widget_Recent_Posts' );
	unregister_widget( 'WP_Widget_Categories' );
	unregister_widget( 'WP_Widget_Tag_Cloud' );
}

add_action( 'widgets_init', __NAMESPACE__ . '\\unregister_posts_and_taxonomies_widgets', PHP_INT_MAX );
