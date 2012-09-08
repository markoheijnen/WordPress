<?php

abstract class WP_Image_Editor_Base {
	protected $file = null;
	protected $size = null;
	protected $orig_type  = null;
	protected $quality = 90;

	function __construct( $filename ) {
		$this->file = $filename;
	}

	public static function test() {
		return false;
	}

	protected function load() {
		return false;
	}

	public function get_size() {
		if ( ! $this->load() )
			return false;

		return $this->size;
	}

	protected function update_size( $width = null, $height = null ) {
		$this->size = array(
			'width' => $width,
			'height' => $height
		);
	}

	public function set_quality( $quality ) {
		$this->quality = apply_filters( 'wp_editor_set_quality', $quality );
	}

	public function generate_filename( $suffix = null, $dest_path = null ) {
		if ( ! $this->load() )
			return false;

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
		if ( ! $this->get_size() )
			return false;

		return "{$this->size['width']}x{$this->size['height']}";
	}
}