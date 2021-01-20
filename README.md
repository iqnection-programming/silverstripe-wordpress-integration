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

