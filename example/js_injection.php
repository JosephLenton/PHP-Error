<?php
    session_start();
    $_SESSION['DKDKDK']='something';
?><!DOCTYPE html>
<?php
	require( dirname(__FILE__) . '/../src/php_error.php' );
	\php_error\reportErrors();
?>

<script src="../src/php_error_jquery.js"></script>

<script>
	$(document).ready( function() {
		$.post( './unknown_variable.php?something=blah_blah&dkkddk=something_else', { foo:[ 1234, 9393, null, [["kdkdkdk"]]], bar: 993, foobar: "dkkdkslfjdslkfj" }, function(str) {
			console.log( str );
		} );
	});
</script>
