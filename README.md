# Provides a simple integration to use a WordPress blog with your SilverStripe site

## Install via Composer
```
composer require iqnection/silverstripe-wordpress-integration
```

## Usage
Create a WordPress Redirect Page and set the URL directory (from root) for where the WordPress install lives
- The site root .htaccess will be updated to allow requests to this directory

You can also retrieve posts from your WP blog to display on your SS pages
```
// find the page model
$wpPage = WordPressRedirectPage::get()->byId($id);
// retrieve the posts
$posts = $wpPage->getBlogFeed();
```
Posts will be cached so the RSS is not queried on every page request

When the URL for the page is requested, a cache of specified templates will be created, which can then be injected into your WordPress theme.
This provides the ability to utilize the same header and footer between platforms.
The template cache will be store at path/to/site/root/template-cache/{page-url}.json
The json consists of templates you cached, stored as base 64 strings.

In your WP theme, simple load this file, decode the JSON, then base64 decode the array values.

## Configuration
You can add or remove elements to cache. 
Set array key: value(s) for which templates to cache. Use the same key in your WordPress theme to access the rendered HTML
When teh templates are rendered, a template variable $ForCache is provided, incase you don't want certain elements to render, like forms.
```
<% if not $ForCache %>
	$MyForm
<% end_if %>
```

### Defaults
Default cached templates are 
- header: Includes/Header
- footer: Includes/Footer

### Adding templates to render
```
IQnection\WordPress\WordPressRedirectPageController:
  cache_templates:
    my-key: 'path/to/template'
```


