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
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\View\Parsers\URLSegmentFilter;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Assets\File;

class WordPressRedirectPage extends \Page
{
	private static $table_name = 'WordPressRedirectPage';

	private static $icon = "iqnection/silverstripe-wordpress-integration:client/images/icon-blog-file.gif";

	private static $feed_cache_lifetime = 3600;

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
		$fields->removeByName([
            "Content",
		    'Metadata',
            'ElementalArea',
		    'Developer'
        ]);
		$fields->addFieldToTab("Root.Main", Forms\TextField::create("WordPressURL", "WordPress Segment (eg. 'blog', 'news', etc.)"));

		if ($wpUrl = $this->WordPressAdminURL())
		{
			$fields->addFieldToTab("Root.Main", Forms\LiteralField::create("Desc1", '<div id="wp-login"><h1><img src="'.\SilverStripe\Core\Manifest\ModuleResourceLoader::singleton()->resolveURL('iqnection/silverstripe-wordpress-integration:client/images/wordpress-logo.png').'" alt="WordPress" /></h1>
			<a href="'.$wpUrl.'" target="_blank"><img src="'.\SilverStripe\Core\Manifest\ModuleResourceLoader::singleton()->resolveURL('iqnection/silverstripe-wordpress-integration:client/images/wordpress-login.png').'" alt="Login" /></a></div>'));
		}
		return $fields;
	}

    public function WordPressAbsoluteURL()
    {
        if (preg_match('/^http/',$this->WordPressURL))
        {
            return $this->WordPressURL;
        }
        return Director::AbsoluteURL($this->WordPressURL);
    }

    public function WordPressRelativePath()
    {
        return Director::makeRelative($this->WordPressAbsoluteURL());
    }

    public function WordPressAdminURL()
    {
        return Controller::join_links($this->WordPressAbsoluteURL(), 'wp-login.php');
    }

	public function validate()
	{
		$result = parent::validate();
        if (Director::is_site_url($this->WordPressURL))
        {
            $this->WordPressURL = Director::makeRelative($this->WordPressURL);
		    if ( ($this->ParentID == 0) && ($this->URLSegment) && ($this->URLSegment == $this->WordPressURL) )
		    {
                $suggest = URLSegmentFilter::singleton()->filter(SiteConfig::current_site_config()->Title.' '.$this->Title);
			    $result->addError('The URL Segment for this page may cause an infinite loop if the SilverStripe path is the same as the WordPress directory. I suggest ['.$suggest.'] format.');
		    }
        }
		return $result;
	}

	public function updateRefreshCacheVars(&$vars)
	{
        $vars[] = 'WordPressURL';
        $vars[] = 'WordPressFullURL';
		return $vars;
	}

    public function onAfterUnpublish()
    {
        $path = Director::baseFolder()."/.htaccess";
        $curr_data = @file($path);
        $inside = false;
        foreach($curr_data as &$line)
        {
            $line = trim($line);
            if (preg_match('/\['.$this->ID.'\] Begin WordPress Redirect/', $line))
            {
                $inside = true;
            }
            if ( ($inside) && (preg_match('/^(\s|\t)?RewriteRule/',$line)) )
            {
                $inside = false;
            }
            if ($inside)
            {
                $line = false;
            }
            if (preg_match('/\['.$this->ID.'\] End WordPress Redirect/', $line))
            {
                $inside = false;
            }
        }
        $curr_data = array_filter($curr_data);
        $h = @fopen($path, "w");
        fwrite($h, implode("\n", $curr_data));
        @fclose($h);
    }

	public function onAfterPublish()
	{
		if ( (!$this->WordPressURL) || (!Director::is_site_url($this->WordPressURL)) )
        {
            return;
        }
		$path = Director::baseFolder()."/.htaccess";
		$curr_data = @file($path);
		// first remove any blog redirect already in the file
		$inside = false;
		foreach($curr_data as &$line)
		{
            $line = trim($line);
			if (preg_match('/\['.$this->ID.'\] Begin WordPress Redirect/', $line))
			{
                $inside = true;
			}
            if ($inside)
            {
                $line = false;
            }
            if (preg_match('/\['.$this->ID.'\] End WordPress Redirect/', $line))
            {
                $inside = false;
            }
		}
        $curr_data = array_filter($curr_data);
		$finished = false;
        $new_file = [];
		foreach($curr_data as $index => $cleanline)
		{
			if ( (!$finished) && (preg_match('/RewriteRule.*?public\/\$1/', trim($cleanline))) )
			{
                $new_file[] = "### [".$this->ID."] Begin WordPress Redirect  - DO NOT REMOVE ###";
				$new_file[] = "RewriteCond %{REQUEST_URI} !^/".$this->WordPressURL."$";
				$new_file[] = "RewriteCond %{REQUEST_URI} !^/".$this->WordPressURL."/";
				$new_file[] = "### [".$this->ID."] End WordPress Redirect  - DO NOT REMOVE ###";
				$finished = true;
			}
			$new_file[] = $cleanline;
		}
        $new_file = array_filter($new_file);
		$h = @fopen($path, "w");
		fwrite($h, implode("\n", $new_file));
		@fclose($h);
	}

	public function getXmlFeed()
	{
		$body = false;
		if ($BlogURL = $this->WordPressAbsoluteURL())
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

	public function WPCacheInterface()
	{
        return Injector::inst()->get(CacheInterface::class . '.wpCache');
	}

	public function clearWPCachedXmlFeed()
	{
        $this->WPCacheInterface()->clear();
        return $this;
	}

	protected function setWPCachedXmlFeed($feed)
	{
        $this->WPCacheInterface()->set('wpFeed', $feed, $this->Config()->get('feed_cache_lifetime'));
	}

    protected function getWPCachedXmlFeed()
    {
        $this->WPCacheInterface()->get('wpFeed');
    }

    public function clearTemplateCache()
    {
        if ( ($filePath = $this->templateCacheFilePath()) && (file_exists($filePath)) )
        {
            unlink($filePath);
        }
        return $this;
    }

    public function onAfterBuild()
    {
        parent::onAfterBuild();
        $this->clearTemplateCache();
    }

    public function templateCacheDirPath()
    {
        $cacheDirPath = Director::getAbsFile('template-cache');
        // make sure the cache directory exists
        if (!file_exists($cacheDirPath))
        {
            mkdir($cacheDirPath,0755);
            file_put_contents(File::join_paths($cacheDirPath, '.htaccess'),"Order deny,allow\nDeny from all\nAllow from 127.0.0.1");
        }
        return $cacheDirPath;
    }

    public function templateCacheFilePath()
    {
        $fileName = trim( preg_replace('/[^a-zA-Z0-9\-_]+/', '-', $this->WordPressRelativePath()), ' -_').'.json';
        return Director::getAbsFile('template-cache'.DIRECTORY_SEPARATOR.$fileName);
    }

	protected $_WPFeed;
	public function getWPFeed()
	{
		if (is_null($this->_WPFeed))
		{
            $this->_WPFeed = false;
			$BlogFeed = ArrayList::create();
			$feed = $this->getWPCachedXmlFeed();
            if (!trim($feed))
            {
                if (!$feed = $this->getXmlFeed())
                {
                    return;
                }
                $this->setWPCachedXmlFeed($feed);
            }
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
			$this->_WPFeed = $BlogFeed;
		}
		return $this->_WPFeed;
	}
}








