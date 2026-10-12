<?php

namespace TravelSteps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core's Imagick editor, except that copies of trip photos keep no metadata but their
 * colour profile. Core keeps EXIF and XMP in every copy (for the orientation, which
 * it has already turned the pixels to by the time it saves), and with them the
 * photo's GPS position. GD writes no metadata at all.
 *
 * Stripping on every save covers the sizes made on upload, "-scaled" and "-rotated",
 * sizes finished or regenerated later, edits, and sizes the photo proxy makes, whatever
 * the image_strip_meta filter says. Originals are never saved through an editor.
 */
class ImagickEditor extends \WP_Image_Editor_Imagick {
	const KEEP_PROFILES = [ 'icc', 'icm' ];

	/**
	 * @param \Imagick $image
	 */
	protected function _save( $image, $filename = null, $mime_type = null ) {
		if ( Uploads::is_trip_file( (string) $this->file ) ) {
			try {
				foreach ( array_keys( $image->getImageProfiles( '*', true ) ) as $profile ) {
					if ( ! in_array( $profile, self::KEEP_PROFILES, true ) ) {
						$image->removeImageProfile( $profile );
					}
				}
			} catch ( \Exception $e ) {
				return new \WP_Error( 'image_strip_meta_error', $e->getMessage() );
			}
		}
		return parent::_save( $image, $filename, $mime_type );
	}
}
