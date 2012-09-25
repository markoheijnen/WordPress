<?php

abstract class WP_Image_Editor {
	protected $file = null;
	protected $size = null;
	protected $mime_type  = null;
	protected $default_mime_type = 'image/jpeg';
	protected $quality = 90;

	protected function __construct( $filename ) {
		$this->file = $filename;
	}

	/**
	 * Returns a WP_Image_Editor instance and loads $path into it.
	 *
	 * @since 3.5.0
	 *
	 * @param string $path
	 * @return WP_Image_Editor|WP_Error|boolean
	 */
	public final static function get_instance( $path ) {
		$implementation = apply_filters( 'image_editor_class', self::choose_implementation(), $path );

		if ( $implementation ) {
			$editor = new $implementation( $path );
			$loaded = $editor->load();

			if ( is_wp_error ( $loaded ) )
				return $loaded;

			return $editor;
		}

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
	private final static function choose_implementation() {
		static $implementation;

		if ( null === $implementation ) {
			$request_order = apply_filters( 'wp_editors', array( 'imagick', 'gd' ) );

			// Loop over each editor on each request looking for one which will serve this request's needs
			foreach ( $request_order as $editor ) {
				$class = 'WP_Image_Editor_' . $editor;

				// Check to see if this editor is a possibility, calls the editor statically
				if ( ! call_user_func( array( $class, 'test' ) ) )
					continue;

				$implementation = $class;
				break;
			}
		}
		return $implementation;
	}

	abstract public static function test();
	abstract protected function load();
	abstract protected function supports_mime_type( $mime_type); // returns bool
	abstract public function resize( $max_w, $max_h, $crop = false );
	abstract public function multi_resize( $sizes );
	abstract public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false );
	abstract public function rotate( $angle );
	abstract public function flip( $horz, $vert );
	abstract public function save( $destfilename = null, $mime_type = null );
	abstract public function stream( $mime_type = null );

	/**
	 * Gets dimensions of image
	 *
	 * @since 3.5.0
	 *
	 * @return array { 'width'=>int, 'height'=>int }
	 */
	public function get_size() {
		return $this->size;
	}

	/**
	 * Sets current image size
	 *
	 * @since 3.5.0
	 *
	 * @param int $width
	 * @param int $height
	 */
	protected function update_size( $width = null, $height = null ) {
		$this->size = array(
			'width' => $width,
			'height' => $height
		);
		return true;
	}

	/**
	 * Sets Image Compression quality on a 1-100% scale.
	 *
	 * @since 3.5.0
	 *
	 * @param int $quality 1-100
	 * @return boolean
	 */
	public function set_quality( $quality ) {
		$this->quality = apply_filters( 'wp_editor_set_quality', $quality );

		return ( (bool) $this->quality );
	}

	protected function get_output_format( $filename = null, $mime_type = null ) {
		$new_ext = null;

		if ( $mime_type ) {
			$new_ext = $this->get_extension( $mime_type );
		}
		else if ( $filename ) {
			$new_ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$mime_type = $this->get_mime_type( $new_ext );
		}
		else {
			// Use the editor's current file and mime-type.
			$new_ext = strtolower( pathinfo( $this->file, PATHINFO_EXTENSION ) );
			$mime_type = $this->mime_type;
		}

		// Double-check that the mime-type selected is supported by the editor.
		// If not, choose a default instead.
		if ( ! $this->supports_mime_type( $mime_type ) ) {
			$mime_type = apply_filters( 'image_editor_default_mime_type', $this->default_mime_type );
			$new_ext = $this->get_extension( $mime_type );
		}

		if ( $filename ) {
			$info = pathinfo( $filename );
			$dir  = $info['dirname'];
			$ext  = $info['extension'];

			$filename = $dir.DIRECTORY_SEPARATOR.wp_basename( $filename, ".$ext" ).".{$new_ext}";
		}

		return array( $filename, $new_ext, $mime_type );
	}

	public function generate_filename( $suffix = null, $dest_path = null, $extension = null ) {
		// $suffix will be appended to the destination filename, just before the extension
		if ( ! $suffix )
			$suffix = $this->get_suffix();

		$info = pathinfo( $this->file );
		$dir  = $info['dirname'];
		$ext  = $info['extension'];

		$name = wp_basename( $this->file, ".$ext" );
		$new_ext = strtolower( $extension ? $extension : $ext );

		if ( ! is_null( $dest_path ) && $_dest_path = realpath( $dest_path ) )
			$dir = $_dest_path;

		return $dir.DIRECTORY_SEPARATOR."{$name}-{$suffix}.{$new_ext}";
	}

	public function get_suffix() {
		if ( ! $this->get_size() )
			return false;

		return "{$this->size['width']}x{$this->size['height']}";
	}

	protected function make_image( $filename, $function, $arguments ) {
		$dst_file = $filename;

		if ( $stream = wp_is_stream( $filename ) ) {
			$filename = null;
			ob_start();
		}

		$result = call_user_func_array( $function, $arguments );

		if( $result && $stream ) {
			$contents = ob_get_contents();

			$fp = fopen( $dst_file, 'w' );

			if( ! $fp )
				return false;

			fwrite( $fp, $contents );
			fclose( $fp );
		}

		if( $stream ) {
			ob_end_clean();
		}

		return $result;
	}

	static protected function get_mime_type( $extension ) {
		$mime_types = wp_get_mime_types();
		$extensions = array_keys( $mime_types );

		foreach( $extensions as $_extension ) {
			if( preg_match("/{$extension}/i", $_extension ) ) {
				return $mime_types[ $_extension ];
			}
		}

		return false;
	}

	static protected function get_extension( $mime_type ) {
		$extensions = explode( '|', array_search( $mime_type, wp_get_mime_types() ) );

		return $extensions[0];
	}
}