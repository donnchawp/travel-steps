<?php
/**
 * Loaded as a mu-plugin while check.php fetches pages: records each PHP error and
 * warning a page raises in /out/errors.txt, whether or not PHP displays it. Notices
 * and deprecations count only if WordPress reports them, as on the gallery's sites.
 */

set_error_handler(
	function ( $type, $message, $file, $line ) {
		if ( error_reporting() & $type ) {
			file_put_contents( '/out/errors.txt', "$message @ $file:$line\n", FILE_APPEND );
		}
		return false;
	}
);
register_shutdown_function(
	function () {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
			file_put_contents( '/out/errors.txt', 'fatal: ' . $error['message'] . ' @ ' . $error['file'] . ':' . $error['line'] . "\n", FILE_APPEND );
		}
	}
);
