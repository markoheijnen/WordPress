<?php

class WP_Image_Editor_Imagemagick extends WP_Image_Editor {
	private static $convert_bin = null; // ImageMagick executable
	protected $image = null; // Imagemagick Object

	function __destruct() {
		if ( $this->image ) {
			// we don't need the original in memory anymore
			if ( file_exists( $this->image ) )
				unlink( $this->image );
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

	function run_convert( $command, $returnbool = false, $debug = false ) {
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

	/**
	 * Load image in $file
	 *
	 * @return boolean|\WP_Error
	 */
	protected function load() {
		if ( $this->image )
			return true;

		if ( ! file_exists( $this->file ) )
			return new WP_Error( 'error_loading_image', __('File doesn&#8217;t exist?'), $this->file );

		try {
			$identify = $this->run_convert( sprintf( $this->file . ' -format %s -identify null:', escapeshellarg( '%m' ) ) );
			if ( $identify && in_array( strtolower( $identify[0] ), array( 'jpg', 'jpeg', 'jpe', 'gif', 'png' ) ) ) {
				$this->image = $this->generate_filename( 'temp' );
				$this->run_convert( sprintf( $this->file . ' %s', escapeshellarg( $this->image ) ) );
			} else {
				return new WP_Error( 'invalid_image', __('File is not an image.'), $this->file);
			}

			$this->orig_type = 'image/' . strtolower( $identify[0] );
		}
		catch ( Exception $e ) {
			return new WP_Error( 'error_loading_image', $e->getMessage(), $this->file );
		}

		$updated_size = $this->update_size();
		if ( is_wp_error( $updated_size ) )
			return $updated_size;

		return $this->set_quality();
	}

	/**
	 * Sets Image Compression quality on a 1-100% scale.
	 *
	 * @param int $quality
	 * @return boolean|WP_Error
	 */
	public function set_quality( $quality = null ) {
		$quality = $quality ? $quality : $this->quality;

		try {
			if( 'JPEG' == $this->orig_type ) {
				$quality = apply_filters( 'jpeg_quality', $quality, 'image_resize' );
			}
		}
		catch ( Exception $e ) {
			return new WP_Error( 'image_quality_error', $e->getMessage() );
		}

		return parent::set_quality( $quality );
	}

	protected function update_size( $width = null, $height = null ) {
		$size = null;
		if ( !$width || !$height ) {
			try {
				$geometry = $this->run_convert( sprintf( $this->image . ' -format %s -identify %s', escapeshellarg( '{"size":{"width":"%w","height":"%h"}}' ), escapeshellarg( $this->image ) ) );
				$geometry = json_decode( $geometry[0] );

				$size = $geometry->size;
			}
			catch ( Exception $e ) {
				return new WP_Error( 'invalid_image', __('Could not read image size'), $this->file );
			}
		}

		return parent::update_size( $width ? $width : $size->width, $height ? $height : $size->height );
	}

	public function resize( $max_w, $max_h, $crop = false ) {
		if ( ( $this->size['width'] == $max_w ) && ( $this->size['height'] == $max_h ) )
			return true;

		$dims = image_resize_dimensions( $this->size['width'], $this->size['height'], $max_w, $max_h, $crop );
		if ( ! $dims )
			return new WP_Error( 'error_getting_dimensions', __('Could not calculate resized image dimensions') );
		list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

		if ( $crop ) {
			return $this->crop( $src_x, $src_y, $src_w, $src_h, $dst_w, $dst_h );
		}

		try {
			//$this->image->thumbnailImage( $dst_w, $dst_h );
			$this->run_convert( sprintf( $this->image . ' -scale %dx%d -quality %d %s', $dst_w, $dst_h, $this->quality, escapeshellarg( $this->image ) ) );
		}
		catch ( Exception $e ) {
			return new WP_Error( 'image_resize_error', $e->getMessage() );
		}

		return $this->update_size( $dst_w, $dst_h );
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
		$orig_size = $this->size;
		$orig_image = $this->image;
		foreach ( $sizes as $size => $size_data ) {
			if ( ! $this->image )
				$this->image = $orig_image;

			$this->image = $this->generate_filename( 'temp' );
			$this->run_convert( sprintf( $this->file . ' %s', escapeshellarg( $this->image ) ) );
			$resize_result = $this->resize( $size_data['width'], $size_data['height'], $size_data['crop'] );

			if( ! is_wp_error( $resize_result ) ) {
				$resized = $this->save();

				unlink( $this->image );
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
		// Not sure this is compatible.
		if ( $src_abs ) {
			$src_w -= $src_x;
			$src_h -= $src_y;
		}

		try {
			$this->run_convert( sprintf( $this->image . ' -crop %dx%d+%d+%d -quality %d %s', $src_w, $src_h, $src_x, $src_y, $this->quality, escapeshellarg( $this->image ) ) );

			if ( $dst_w || $dst_h ) {
				// If destination width/height isn't specified, use same as
				// width/height from source.
				$dst_w = $dst_w ? $dst_w : $src_w;
				$dst_h = $dst_h ? $dst_h : $src_h;

				$this->run_convert( sprintf( $this->image . ' -scale %dx%d -quality %d %s', $dst_w, $dst_h, $this->quality, escapeshellarg( $this->image ) ) );
				return $this->update_size( $dst_w, $dst_h );
			}
		}
		catch ( Exception $e ) {
			return new WP_Error( 'image_crop_error', $e->getMessage() );
		}

		return $this->update_size( $src_w, $src_h );
	}

	/**
	 * Rotates image by $angle.
	 *
	 * @since 3.5.0
	 *
	 * @param float $angle
	 * @return boolean
	 */
	public function rotate( $angle ) {
		/**
		 * $angle is 360-$angle because Imagemagick rotates clockwise
		 * (GD rotates counter-clockwise)
		 */
		try {
			$this->image->rotateImage( new ImagickPixel('none'), 360-$angle );
		}
		catch ( Exception $e ) {
			return new WP_Error( 'image_rotate_error', $e->getMessage() );
		}
		return $this->update_size();
	}

	/**
	 * Flips Image
	 *
	 * @param boolean $horz
	 * @param boolean $vert
	 * @returns boolean
	 */
	public function flip( $horz, $vert ) {
		try {
			if ( $horz )
				$this->image->flipImage();

			if ( $vert )
				$this->image->flopImage();
		}
		catch ( Exception $e ) {
			return new WP_Error( 'image_flip_error', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Saves current image to file
	 *
	 * @param string $destfilename
	 * @param string $mime_type
	 * @return array
	 */
	public function save( $destfilename = null, $mime_type = null ) {
		$saved = $this->_save( $this->image, $destfilename, $mime_type );

		if ( ! is_wp_error( $saved ) ) {
			$this->file = $destfilename ? $destfilename : $this->file;
			$this->orig_type = $mime_type ? $mime_type : $this->orig_type;
		}

		return $saved;
	}

	protected function _save( $image, $destfilename = null, $mime_type = null ) {
		$mime_type = $mime_type ? $mime_type : $this->orig_type;

		try {
			if ( apply_filters( 'wp_editors_stripimage', true ) ) {
				$this->run_convert( sprintf( $this->image . ' -strip' ) );
			}

			$imagemagick_extension = null;
			switch ( $mime_type ) {
				case 'image/png':
					$imagemagick_extension = 'PNG';
					break;
				case 'image/gif':
					$imagemagick_extension = 'GIF';
					break;
				default:
					$imagemagick_extension = 'JPG';
			}

			$destfilename = $destfilename ? $destfilename : $this->generate_filename( null, null, $imagemagick_extension );

			$this->make_image( $destfilename, array( $this, 'write' ), array( $destfilename ) );
		}
		catch ( Exception $e ) {
			return new WP_Error( 'image_save_error', $e->getMessage(), $destfilename );
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

	public function write( $destfilename ) {
		$imagemagick_extension = null;
		switch ( $this->orig_type ) {
			case 'image/png':
				$imagemagick_extension = 'PNG';
				break;
			case 'image/gif':
				$imagemagick_extension = 'GIF';
				break;
			default:
				$imagemagick_extension = 'JPG';
		}

		$this->run_convert( sprintf( $this->image . ' -quality %d' . ( $imagemagick_extension == 'JPG' ? ' -compress JPEG ' : ' ' ) . '-format %s %s', $this->quality, escapeshellarg( $imagemagick_extension ), escapeshellarg( $destfilename ) ) );
	}

	/**
	 * Streams current image to browser
	 *
	 * @param string $mime_type
	 * @return boolean|WP_Error
	 */
	public function stream( $mime_type = null ) {
		$mime_type = $mime_type ? $mime_type : $this->orig_type;

		switch ( $mime_type ) {
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

		try {
			print $this->image->getImageBlob();
		}
		catch ( Exception $e ) {
			return new WP_Error( 'image_stream_error', $e->getMessage() );
		}

		return true;
	}
}