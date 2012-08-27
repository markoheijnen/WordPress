<?php

class WP_Image_Editor_Base {
	protected $file = false;
	protected $size = false;
	protected $orig_type  = false;
	protected $quality = 90;

	protected $dest_size = false;

	function __construct( $filename ) {
		$this->file = $filename;
	}

	public static function test() {
		return false;
	}

	public function set_quality( $quality ) {
		$this->quality = apply_filters( 'wp_editor_set_quality', $quality );
	}
}