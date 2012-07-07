<?php
	require( dirname(__FILE__) . '/../src/php_error.php' );
	\php_error\reportErrors( array('display_line_numbers' => true) );

	function a() {
		b();
	}

	function b() {
		$foo = $bar;
	}

	a( "fooobar fooobar fooobar fooobar fooobar fooobar fooobar fooobar fooobar fooobar", "fooobar", "fooobar", "fooobar", "fooobar", "fooobar", "fooobar", "fooobar" );
