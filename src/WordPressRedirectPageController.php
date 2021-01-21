<?php

namespace IQnection\WordPress;

use SilverStripe\Control\Director;
use SilverStripe\Assets\File;

class WordPressRedirectPageController extends \PageController
{
    /**
    * stores the templates to be cached
    * key: name of the array kay
    * value: path or paths where templates can be found
    * to remove a key:value, just set the value to false
    *
    * @var array
    */
    private static $cache_templates = [
        'header' => [
            'Header',
            'Includes/Header'
        ],
        'footer' => [
            'Footer',
            'Includes/Footer'
        ]
    ];

	private static $allowed_actions = array(
		"index"
	);

	public function index()
	{
		// generate the template cache, if needed
		$cachePath = $this->templateCacheFilePath();
		if ( (!file_exists($cachePath)) || (filemtime($cachePath) < strtotime('-1 hour')) )
		{
			$this->generateTemplateCache();
		}
		return $this->redirect($this->WordPressAbsoluteURL());
	}



    public function generateTemplateCache()
    {
        $cache = [];
        $cache_templates = array_filter($this->Config()->get('cache_templates'));
        foreach($cache_templates as $key => $value)
        {
            // base 64 encode to make sure there are no JSON errors
            $cache[$key] = base64_encode($this->Customise(array('ForCache' => true))->renderWith($value)->AbsoluteLinks());
        }
        $this->extend('updateGeneratedTemplateCache', $cache);
        $cache = json_encode($cache);
        $cacheFilePath = $this->templateCacheFilePath();
        file_put_contents($cacheFilePath,$cache);
        return $cache;
    }

}








