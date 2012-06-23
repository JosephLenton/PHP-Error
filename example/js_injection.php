<!DOCTYPE html>
<?
	require( __DIR__ . '/../src/php_error.php' );
	\php_error\reportErrors();
?>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>

<script>
	$(document).ready( function() {
		$.get( '/example/unknown_variable.php', function(str) {
			console.log('success!');
		} );
	});
</script>