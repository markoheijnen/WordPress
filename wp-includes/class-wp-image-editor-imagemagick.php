<?php

class WP_Image_Editor_Imagemagick extends WP_Image_Editor {
	private static $convert_bin = null; // ImageMagick executable
	private $image = null; // Imagemagick Object

	function __destruct() {
		if ( $this->image ) {
			// we don't need the original in memory anymore
			$this->image = null;
		}
	}

	protected function find_exec() {
		exec( "type convert", $type, $type_rcode );

		if ( ! $type_rcode ) {
			$convert_type = explode( ' ', $type[0] );
			self::$convert_bin = $convert_type[0];
		} else {
			exec( "locate " . escapeshellarg( "*/convert" ), $locate, $locate_rcode );

			foreach ( $locate as $binary ) {
				if ( '/usr/local/bin/convert' == $binary || '/usr/bin/convert' == $binary ) {
					self::$convert_bin = $binary;
				}
			}
		}
	}

	function run_convert( $command, $returnbool = false, $debug = true ) {
		if ( ! self::$convert_bin )
			self::find_exec();

		$command = self::$convert_bin . ' ' . $command;
		if ( $debug )
			echo '<pre>Command:' . "\n" . $command . '</pre>';
		exec( $command, $convert_data, $return_code );
		if ( $debug )
			echo '<pre>Output:' . "\n" . print_r( $convert_data, true ) . '</pre>';
		if ( $returnbool )
			return ( $return_code ) ? false : true;
		else
			return $convert_data;
	}

	public static function test() {
		$convert = self::run_convert( sprintf( '-version' ), true );
		return $convert;
	}

	protected function load() {
		if ( $this->image )
			return true;

		if ( ! file_exists( $this->file ) )
			return sprintf( __('File &#8220;%s&#8221; doesn&#8217;t exist?'), $this->file );

		$identify = $this->run_convert( sprintf( $this->file . ' -format %s -identify null:', escapeshellarg( '%m' ) ) );
		if ( $identify && in_array( strtolower( $identify[0] ), array( 'jpg', 'jpeg', 'jpe', 'gif', 'png' ) ) ) {
			$this->image = $this->file;
		} else {
			return sprintf(__('File &#8220;%s&#8221; is not an image.'), $this->file);
		}

		$this->update_size();

		$this->orig_type = $identify[0];
		if ( ! $this->size )
			return new WP_Error( 'invalid_image', __('Could not read image size'), $this->file );

		$this->set_quality();

		return true;
	}

	public function set_quality( $quality = null ) {
		$quality = $quality ?: $this->quality;

		if( 'JPEG' == $this->orig_type ) {
//			$this->image->setImageCompressionQuality( apply_filters( 'jpeg_quality', $quality, 'image_resize' ) );
//			$this->image->setImageCompression( imagick::COMPRESSION_JPEG );
		}
		else {
//			$this->image->setImageCompressionQuality( $quality );
		}

		return parent::set_quality( $quality );
	}

	protected function update_size( $width = null, $height = null ) {
		if ( ! $this->load() )
			return false;

		$size = null;
		if ( !$width || !$height ) {
			$geometry = $this->run_convert( sprintf( $this->image . ' -format %s -identify null:', escapeshellarg( '{"size":{"width":"%w","height":"%h"}}' ) ) );
			$geometry = json_decode( $geometry[0] );

			if ( ! $geometry_rcode && ! empty( $geometry->size ) ) {
				$size = $geometry->size;
			} else {
				return sprintf(__('File &#8220;%s&#8221; couldn\'t be checked for size.'), $this->file);
			}
		}

		parent::update_size( $width ?: $size->width, $height ?: $size->height );
	}

	public function resize( $max_w, $max_h, $crop = false ) {
		if ( ! $this->load() )
			return false;

		$dims = image_resize_dimensions( $this->size['width'], $this->size['height'], $max_w, $max_h, $crop );
		if ( ! $dims )
			return new WP_Error( 'error_getting_dimensions', __('Could not calculate resized image dimensions') );
		list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

		if ( $crop ) {
			return $this->crop( $src_x, $src_y, $src_w, $src_h, $dst_w, $dst_h );
		}

		//$this->image->thumbnailImage( $dst_w, $dst_h );
		$this->run_convert( sprintf( $this->image . ' -scale %dx%d -quality %d', $dst_w, $dst_h, $this->quality ) );
		$this->update_size( $dst_w, $dst_h );

		return true;
	}

