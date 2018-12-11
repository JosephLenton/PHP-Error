PHP Error | Improve Error Reporting for PHP
===========================================

**THIS IS NO LOBGER MAINTAINED, Please leave me alone.**

PHP errors are not good enough for development, it's as simple as that. This aims to solve this.

![Better Error Message](http://i.imgur.com/1G77I.png)

When an error strikes, the page is replaced with a full stack trace, syntax highlighting, and all displayed to be readable.

### Works with Ajax too!

If the server errors during an ajax request, then the request is paused, and the error is displayed in the browser. You can then click to automatically retry the last request.

![ajax server stack trace](http://i.imgur.com/WRgug.png)

This requires no changes to your JavaScript, and works with existing JS libraries such as jQuery.

Do not use on a live site!
--------------------------

To help make development easier, this __deliberately__ makes your code unsafe.
External requests are allowed to change your code, it shows more about your site,
gives you more info, and makes trivial errors fatal.
All of that is awesome if you want to fix bugs in less time,
but in production, it is totally unsafe.

**seriously, only use this for development!**

In case you forget,
you can disable this in production using the 'php_error.force_disabled' php.ini option (see below).

Features
--------
 * trivial to use, it's just one file
 * errors displayed in the browser for normal and ajaxy requests
 * ajax requests are paused, allowing you to automatically re-run them
 * makes errors as strict as possible (encourages code quality, and tends to improve performance)
 * code snippets across the whole stack trace
 * provides more information (such as full function signatures)
 * fixes some error messages which are just plain wrong
 * syntax highlighting
 * looks pretty!

Getting Started
---------------

 * Download, it's just one file.
 * Place it in your project.
 * import php_error.php
 * call \php_error\reportErrors()

```php
    <?php
        require( 'php_error.php' );
        \php_error\reportErrors();
    ?>
```

Documentation
-------------

### [Example Setup](https://github.com/JosephLenton/PHP-Error/wiki/Example-Setup)

### [API](https://github.com/JosephLenton/PHP-Error/wiki/API)

### [Options](https://github.com/JosephLenton/PHP-Error/wiki/Options)

### [php.ini settings](https://github.com/JosephLenton/PHP-Error/wiki/php.ini)

Advanced Features
-----------------

 * customization
 * manually turn it on and off
 * run specific sections without error reporting
 * ignore files allowing you to avoid highlighting code in your stack trace
 * application files; these are prioritized when an error strikes!
 
![Application Aware Stack Trace](http://i.imgur.com/qdwnb.png)


