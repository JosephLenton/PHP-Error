PHP Error | Improve Error Reporting for PHP
===========================================

PHP errors are not good enough for development, it's as simple as that. This aims to solve this.

![Better Error Message](http://i.imgur.com/1G77I.png)

When an error strikes, the page is replaced with a full stack trace, syntax highlighting, and all displayed to be readable.

### Works with Ajax too!

If the server errors during an ajax request, then the request is paused, and the error is displayed in the browser. You can then click to automatically retry the last request.

![ajax server stack trace](http://i.imgur.com/WRgug.png)

This requires no changes to your JavaScript, and works with existing JS libraries such as jQuery.

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

There is an online demo [here](http://phperror.net)

Useful Pages
------------

:__[API](https://github.com/JosephLenton/PHP-Error/wiki/API)__:

Example Usage
-------------

 * [Get the library](http://phperror.net/download/php_error.php), it's just one file.
 * Place it into your project.
 * import the file
 * call: \better_error_messages\reportErrors();
 * ???
 * profit! \o/

```php
    <?php
        require( 'php_error.php' );
        \php_error\reportErrors();
    ?>
```

Do not use on a live site!
--------------------------

This is intended for __development only__. It shows more about your site, gives you more info, and makes trivial errors fatal.
All of that is awesome if you want to debug quicker, and force high quality standards.

On a production server, that sucks, and is potentially unsafe.

In case you forget, you can disable this in production using the 'php_error.force_disabled' php.ini option (see below).

Advanced Features
-----------------

 * customization
 * manually turn it on and off
 * run specific sections without error reporting
 * ignore files allowing you to avoid highlighting code in your stack trace
 * application files; these are prioritized when an error strikes!
 
![Application Aware Stack Trace](http://i.imgur.com/qdwnb.png)

Options
-------

The options can be passed into 'reportErrors' when it is called.
They are stored in an associative array of 'option' mapping to the value.

### Usage Examples

Options can be passed in when calling 'reportErrors'.

```php
    // create an array of 'options', and pass them into report errors
    $options = array();
    \php_error\reportErrors( $options );
```

```php
    // a range of example options set
    // and then passed in when turning on reporting errors
    $options = array(
            'snippet_num_lines' => 10,
            'background_title'  => 'Error!',
            'error_reporting_off' => 0,
            'error_reporting_on' => E_ALL | E_STRICT
    );
    \php_error\reportErrors( $options );
```
    
If you chose to create the ErrorHandler manually (see API section),
then you can pass in the same options to it's constructor.

```php
    $options = array(
            // options set here
    );
    $handler = new \php_error\ErrorHandler( $options );
    $handler->turnOn();
```

### All Options

<table>
    <tr>
        <th>Option</th>
        <th>Default</th>
        <th>Descriptionth>
    </tr> 
    <tr>
        <td>catch_ajax_errors</td>
        <td>true</td>
        <td>
            When on, this will inject JS Ajax wrapping code, to allow this to catch any future JSON errors.
        </td>
    <tr>
    </tr>
        <td>catch_supressed_errors</td>
        <td>false</td>
        <td>
            The @ supresses errors. If set to true, then they are still reported anyway, but respected when false.
        </td>
    <tr>
    </tr>
        <td>catch_class_not_found</td>
        <td>true</td>
        <td>
            When true, loading a class that does not exist will be caught.
            If there are any existing class loaders, they will be run first, giving you a chance to load the class.
        </td>
    <tr>
    </tr>
        <td>error_reporting_on</td>
        <td>-1 (everything)</td>
        <td>
            The error reporting value for when errors are turned on by PHP Error.
        </td>
    <tr>
    </tr>
        <td>error_reporting_off</td>
        <td>the value of 'error_reporting'</td>
        <td>
            The error reporting value for when PHP Error reporting is turned off. By default it just goes back to the standard level.
        </td>
    <tr>
    </tr>
        <td>application_root</td>
        <td>$_SERVER['DOCUMENT_ROOT']</td>
        <td>
            When it's working out hte stack trace, this is the root folder of the application, to use as it's base.
            A relative path can be given, but lets be honest, an explicit path is the way to guarantee that you
            will get the path you want. My relative might not be the same as your relative.
        </td>
    <tr>
    </tr>
        <td>snippet_num_lines</td>
        <td>13</td>
        <td>
            The number of lines to display in the code snippet. 
            That includes the line being reported.
        </td>
    <tr>
    </tr>
        <td>server_name</td>
        <td>$_SERVER['SERVER_NAME']</td>
        <td>
            The name for this server; it's domain address or ip being used to access it.
            This is displayed in the output to tell you which project the error is being reported in.
        </td>
    <tr>
    </tr>
        <td>ignore_folders</td>
        <td>null (no folders)</td>
        <td>
            This is allows you to highlight non-framework code in a stack trace.
            An array of folders to ignore, when working out the stack trace.
            This is folder prefixes in relation to the application_root, whatever that might be.
            They are only ignored if there is a file found outside of them.
            If you still don't get what this does, don't worry, it's here cos I use it.
        </td>
    <tr>
    </tr>
        <td>application_folders</td>
        <td>null (no folders)</td>
        <td>
            Just like ignore, but anything found in these folders takes precedence
            over anything else.
        </td>
    <tr>
    </tr>
        <td>background_text</td>
        <td>an empty string</td>
        <td>
            The text that appeares in the background. By default this is blank.
            Why? You can replace this with the name of your framework, for extra customization spice.
        </td>
    </tr>
</table>

### php.ini - changing the defaults

The default values for the options can be set in your php.ini file.
Just specify the option with 'php_error.' prefixed, and then set the new default value.

For example to turn off catching ajax errors and changing the number of line numbers displayed:

```
    php_error.catch_ajax_errors = Off
    php_error.snippet_num_line = 20
``` 

This allows you to set these changes globally for all projects.

php.ini options
---------------

Add these to your php.ini, or other .ini files, as global options for PHP-Error.

### php_error.force_disabled

When set to 'on', the error reporter will look and act like it's running, but really it does nothing.
No setup changes, no error reporting, and no other work.

```
    php_error.force_disabled = On
```

If you manually call 'turnOn', it on will also silently fail, and still be set to turned off.

My advice is to never put PHP-error into production. This option exists incase you forget,
so you can disable it for all of your sites as a part of your global configuration.

