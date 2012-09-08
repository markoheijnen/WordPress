<?php

class WP_Image_Editor_Imagick extends WP_Image_Editor_Base {
	private $image = null; // Imagick Object

	function __destruct() {
		if ( $this->image ) {
			// we don't need the original in memory anymore
			$this->image->destroy();
			unset( $this->image );
		}
	}

	public static function test() {
		if ( ! extension_loaded('imagick') )
			return false;

		return true;
	}

	protected function load() {
		if ( $this->image )
			return true;

		if ( ! file_exists( $this->file ) )
			return sprintf( __('File &#8220;%s&#8221; doesn&#8217;t exist?'), $this->file );

		try {
			$this->image = new Imagick( $this->file );
			$this->image->setIteratorIndex(0);
		}
		catch ( Exception $e ) {
			return sprintf(__('File &#8220;%s&#8221; is not an image.'), $this->file);
		}

		if( ! $this->image->valid() ) {
			return sprintf(__('File &#8220;%s&#8221; is not an image.'), $this->file);
		}

		$this->update_size();
		$this->orig_type  = $this->image->getImageFormat(); // TODO: Wrap in exception handling
		if ( ! $this->size )
			return new WP_Error( 'invalid_image', __('Could not read image size'), $this->file );

		$this->set_quality();

		return true;
	}

	public function set_quality( $quality = null ) {
		$quality = $quality ?: $this->quality;

		if( 'JPEG' == $this->orig_type ) {
			$this->image->setImageCompressionQuality( apply_filters( 'jpeg_quality', $quality, 'image_resize' ) );
			$this->image->setImageCompression( imagick::COMPRESSION_JPEG );
		}
		else {
			$this->image->setImageCompressionQuality( $quality );
		}

		return parent::set_quality( $quality );
	}

	protected function update_size( $width = null, $height = null ) {
		if ( ! $this->load() )
			return false;

		$size = null;
		if ( !$width || !$height ) {
			try {
				$size = $this->image->getImageGeometry();
			}
			catch ( Exception $e ) {
				return sprintf(__('File &#8220;%s&#8221; couldn\'t be checked for size.'), $this->file);
			}
		}

		parent::update_size( $width ?: $size['width'], $height ?: $size['height'] );
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
		$this->image->scaleImage( $dst_w, $dst_h );
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
		$orig_image = $this->image->getImage();
		foreach ( $sizes as $size => $size_data ) {
			if ( ! $this->image )
				$this->image = $orig_image->getImage();

			$resize_result = $this->resize( $size_data['width'], $size_data['height'], $size_data['crop'] );

			if( ! is_wp_error( $resize_result ) ) {
				$resized = $this->save();

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
		if ( ! $this->load() )
			return false;

		// Not sure this is compatible.
		if ( $src_abs ) {
			$src_w -= $src_x;
			$src_h -= $src_y;
		}

		$this->image->cropImage( $src_w, $src_h, $src_x, $src_y );
		$this->image->setImagePage( $src_w, $src_h, 0, 0);

		if ( $dst_w || $dst_h ) {
			// If destination width/height isn't specified, use same as
			// width/height from source.
			$dst_w = $dst_w ?: $src_w;
			$dst_h = $dst_h ?: $src_h;

			$this->image->scaleImage( $dst_w, $dst_h );
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
		 * $angle is 360-$angle because Imagick rotates clockwise
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
			$image->stripImage();
		}

		$image->writeImage( $destfilename );

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
			case 'JPEG':
				header( 'Content-Type: image/jpeg' );
				break;
			case 'PNG':
				header( 'Content-Type: image/png' );
				break;
			case 'GIF':
				header( 'Content-Type: image/gif' );
				break;
			default:
				return false;
		}

		print $this->image->getImageBlob();
		return true;
	}
}