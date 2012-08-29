<?php

class WP_Image_Editor_GD extends WP_Image_Editor_Base {
	private $image = false;

	function __destruct() {
		if ( $this->image ) {
			// we don't need the original in memory anymore
			imagedestroy( $this->image );
		}
	}

	public static function test() {
		if ( ! extension_loaded('gd') || ! function_exists('gd_info') )
			return false;

		return true;
	}

	private function load() {
		if ( $this->image )
			return true;

		if ( ! file_exists( $this->file ) )
			return sprintf( __('File &#8220;%s&#8221; doesn&#8217;t exist?'), $this->file );

		if ( ! function_exists('imagecreatefromstring') )
			return __('The GD image library is not installed.');

		// Set artificially high because GD uses uncompressed images in memory
		@ini_set( 'memory_limit', apply_filters( 'image_memory_limit', WP_MAX_MEMORY_LIMIT ) );
		$this->image = imagecreatefromstring( file_get_contents( $this->file ) );

		if ( ! is_resource( $this->image ) )
			return sprintf( __('File &#8220;%s&#8221; is not an image.'), $this->file );

		$size = @getimagesize( $this->file );
		if ( ! $size )
			return new WP_Error( 'invalid_image', __('Could not read image size'), $this->file );

		$this->update_size( $size[0], $size[1] );
		$this->orig_type = $size['mime'];

		return true;
	}

	public function get_size() {
		if ( ! $this->load() )
			return;

		return parent::get_size();
	}

	protected function update_size( $width = false, $height = false ) {
		if ( ! $this->load() )
			return;

		$this->size = array(
			'width' => $width ?: imagesx( $this->image ),
			'height' => $height ?: imagesy( $this->image )
		);
	}

	public function resize( $max_w, $max_h, $crop = false ) {
		if ( ! $this->load() )
			return;

		$dims = image_resize_dimensions( $this->size['width'], $this->size['height'], $max_w, $max_h, $crop );
		if ( ! $dims )
			return new WP_Error( 'error_getting_dimensions', __('Could not calculate resized image dimensions') );
		list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

		$resized = wp_imagecreatetruecolor( $dst_w, $dst_h );
		imagecopyresampled( $resized, $this->image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h );

		if ( is_resource( $resized ) ) {
			imagedestroy( $this->image ); 
			$this->image = $resized;
			$this->update_size( $dst_w, $dst_h );
			return true;
		}
	}

	/**
	 * Ported from image.php
	 *
	 * @param type $x
	 * @param type $y
	 * @param type $w
	 * @param type $h
	 * @return boolean
	 */
	public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false ) {
		if ( ! $this->load() )
			return;

		// If destination width/height isn't specified, use same as
		// width/height from source.
		$dst_w = $dst_w ?: $src_w;
		$dst_h = $dst_h ?: $src_h;
		$dst = wp_imagecreatetruecolor( $dst_w, $dst_h );

		if ( $src_abs ) {
			$src_w -= $src_x;
			$src_h -= $src_y;
		}

		if ( function_exists( 'imageantialias' ) )
			imageantialias( $dst, true );

		imagecopyresampled( $dst, $this->image, 0, 0, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h );

		if ( is_resource( $dst ) ) {
			imagedestroy( $this->image );
			$this->image = $dst;
			$this->update_size( $dst_w, $dst_h );
			return true;
		}

		return false; // @TODO: WP_Error here.
	}

	/**
	 * Ported from image-edit.php
	 *
	 * @param float $angle
	 * @return boolean 
	 */
	public function rotate( $angle ) {
		if ( ! $this->load() )
			return;

		if ( function_exists('imagerotate') ) {
			$rotated = imagerotate( $this->image, $angle, 0 );

			if ( is_resource( $rotated ) ) {
				imagedestroy( $this->image ); 
				$this->image = $rotated;
				$this->update_size();
				return true;
			}
		}
		return false; // @TODO: WP_Error here.
	}

	/**
	 * Ported from image-edit.php
	 *
	 * @param type $horz
	 * @param type $vert 
	 */
	public function flip( $horz, $vert ) {
		if ( ! $this->load() )
			return;

		$w = $this->size['width'];
		$h = $this->size['height'];
		$dst = wp_imagecreatetruecolor( $w, $h );

		if ( is_resource( $dst ) ) {
			$sx = $vert ? ($w - 1) : 0;
			$sy = $horz ? ($h - 1) : 0;
			$sw = $vert ? -$w : $w;
			$sh = $horz ? -$h : $h;

			if ( imagecopyresampled( $dst, $this->image, 0, 0, $sx, $sy, $w, $h, $sw, $sh ) ) {
				imagedestroy( $this->image );
				$this->image = $dst;
				return true;
			}
		}

		return false; // @TODO: WP_Error here.
	}

	public function save( $destfilename = null ) {
		if ( ! $this->load() )
			return;

		if ( null == $destfilename ) {
			$destfilename = $this->generate_filename();
		}

		if ( 'image/gif' == $this->orig_type ) {
			if ( ! $this->make_image( 'imagegif', $this->image, $destfilename ) )
				return new WP_Error( 'resize_path_invalid', __( 'Resize path invalid' ) );
		}
		elseif ( 'image/png' == $this->orig_type ) {
			// convert from full colors to index colors, like original PNG.
			if ( function_exists('imageistruecolor') && ! imageistruecolor( $this->image ) )
				imagetruecolortopalette( $this->image, false, imagecolorstotal( $this->image ) );

			if ( ! $this->make_image( 'imagepng', $this->image, $destfilename ) )
				return new WP_Error( 'resize_path_invalid', __( 'Resize path invalid' ) );
		}
		else {
			// all other formats are converted to jpg
			$destfilename = preg_replace( '/\\.[^\\.]+$/', '.jpg', $destfilename );

			if ( ! ! $this->make_image( 'imagejpeg', $this->image, $destfilename, apply_filters( 'jpeg_quality', $this->quality, 'image_resize' ) ) )
				return new WP_Error( 'resize_path_invalid', __( 'Resize path invalid' ) );
		}

		// Set correct file permissions
		$stat = stat( dirname( $destfilename ) );
		$perms = $stat['mode'] & 0000666; //same permissions as parent folder, strip off the executable bits
		@ chmod( $destfilename, $perms );

		return array(
			'path' => $destfilename,
			'file' => wp_basename( apply_filters( 'image_make_intermediate_size', $destfilename ) ),
			'width' => $this->size['width'],
			'height' => $this->size['height']
		);
	}

	/**
	 * Returns stream of current image
	 */
	public function stream() {
		if ( ! $this->load() )
			return;

		switch ( $this->orig_type ) {
			case 'image/jpeg':
				header('Content-Type: image/jpeg');
				return imagejpeg($this->image, null, 90);
			case 'image/png':
				header('Content-Type: image/png');
				return imagepng($this->image);
			case 'image/gif':
				header('Content-Type: image/gif');
				return imagegif($this->image);
			default:
				return false;
		}
	}

	private function make_image( $function, $image, $filename, $quality = -1, $filters = null ) {
		$dst_file = $filename;

		if ( $stream = wp_is_stream( $filename ) ) {
			$filename = null;
			ob_start();
		}

		$result = call_user_func( $function, $image, $filename, $quality, $filters );

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
}