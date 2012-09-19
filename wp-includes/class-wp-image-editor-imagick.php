<?php

class WP_Image_Editor_Imagick extends WP_Image_Editor {
	protected $image = null; // Imagick Object

	function __destruct() {
		if ( $this->image ) {
			// we don't need the original in memory anymore
			$this->image->clear();
			$this->image->destroy();
		}
	}

	public static function test() {
		if ( ! extension_loaded( 'imagick' ) )
			return false;

		return true;
	}

	/**
	 * Load image in $file into new Imagick Object
	 *
	 * @return boolean|\WP_Error
	 */
	protected function load() {
		if ( $this->image )
			return true;

		if ( ! file_exists( $this->file ) )
			return new WP_Error( 'error_loading_image', __('File doesn&#8217;t exist?'), $this->file );

		try {
			$this->image = new Imagick( $this->file );

			if( ! $this->image->valid() )
				return new WP_Error( 'invalid_image', __('File is not an image.'), $this->file);

			// Select the first frame to handle animated GIFs properly
			$this->image->setIteratorIndex(0);
			$this->orig_type = $this->image->getImageFormat();
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
		$quality = $quality ?: $this->quality;

		try {
			if( 'JPEG' == $this->orig_type ) {
				$this->image->setImageCompressionQuality( apply_filters( 'jpeg_quality', $quality, 'image_resize' ) );
				$this->image->setImageCompression( imagick::COMPRESSION_JPEG );
			}
			else {
				$this->image->setImageCompressionQuality( $quality );
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
				$size = $this->image->getImageGeometry();
			}
			catch ( Exception $e ) {
				return new WP_Error( 'invalid_image', __('Could not read image size'), $this->file );
			}
		}

		return parent::update_size( $width ?: $size['width'], $height ?: $size['height'] );
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
			/**
			 * @TODO: Thumbnail is more efficient, given a newer version of Imagemagick.
			 * $this->image->thumbnailImage( $dst_w, $dst_h );
			 */
			$this->image->scaleImage( $dst_w, $dst_h );
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
		$orig_image = $this->image->getImage();

		foreach ( $sizes as $size => $size_data ) {
			if ( ! $this->image )
				$this->image = $orig_image->getImage();

			$resize_result = $this->resize( $size_data['width'], $size_data['height'], $size_data['crop'] );

			if( ! is_wp_error( $resize_result ) ) {
				$resized = $this->save();

				$this->image->clear();
				$this->image->destroy();
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
			$this->image->cropImage( $src_w, $src_h, $src_x, $src_y );
			$this->image->setImagePage( $src_w, $src_h, 0, 0);

			if ( $dst_w || $dst_h ) {
				// If destination width/height isn't specified, use same as
				// width/height from source.
				$dst_w = $dst_w ?: $src_w;
				$dst_h = $dst_h ?: $src_h;

				$this->image->scaleImage( $dst_w, $dst_h );
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
		 * $angle is 360-$angle because Imagick rotates clockwise
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
			$this->file = $destfilename ?: $this->file;
			$this->orig_type = $mime_type ?: $this->orig_type;
		}

		return $saved;
	}

	protected function _save( $image, $destfilename = null, $mime_type = null ) {
		$mime_type = $mime_type ?: $this->orig_type;

		try {
			if ( apply_filters( 'wp_editors_stripimage', true ) ) {
				$image->stripImage();
			}

			$imagick_extension = null;
			switch ( $mime_type ) {
				case 'image/png':
					$imagick_extension = 'PNG';
					break;
				case 'image/gif':
					$imagick_extension = 'GIF';
					break;
				default:
					$imagick_extension = 'JPG';
			}

			$destfilename = $destfilename ?: $this->generate_filename( null, null, $imagick_extension );

			$this->image->setImageFormat( $imagick_extension );
			$this->make_image( $destfilename, array( $image, 'writeImage' ), array( $destfilename ) );
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

	/**
	 * Streams current image to browser
	 *
	 * @param string $mime_type
	 * @return boolean|WP_Error
	 */
	public function stream( $mime_type = null ) {
		$extension = $this->orig_type;
		if ( $mime_type ) {
			$extension = $this->get_extension( $mime_type );
		} else {
			$mime_type = $this->get_mime_type( $imagick_type );
		}

		try {
			if ( !( $extension || $mime_type)  ||
					! $this->image->queryFormats( strtoupper($extension) ) ) {
				$extension = 'JPG';
				$mime_type = 'image/jpeg';
			}

			// Temporarily change format for stream
			$this->image->setImageFormat( $extension );

			// Output stream of image content
			header( "Content-Type: $mime_type" );
			print $this->image->getImageBlob();

			// Reset Image to original Format
			$this->image->setImageFormat( $this->orig_type );
		}
		catch ( Exception $e ) {
			return new WP_Error( 'image_stream_error', $e->getMessage() );
		}

		return true;
	}
}