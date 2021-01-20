<?php

namespace IQnection\WordPress;

use SilverStripe\Forms;
use SilverStripe\Control\Director;
use SilverStripe\ORM;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\FieldType;
use SilverStripe\View\ArrayData;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Control\Controller;

class WordPressRedirectPage extends \Page
{
	private static $table_name = 'WordPressRedirectPage';

	private static $icon = "iqnection-pages/basepages:images/icons/icon-blog-file.gif";

	private static $feed_cache_lifetime = '-1 hour';

	private static $db = array(
		"WordPressURL" => "Varchar(255)"
	);

	private static $search_config = array(
		"ignore_in_search" => true
	);

	private static $defaults = array(
		'ShowInSearch' => false
	);

	public function getCMSFields()
	{
		$fields = parent::getCMSFields();
		$fields->removeFieldFromTab("Root", "Content");
		$fields->removeByName('Metadata');
		$fields->removeByName('Developer');
		$fields->addFieldToTab("Root.Main", Forms\TextField::create("WordPressURL", "WordPress Segment (eg. 'blog', 'news', etc.)"));

		if ($this->WordPressURL)
		{
			$fields->addFieldToTab("Root.Main", Forms\LiteralField::create("Desc1", '<div id="wp-login"><h1><img src="resources/vendor/iqnection-pages/basepages/images/wordpress-logo.png" alt="WordPress" /></h1>
			<a href="'.Director::AbsoluteBaseURL().$this->WordPressURL.'/wp-login.php" target="_blank"><img src="resources/vendor/iqnection-pages/basepages/images/wordpress-login.png" alt="Login" /></a></div>'));
		}
		return $fields;
	}

	public function validate()
	{
		$result = parent::validate();
		if ( ($this->ParentID == 0) && ($this->URLSegment) && ($this->URLSegment == $this->WordPressURL) )
		{
			$result->addError('The URL Segment for this page may cause an infinite loop if the SilverStripe path is the same as the WordPress directory. I suggest [site-title-blog] format.');
		}
		return $result;
	}

	public function updateRefreshCacheVars(&$vars)
	{
		$vars[] = 'WordPressURL';
		return $vars;
	}

	public function onAfterWrite()
	{
		parent::onAfterWrite();
		if (!$this->WordPressURL) { return; }
		$path = Director::baseFolder()."/.htaccess";
		$curr_data = @file($path);

		$extra = $this->WordPressURL ? "RewriteCond %{REQUEST_URI} !^/".$this->WordPressURL."$" : "";
		$extra2 = $this->WordPressURL ? "RewriteCond %{REQUEST_URI} !^/".$this->WordPressURL."/" : "";

		$new_file = array();

		// first remove any blog redirect already in the file
		$remove_keys = array();
		foreach($curr_data as $key => $line)
		{
			if (trim($line) == "### Blog Redirect ###")
			{
				$remove_keys[] = $key;
				$remove_keys[] = $key + 1;
				$remove_keys[] = $key + 2;
			}

		}
		foreach($remove_keys as $key) { unset($curr_data[$key]); }

		$ss_line = 0;
		$finished = false;
		foreach ($curr_data as $line)
		{
			if ( (!$finished) && (trim($line) == "RewriteRule ^(.*)$ public/$1") )
			{
				$new_file[] = trim("### Blog Redirect ###");
				$new_file[] = trim($extra);
				$new_file[] = trim($extra2);
				$finished = true;
			}
			$new_file[] = trim($line);
		}

		$h = @fopen($path, "w");
		fwrite($h, implode("\n", $new_file));
		@fclose($h);
	}

	public function getXmlFeed()
	{
		$body = false;
		if ($BlogURL = $this->WordPressURL)
		{
			$BlogLink = Director::AbsoluteURL(Controller::join_links($BlogURL,'feed'));
			$client = new \GuzzleHttp\Client();
			try {
				$response = $client->request('GET',$BlogLink);
			} catch (Exception $e) {

			}
			$body = $response->getBody()->getContents();
		}
		$this->extend('updateXmlFeed',$body);
		return $body;
	}

	public function cacheXmlFilePath()
	{
		$cachePath = Director::baseFolder().'/blog-feed-'.$this->ID.'.xml';
		$this->extend('updateCacheXmlFilePath',$cachePath);
		return $cachePath;
	}

	public function clearXmlCache()
	{
		if (file_exists($this->cacheXmlFilePath()))
		{
			unlink($this->cacheXmlFilePath());
		}
		return $this;
	}

	protected function cachedXmlFeed()
	{
		if ($BlogURL = $this->WordPressURL)
		{
			$cachePath = $this->cacheXmlFilePath();;
			if ( (!file_exists($cachePath)) || (filemtime($cachePath) < strtotime($this->Config()->get('feed_cache_lifetime'))) )
			{
				$feed = $this->getXmlFeed();
				file_put_contents($cachePath,$feed);
				return $feed;
			}
		}
		$feed = (file_exists($cachePath)) ? file_get_contents($cachePath) : false;
		$this->extend('updateCachedXmlFeed',$feed);
		return $feed;
	}

	protected $_BlogFeed;
	public function getBlogFeed()
	{
		if (is_null($this->_BlogFeed))
		{
			$BlogFeed = ArrayList::create();
			$feed = $this->cachedXmlFeed();
			$xml = new \SimpleXMLElement($feed);
			$ns = $xml->getDocNamespaces();
			// parse each item
			foreach($xml->channel->item as $item)
			{
				$Content = preg_replace('/<img[^>]+>/','',$item->children($ns['content']));
				$obj = ArrayData::create([]);
				$obj->Title = FieldType\DBField::create_field(FieldType\DBVarchar::class,Convert::xml2raw($item->title));
				$obj->Link = FieldType\DBField::create_field(FieldType\DBVarchar::class,Convert::xml2raw($item->link));
				$obj->Datetime = FieldType\DBField::create_field(FieldType\DBDatetime::class,date('Y-m-d H:i:s',strtotime(Convert::xml2raw($item->pubDate))));
				$obj->Author = FieldType\DBField::create_field(FieldType\DBVarchar::class,Convert::xml2raw($item->children($ns['dc'])));
				$obj->Content = FieldType\DBField::create_field(FieldType\DBHTMLText::class,$Content);
				$obj->Description = FieldType\DBField::create_field(FieldType\DBHTMLText::class,Convert::xml2raw($item->description));
				$obj->CommentsCount = FieldType\DBField::create_field(FieldType\DBInt::class,Convert::xml2raw($item->children($ns['slash'])));
				$BlogFeed->push($obj);
			}
			$this->extend('updateBlogFeed',$BlogFeed);
			$this->_BlogFeed = $BlogFeed;
		}
		return $this->_BlogFeed;
	}
}








