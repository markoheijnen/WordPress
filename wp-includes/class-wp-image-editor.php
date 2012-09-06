<?php

final class WP_Image_Editor {

	public static function get_instance( $path ) {
		$implementation = apply_filters( 'image_editor_class', self::choose_implementation(), $path );

		if ( $implementation )
			return new $implementation( $path );

		return false;
	}

	/**
	 * Tests which editors are capable of supporting the request.
	 *
	 * @since 3.5.0
	 * @access private
	 *
	 * @return string|bool Class name for the first editor that claims to support the request. False if no editor claims to support the request.
	 */
	private static function choose_implementation() {
		static $implementation;

		if ( null === $implementation ) {
			$request_order = apply_filters( 'wp_editors', array( 'imagick', 'gd' ) );

			// Loop over each editor on each request looking for one which will serve this request's needs
			foreach ( $request_order as $editor ) {
				$class = 'WP_Image_Editor_' . $editor;

				// Check to see if this editor is a possibility, calls the editor statically
				if ( ! call_user_func( array( $class, 'test' ) ) )
					continue;

				if( ! apply_filters( 'wp_editor_use_' . $editor, true ) )
					continue;

				$implementation = $class;

				break;
			}
		}

		return $implementation;
	}
}