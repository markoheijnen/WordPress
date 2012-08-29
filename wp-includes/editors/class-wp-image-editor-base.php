<?php

class WP_Image_Editor_Base {
	protected $file = false;
	protected $size = false;
	protected $orig_type  = false;
	protected $quality = 90;

	function __construct( $filename ) {
		$this->file = $filename;
	}

	public static function test() {
		return false;
	}

	public function get_size() {
		return $this->size;
	}

	public function set_quality( $quality ) {
		$this->quality = apply_filters( 'wp_editor_set_quality', $quality );
	}

	public function generate_filename( $suffix = null, $dest_path = null ) {
		// $suffix will be appended to the destination filename, just before the extension
		$suffix = $this->get_suffix();

		$info = pathinfo( $this->file );
		$dir  = $info['dirname'];
		$ext  = $info['extension'];
		$name = wp_basename( $this->file, ".$ext" );

		if ( ! is_null( $dest_path ) && $_dest_path = realpath( $dest_path ) )
			$dir = $_dest_path;

		return "{$dir}/{$name}-{$suffix}.{$ext}";
	}

	public function get_suffix() {
		return "{$this->size['width']}x{$this->size['height']}";
	}
}