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
		if( $this->image ) 
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

		$this->size = array( 'width' => $size[0], 'height' => $size[1] );
		$this->orig_type = $size[1];

		return true;
	}

	public function resize( $max_w, $max_h, $crop = false, $suffix = null, $dest_path = null, $jpeg_quality = 90 ) {
		if ( ! $this->load() )
			return;

		$dims = image_resize_dimensions( $this->size['width'], $this->size['height'], $max_w, $max_h, $crop );
		if ( ! $dims )
			return new WP_Error( 'error_getting_dimensions', __('Could not calculate resized image dimensions') );
		list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

		$newimage = wp_imagecreatetruecolor( $dst_w, $dst_h );

		imagecopyresampled( $newimage, $this->image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h );

		// convert from full colors to index colors, like original PNG.
		if ( IMAGETYPE_PNG == $this->orig_type && function_exists('imageistruecolor') && !imageistruecolor( $this->image ) )
			imagetruecolortopalette( $newimage, false, imagecolorstotal( $this->image ) );

		// $suffix will be appended to the destination filename, just before the extension
		if ( ! $suffix )
			$suffix = "{$dst_w}x{$dst_h}";

		$info = pathinfo( $this->file );
		$dir  = $info['dirname'];
		$ext  = $info['extension'];
		$name = wp_basename( $this->file, ".$ext" );

		if ( ! is_null( $dest_path ) && $_dest_path = realpath( $dest_path ) )
			$dir = $_dest_path;
		$destfilename = "{$dir}/{$name}-{$suffix}.{$ext}";

		if ( IMAGETYPE_GIF == $this->orig_type ) {
			if ( ! imagegif( $newimage, $destfilename ) )
				return new WP_Error( 'resize_path_invalid', __( 'Resize path invalid' ) );
		}
		elseif ( IMAGETYPE_PNG == $this->orig_type ) {
			if ( !imagepng( $newimage, $destfilename ) )
				return new WP_Error( 'resize_path_invalid', __( 'Resize path invalid' ) );
		}
		else {
			// all other formats are converted to jpg
			if ( 'jpg' != $ext && 'jpeg' != $ext )
				$destfilename = "{$dir}/{$name}-{$suffix}.jpg";

			if ( ! imagejpeg( $newimage, $destfilename, apply_filters( 'jpeg_quality', $jpeg_quality, 'image_resize' ) ) )
				return new WP_Error( 'resize_path_invalid', __( 'Resize path invalid' ) );
		}

		imagedestroy( $newimage );

		// Set correct file permissions
		$stat = stat( dirname( $destfilename ) );
		$perms = $stat['mode'] & 0000666; //same permissions as parent folder, strip off the executable bits
		@ chmod( $destfilename, $perms );

		return array(
			'path' => $destfilename,
			'file' => wp_basename(  apply_filters( 'image_make_intermediate_size', $destfilename ) ),
			'width' => $dst_w,
			'height' => $dst_h
		);
	}
}