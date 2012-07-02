<?php
    session_start();
    $_SESSION['DKDKDK']='something';
?><!DOCTYPE html>
<?php
	require( dirname(__FILE__) . '/../src/php_error.php' );
	\php_error\reportErrors();
?>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>

<script>
	$(document).ready( function() {
		$.post( '/example/unknown_variable.php?something=blah_blah&dkkddk=something_else', { foo:[ 1234, 9393, null, [["kdkdkdk"]]], bar: 993, foobar: "dkkdkslfjdslkfj" }, function(str) {
			console.log( str );
		} );
	});
</script>
