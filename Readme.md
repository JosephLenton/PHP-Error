PHP Better Error Reporting
==========================

PHP errors are not good enough for development, this aims to improve them.

![Better Error Message](http://i.imgur.com/WdvX9.png)

Features
--------
 * trivial to use, just one file
 * makes errors as strict as possible (good for code quality, and tends to improve performance)
 * provides more information
 * fixes some errors which are just plain wrong
 * syntax highlighting
 * provides code for your error
 * looks pretty!

Example Usage
-------------

Download the script and place it into your project. At the earliest moment then import and start error messages.

```php
	<?
		require( 'better_error_message.php' );
		\better_error_message\reportErrors();
	?>
```

Do not use on a live site!
--------------------------

This is intended for __development only__. It shows more about your site, gives you more info, and makes trivial errors fatal.
All of that is awesome if you want to debug quicker, and force high quality standards.

On a production server, that sucks, and is potentially unsafe.

Advanced Features
-----------------

 * turn it on and off
 * customization
 * ignore files allowing you to avoid highlighting code in your stack trace
 * application files; these are prioritized when an error strikes!
 
![Application Aware Stack Trace](http://i.imgur.com/tQxc0.png)
