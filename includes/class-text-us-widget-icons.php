<?php
/**
 * Loads Lucide SVG icons bundled in the plugin's /icons/ folder.
 *
 * Lucide is ISC licensed (some icons MIT, via Feather). Keep icons/LICENSE
 * with the icons. Browse the set at https://lucide.dev/icons.
 *
 * @package Text_Us_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Icon helper.
 */
class Text_Us_Widget_Icons {

	/**
	 * Return an inline SVG icon, sized and ready to echo.
	 *
	 * The bundled Lucide icons use `stroke="currentColor"`, so the icon
	 * inherits the colour of whatever element it is placed inside.
	 *
	 * @param string $name  Icon file name (without extension), e.g. "sliders-horizontal".
	 * @param int    $size  Width/height in pixels.
	 * @param string $class Extra CSS class(es) to add to the <svg>.
	 * @return string Inline SVG markup, or an empty string if the icon is missing.
	 */
	public static function get( $name, $size = 20, $class = '' ) {
		$name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $name ) );

		if ( '' === $name ) {
			return '';
		}

		$file = TEXT_US_WIDGET_DIR . 'icons/' . $name . '.svg';

		if ( ! is_readable( $file ) ) {
			return '';
		}

		$svg = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $svg ) {
			return '';
		}

		// Collapse whitespace between tags so the markup is safe to drop anywhere.
		$svg = preg_replace( '/>\s+</', '><', trim( $svg ) );

		// Drop the icon's own width/height so our size wins.
		$svg = preg_replace( '/\s(?:width|height)="[^"]*"/', '', $svg, 2 );

		$size    = absint( $size );
		$classes = trim( 'text-us-widget-icon ' . $class );
		$attrs   = sprintf(
			' width="%1$d" height="%1$d" class="%2$s" aria-hidden="true" focusable="false"',
			$size,
			esc_attr( $classes )
		);

		return preg_replace( '/<svg/', '<svg' . $attrs, $svg, 1 );
	}

	/**
	 * Return an inline Simple Icons brand logo from icons/simpleicons/.
	 *
	 * The logos are filled shapes with no color of their own, so they take
	 * the surrounding text color too.
	 *
	 * @param string $name  File name (without extension), e.g. "apple".
	 * @param int    $size  Width/height in pixels.
	 * @param string $class Extra CSS class(es) to add to the <svg>.
	 * @return string Inline SVG markup, or an empty string if the logo is missing.
	 */
	public static function brand( $name, $size = 20, $class = '' ) {
		$name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $name ) );
		$file = TEXT_US_WIDGET_DIR . 'icons/simpleicons/' . $name . '.svg';

		if ( '' === $name || ! is_readable( $file ) ) {
			return '';
		}

		$svg = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $svg ) {
			return '';
		}

		// The <title> would be read out; the icons are decorative.
		$svg   = preg_replace( '#<title>.*?</title>#s', '', trim( $svg ) );
		$size  = absint( $size );
		$attrs = sprintf(
			' width="%1$d" height="%1$d" fill="currentColor" class="%2$s" aria-hidden="true" focusable="false"',
			$size,
			esc_attr( trim( 'text-us-widget-icon ' . $class ) )
		);

		return preg_replace( '/<svg role="img"/', '<svg' . $attrs, $svg, 1 );
	}
}
