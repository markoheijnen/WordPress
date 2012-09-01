<?php

class WP_Image_Editor_Imagick extends WP_Image_Editor_Base {
	private $image = false; // Imagick Object

	public static function test() {
		if ( ! extension_loaded('imagick') )
			return false;

		return true;
	}

	private function load() {
		if ( ! file_exists( $this->file ) )
			return sprintf( __('File &#8220;%s&#8221; doesn&#8217;t exist?'), $this->file );

		try {
			$this->image = new Imagick( $this->file );
		}
		catch ( Exception $e ) {
			return sprintf(__('File &#8220;%s&#8221; is not an image.'), $this->file);
		}

		if( ! $this->image->valid() ) {
			return sprintf(__('File &#8220;%s&#8221; is not an image.'), $this->file);
		}

		$this->update_size();
		$this->orig_type  = $this->image->getImageFormat();
		if ( ! $this->size )
			return new WP_Error( 'invalid_image', __('Could not read image size'), $this->file );

		return true;
	}

	protected function update_size( $width = false, $height = false ) {
		if ( ! $this->load() )
			return false;

		$size = null;
		if ( !$this->size || $width || $height ) {
			try {
				$size = $this->image->getImageFormat();
			}
			catch ( Exception $e ) {
				return sprintf(__('File &#8220;%s&#8221; couldn\'t be checked for size.'), $this->file);
			}
		}

		parent::update_size( $width ?: $size['height'], $height ?: $size['width'] );
	}

	public function resize( $max_w, $max_h, $crop = false ) {
		// Yes, this is forcing a load every time at the moment.
		// However, for multi-resize to work, it needs to do so, unless it's going to resize based on a modified image.
		if ( ! $this->load() )
			return false;

		if ( ! is_object( $this->image ) )
			return new WP_Error( 'error_loading_image', $this->image, $this->file );

		$dims = image_resize_dimensions( $this->size['width'], $this->size['height'], $max_w, $max_h, $crop );
		if ( ! $dims )
			return new WP_Error( 'error_getting_dimensions', __('Could not calculate resized image dimensions') );
		list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

		if( 'JPEG' == $this->orig_type ) {
			$this->image->setImageCompressionQuality( apply_filters( 'jpeg_quality', $this->quality, 'image_resize' ) );
			$this->image->setImageCompression( imagick::COMPRESSION_JPEG );
		}
		else {
			$this->image->setImageCompressionQuality( $this->quality );
		}

		if ( $crop ) {
			$this->image->cropImage( $src_w, $src_h, $src_x, $src_y );
		}

		//$this->image->thumbnailImage( $dst_w, $dst_h );
		$this->image->scaleImage( $dst_w, $dst_h, true );
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
			return;

		if ( null == $destfilename ) {
			$destfilename = $this->generate_filename();
		}

		if( apply_filters( 'wp_editors_stripimage', true ) ) {
			$this->image->stripImage();
		}

		$this->image->writeImage( $destfilename );

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

	public function generate_filename( $suffix = null, $dest_path = null ) {
		if ( ! $this->load() )
			return;

		return parent::generate_filename( $suffix, $dest_path );
	}

	public function get_suffix() {
		if ( ! $this->load() )
			return;

		return parent::get_suffix();
	}
}