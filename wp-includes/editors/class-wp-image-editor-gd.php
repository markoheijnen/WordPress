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

	function get_resource() {
		return $this->image;
	}

	public function resize( $max_w, $max_h, $crop = false ) {
		if ( ! $this->load() )
			return;

		$dims = image_resize_dimensions( $this->size['width'], $this->size['height'], $max_w, $max_h, $crop );
		if ( ! $dims )
			return new WP_Error( 'error_getting_dimensions', __('Could not calculate resized image dimensions') );
		list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;
		$this->dest_size = array( 'width' => $dst_w, 'height' => $dst_h );

		$resized = wp_imagecreatetruecolor( $dst_w, $dst_h );
		imagecopyresampled( $resized, $this->image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h );

		if ( is_resource( $resized ) ) {
			imagedestroy( $this->image ); 
			$this->image = $resized;
			return true;
		}
	}


	/**
	 * Ported from image-edit.php
	 * 
	 * @TODO: Is it better to destroy current,
	 *		  then set $image to new -- or copy, then destroy old?
	 *		  It seems like with the first method, we may end up with
	 *		  an extra copy of the image, because I don't believe they function
	 *		  like pointers, even though we wish they were.
	 * @param type $angle
	 * @return boolean 
	 */
	public function rotate( $angle ) {
		if ( function_exists('imagerotate') ) {
			$rotated = imagerotate( $this->image, $angle, 0 );

			if ( is_resource( $rotated ) ) {
				imagedestroy( $this->image ); 
				$this->image = $rotated;
				return true;
			}
		}
		return false; // @TODO: WP_Error here.
	}

	/**
	 *Ported from image-edit.php
	 * @param type $horz
	 * @param type $vert 
	 */
	public function flip( $horz, $vert ) {
		$w = $size['width'];
		$h = $size['height'];
		$dst = wp_imagecreatetruecolor( $w, $h );

		if ( is_resource( $dst ) ) {
			$sx = $vert ? ($w - 1) : 0;
			$sy = $horz ? ($h - 1) : 0;
			$sw = $vert ? -$w : $w;
			$sh = $horz ? -$h : $h;

			if ( imagecopyresampled( $dst, $img, 0, 0, $sx, $sy, $w, $h, $sw, $sh ) ) {
				imagedestroy( $this->image );
				$this->image = $dst;
			}
		}
		return false; // @TODO: WP_Error here.
	}

	public function save( $suffix = null, $dest_path = null ) {
		// convert from full colors to index colors, like original PNG.
		if ( IMAGETYPE_PNG == $this->orig_type && function_exists('imageistruecolor') && !imageistruecolor( $this->image ) )
			imagetruecolortopalette( $this->image, false, imagecolorstotal( $this->image ) );

		// $suffix will be appended to the destination filename, just before the extension
		if ( ! $suffix )
			$suffix = "{$this->dest_size['width']}x{$this->dest_size['height']}";

		$info = pathinfo( $this->file );
		$dir  = $info['dirname'];
		$ext  = $info['extension'];
		$name = wp_basename( $this->file, ".$ext" );

		if ( ! is_null( $dest_path ) && $_dest_path = realpath( $dest_path ) )
			$dir = $_dest_path;

		$destfilename = "{$dir}/{$name}-{$suffix}.{$ext}";

		if ( IMAGETYPE_GIF == $this->orig_type ) {
			if ( ! $this->make_image( 'imagegif', $this->image, $destfilename ) )
				return new WP_Error( 'resize_path_invalid', __( 'Resize path invalid' ) );
		}
		elseif ( IMAGETYPE_PNG == $this->orig_type ) {
			if ( ! $this->make_image( 'imagepng', $this->image, $destfilename ) )
				return new WP_Error( 'resize_path_invalid', __( 'Resize path invalid' ) );
		}
		else {
			// all other formats are converted to jpg
			if ( 'jpg' != $ext && 'jpeg' != $ext )
				$destfilename = "{$dir}/{$name}-{$suffix}.jpg";

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
			'width' => $this->dest_size['width'],
			'height' => $this->dest_size['height']
		);
	}

	private make_image( $function, $image, $filename, $quality = -1, $filters = null ) { 
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