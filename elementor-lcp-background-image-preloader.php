<?php
/**
 * Plugin Name: Elementor LCP Background Image Preloader
 * Plugin URI: https://github.com/westonruter/elementor-lcp-background-image-preloader
 * Description: Optimizes the loading of the background image added by Elementor LCP image loading with <code>fetchpriority=high</code> and applies image lazy-loading by leveraging client-side detection with real user metrics.
 * Requires at least: 6.5
 * Requires PHP: 7.2
 * Requires Plugins: optimization-detective
 * Version: 0.1.0
 * Author: Weston Ruter
 * Author URI: https://weston.ruter.net/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: elementor-lcp-background-image-preloader
 *
 * @package elementor-lcp-background-image-preloader
 */

namespace ElementorLcpBackgroundImagePreloader;

use OD_Tag_Visitor_Registry;
use OD_Tag_Visitor_Context;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( __NAMESPACE__ . '\VERSION' ) ) {
	return;
}

const VERSION = '0.1.0';

/**
 * Displays the HTML generator meta tag for the Image Prioritizer plugin.
 *
 * See {@see 'wp_head'}.
 *
 * @since 0.1.0
 */
function render_generator_meta_tag(): void {
	// Use the plugin slug as it is immutable.
	echo '<meta name="generator" content="elementor-lcp-background-image-preloader ' . esc_attr( VERSION ) . '">' . "\n";
}
add_action( 'wp_head', __NAMESPACE__ . '\render_generator_meta_tag' );

/**
 * Registers tag visitors for images.
 *
 * @since 0.1.0
 *
 * @param OD_Tag_Visitor_Registry $registry Tag visitor registry.
 */
function register_tag_visitors( OD_Tag_Visitor_Registry $registry ): void {
	$registry->register(
		'elementor-lcp-background-image-preloader',
		__NAMESPACE__ . '\visit_tag'
	);
}
add_action( 'od_register_tag_visitors', __NAMESPACE__ . '\register_tag_visitors' );

/**
 * Visits a tag.
 *
 * @since 0.1.0
 *
 * @param OD_Tag_Visitor_Context $context Tag visitor context.
 *
 * @return bool Whether the tag should be tracked in URL metrics.
 */
function visit_tag( OD_Tag_Visitor_Context $context ): bool {
	$processor = $context->processor;

	if ( ! (
		true === $processor->has_class( 'elementor-element' )
		&&
		is_string( $processor->get_attribute( 'data-id' ) )
	) ) {
		return false;
	}

	$element_id        = $processor->get_attribute( 'data-id' );
	$background_images = get_elementor_element_background_images( $element_id );
	$xpath             = $processor->get_xpath();
	foreach ( $context->url_metrics_group_collection->get_groups_by_lcp_element( $xpath ) as $group ) {
		foreach ( $background_images as $background_image ) {
			if (
				$background_image['min_width'] >= $group->get_minimum_viewport_width()
				&&
				$background_image['max_width'] >= $group->get_maximum_viewport_width()
			) {

				$link_attributes = array(
					'rel'           => 'preload',
					'fetchpriority' => 'high',
					'as'            => 'image',
					'href'          => $background_image['src'],
					'media'         => 'screen',
				);

				$context->link_collection->add_link(
					$link_attributes,
					$group->get_minimum_viewport_width(),
					$group->get_maximum_viewport_width()
				);
			}
		}
	}

	return true; // Track the element in URL Metrics.
}

/**
 * Gets Elementor element's background images.
 *
 * @todo This code is entirely untested. It is adapted from code returned from a Gemini response.
 *
 * @since 0.1.0
 *
 * @param string $element_id Element ID.
 * @return array<array{src: non-empty-string, min_width: int, max_width: int}> Background images.
 */
function get_elementor_element_background_images( string $element_id ): array {
	// TODO: This is not working. It returns null.
	$document = \Elementor\Plugin::$instance->documents->get_current();

	$element = find_element_by_id( $document->get_elements_data(), $element_id );
	if ( null === $element || ! isset( $element['settings'] ) ) {
		return array();
	}

	$settings          = $element['settings'];
	$background_images = array();

	// Check if the element has a background image.
	if ( isset( $settings['background_image']['url'] ) ) {
		$background_images[] = array(
			'src'       => $settings['background_image']['url'],
			'min_width' => 0,
			'max_width' => PHP_INT_MAX,
		);
	}

	$breakpoints = (array) \Elementor\Plugin::$instance->breakpoints->get_active_breakpoints();
	foreach ( $breakpoints as $breakpoint_key => $breakpoint_instance ) {
		$breakpoint_name = $breakpoint_instance->get_name();
		if ( ! isset( $settings[ 'background_image_' . $breakpoint_name ]['url'] ) ) {
			continue;
		}

		$background_images[] = array(
			'src'       => $settings[ 'background_image_' . $breakpoint_name ]['url'],
			'min_width' => $breakpoint_instance->get_value(),
			'max_width' => ( isset( $breakpoints[ $breakpoint_key + 1 ] ) ? $breakpoints[ $breakpoint_key + 1 ]->get_value() - 1 : PHP_INT_MAX ),
		);
	}

	return $background_images;
}

/**
 * Recursively find an element by its ID in the Elementor elements data array.
 *
 * @todo This has not been tested. It was adapted by Gemini.
 *
 * @since 0.1.0
 *
 * @param array<int, array{id: string, settings: array<string, mixed>}> $elements   The Elementor elements data array.
 * @param string                                                        $element_id The ID of the element to find.
 * @return array{id: string, settings: array<string, mixed>}|null Element data.
 */
function find_element_by_id( array $elements, string $element_id ): ?array {
	foreach ( $elements as $element ) {
		if ( $element['id'] === $element_id ) {
			return $element;
		}

		if ( isset( $element['elements'] ) ) {
			$found_element = find_element_by_id( $element['elements'], $element_id );
			if ( is_array( $found_element ) ) {
				return $found_element;
			}
		}
	}
	return null;
}
