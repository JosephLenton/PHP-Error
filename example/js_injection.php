<!DOCTYPE html>
<?

	require( __DIR__ . '/../src/php_error.php' );
	\php_error\reportErrors();

	ob_start( function($content) {
		return '###' . $content . '###';
	});

	echo 'blah';