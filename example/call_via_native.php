<?php
	require( dirname(__FILE__) . '/../src/php_error.php' );
	\php_error\reportErrors();

	$fun = function() {
		$a = $b;
	};

	call_user_func( $fun );

	a();