	/**
	 * Processes current image and saves to disk
	 * multiple sizes from single source.
	 *
	 * @param array $sizes
	 * @return array
	 */
	public function multi_resize( $sizes ) {
		$metadata = array();
		if ( ! $this->load() )
			return $metadata;

		$orig_size = $this->size;
		$orig_image = $this->file;
		foreach ( $sizes as $size => $size_data ) {
			if ( ! $this->image )
				$this->image = $orig_image;

			$resize_result = $this->resize( $size_data['width'], $size_data['height'], $size_data['crop'] );

			if( ! is_wp_error( $resize_result ) ) {
				$resized = $this->save();

				$this->image = null;
				unset( $resized['path'] );

				if ( ! is_wp_error( $resized ) && $resized )
					$metadata[$size] = $resized;
			}

			$this->size = $orig_size;
		}

		$this->image = $orig_image;

		return $metadata;
	}

	/**
	 * Crops image.
	 *
	 * @param float $x
	 * @param float $y
	 * @param float $w
	 * @param float $h
	 * @return boolean
	 */
	public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false ) {
		if ( ! $this->load() )
			return false;

		// Not sure this is compatible.
		if ( $src_abs ) {
			$src_w -= $src_x;
			$src_h -= $src_y;
		}

		$this->run_convert( sprintf( $this->image . ' -crop %dx%d+%d+%d -quality %d', $src_w, $src_h, $src_x, $src_y, $this->quality ) );

		if ( $dst_w || $dst_h ) {
			// If destination width/height isn't specified, use same as
			// width/height from source.
			$dst_w = $dst_w ?: $src_w;
			$dst_h = $dst_h ?: $src_h;

			$this->run_convert( sprintf( $this->image . ' -resize %dx%d -quality %d', $dst_w, $dst_h, $this->quality ) );
			$this->update_size( $dst_w, $dst_h );
			return true;
		}

		$this->update_size( $src_w, $src_h );
		return true;

		// @TODO: We need exception handling above  // return false;
	}

	/**
	 * Rotates in memory image by $angle.
	 * Ported from image-edit.php
	 *
	 * @param float $angle
	 * @return boolean
	 */
	public function rotate( $angle ) {
		if ( ! $this->load() )
			return false;

		/**
		 * $angle is 360-$angle because Imagemagick rotates clockwise
		 * (GD rotates counter-clockwise)
		 */
		try {
			$this->image->rotateImage( new ImagickPixel('none'), 360-$angle );
			$this->update_size();
		}
		catch ( Exception $e ) {
			return false; // TODO: WP_Error Here.
		}
	}

	/**
	 * Flips Image
	 *
	 * @param boolean $horz
	 * @param boolean $vert
	 * @returns boolean
	 */
	public function flip( $horz, $vert ) {
		if ( ! $this->load() )
			return false;

		try {
			if ( $horz )
				$this->image->flipImage();

			if ( $vert )
				$this->image->flopImage();
		}
		catch ( Exception $e ) {
			return false; // TODO: WP_Error Here.
		}

		return true;
	}

	/**
	 * Saves current image to file
	 *
	 * @param string $destfilename
	 * @return array
	 */
	public function save( $destfilename = null ) {
		$saved = $this->_save( $this->image, $destfilename );

		if ( ! is_wp_error( $saved ) && $destfilename )
			$this->file = $destfilename;

		return $saved;
	}

	protected function _save( $image, $destfilename = null ) {
		if ( ! $this->load() )
			return false;

		if ( null == $destfilename ) {
			$destfilename = $this->generate_filename();
		}

		if( apply_filters( 'wp_editors_stripimage', true ) ) {
			$this->run_convert( sprintf( $this->image . ' -strip' ) );
		}

		$this->run_convert( sprintf( $this->image . ' %s', escapeshellarg( $destfilename ) ) );

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
	 * @TODO: Wrap in try and clean up.
	 * Also, make GIF not stream the last frame :(
	 *
	 * @return boolean
	 */
	public function stream() {
		if ( ! $this->load() )
			return false;

		switch ( $this->orig_type ) {
			case 'PNG':
				header( 'Content-Type: image/png' );
				break;
			case 'GIF':
				header( 'Content-Type: image/gif' );
				break;
			default:
				header( 'Content-Type: image/jpeg' );
				break;
		}

		print $this->image->getImageBlob();
		return true;
	}
}