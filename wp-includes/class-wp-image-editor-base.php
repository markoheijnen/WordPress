<?php

abstract class WP_Image_Editor_Base {
	protected $file = null;
	protected $size = null;
	protected $orig_type  = null;
	protected $quality = 90;

	function __construct( $filename ) {
		$this->file = $filename;
	}

	abstract public static function test();
	abstract protected function load();
	abstract public function resize( $max_w, $max_h, $crop = false );
	abstract public function multi_resize( $sizes );
	abstract public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false );
	abstract public function rotate( $angle );
	abstract public function flip( $horz, $vert );
	abstract public function save( $destfilename = null );
	abstract public function stream();

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