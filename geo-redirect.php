<?php
/*
Plugin Name:Geo Redirect
Plugin URI: http://wordpress.org/extend/plugins/geographical-redirect/
Description: A plugin that provides visitor redirect according to their geographical location
Author: Ladrower
Author URI: http://profiles.wordpress.org/users/Ladrower/
Author e-mail: ladrower@gmail.com
Version: 3.3.1
License: Free
*/
require_once "geoip/geoipcity.inc";
require_once "geo-redirect-admin.php";

add_action( 'check_client_location', 'geo_redirect_client_location' );

class Geo_Redirect{
	private $ip;
	private $gi;
	private	$country_code;
	private	$country_id;
	private $geo_redirect_data;
	private	$site_lang;
	private	$lang_slug;
	private $site_url;
	private $request_uri;
	private $no_redirect;
	private $referer;
	
	public function __construct()
	{
		$this->ip = $this->getClientIP();
		$this->gi = geoip_open( dirname(__FILE__) . "/geoip/ipdatabase/GeoIP.dat/GeoIP.dat", GEOIP_STANDARD);
		$this->site_url = get_home_url();
		$this->request_uri = $this->getRequestUri();
		$this->no_redirect = (isset($_GET['no_redirect']) || (isset($_POST['pwd']) && isset($_POST['log']))) ? true : false;
		$this->referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
		$this->lang_slug = 'lang';
		$this->getGeoRedirectData();
		$this->getSiteLang();
	}

	private function getClientIP()
	{
		$ip = $_SERVER['REMOTE_ADDR'];
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		}
		$ips = explode(",", $ip);
		return $ips[0];
	}

    private function getRequestUri()
    {
        if (!$this->site_url)
            $this->site_url = get_home_url();

        $site_url_parsed = parse_url($this->site_url);
        $site_root = $site_url_parsed['scheme'] . '://' . $site_url_parsed['host'];
        $uri = str_ireplace($this->site_url, '', $site_root . $_SERVER['REQUEST_URI']);

        return $uri;
    }
	
	public function getCountryCode()
	{
		$this->country_code = geoip_country_code_by_addr($this->gi,$this->ip);  
		return $this->country_code;
	}

	public function getCountryId()
	{
		$this->country_id = geoip_country_id_by_addr($this->gi,$this->ip);
		return $this->country_id;
	}
	
	public function getGeoRedirectData()
	{
		if (function_exists('get_option'))
			$this->geo_redirect_data = get_option('geo_redirect_data');
		return $this->geo_redirect_data;
	}
	
	public function getSiteLang()
	{
		if (!is_array($this->geo_redirect_data))
			return '';
			
		$this->lang_slug = ($this->geo_redirect_data['lang_slug'] != '') ? $this->geo_redirect_data['lang_slug'] : 'lang';
		$this->site_lang = (isset($_GET[$this->lang_slug])) ? ( (string) htmlentities(strtolower($_GET[$this->lang_slug])) ) : '';	
		return $this->site_lang;
	}

    public function getAllLangCodes()
    {
        $geoip = new GeoIP();
        $codes = $geoip->GEOIP_LANG_CODES;
        array_shift($codes);
        if (is_array($this->geo_redirect_data['redirect'])) {
            foreach ($this->geo_redirect_data['redirect'] as $data) {
                if ($data['lang_code'] != '') {
                    array_push($codes, strtoupper($data['lang_code']));
                }
            }
        }
        return array_unique($codes, SORT_STRING);
    }
	
	private function selectRedirectOption($data)
    {
        switch ($data['redirect_option']) {
            case 1:
                if ($data['lang_code'] != '') {
                    if ($data['pretty'] == 1) {
                        if (stripos($this->site_url . $this->request_uri, $this->site_url . '/' . $data['lang_code'] . '/') === false) {
                            $queries = explode('?', $this->request_uri, 2);
                            $query = (isset($queries[1])) ? '?' . $queries[1] : '';
                            $paths = explode('/', ltrim($queries[0],'/'));
                            if (in_array(strtoupper($paths[0]), $this->getAllLangCodes())) {
                                array_shift($paths);
                            }
                            $uri = '/' . implode('/',$paths) . $query;

                            $this->redirectByUrl(home_url($data['lang_code']) . $uri);
                        }
                    } elseif ($this->site_lang != $data['lang_code']) {
                        $this->redirectByLang($data['lang_code']);
                    }
                }
            break;
            case 2:
                if ($data['domain'] != '' && $this->site_url != $data['domain']) {
                    $this->redirectByDomain($data['domain']);
                }
            break;
            case 3:
                if ($data['url'] != '') {
                    $current_url = $this->site_url . $this->request_uri;
                    if ($current_url != $data['url']) {
                        $this->redirectByUrl($data['url']);
                    }
                }
            break;
        }

    }

    public function checkIfRedirectNeeded()
	{
		if (!is_array($this->geo_redirect_data))
			return;

        if ($this->no_redirect)
            return;

        if ($this->checkReferer())
            return;

        if ($this->checkRoot())
            return;

        if ($this->checkOnceCookie())
            return;
		
		if (is_array($this->geo_redirect_data['redirect'])) {

            $this->getCountryId();

			foreach($this->geo_redirect_data['redirect'] as $data) {

				if ($this->country_id == $data['country_id']) {

                    $this->selectRedirectOption($data);
                    return;

				} elseif (-1 == $data['country_id']) {
					$default_data = $data;
				}
			}

			if (!empty($default_data)) {
                $this->selectRedirectOption($default_data);
            }
		}
	
	}
	
	private function redirectByUrl($url)
	{
		if ($url != ''){
			$this->redirectTo($url);
		}
	}

	private function redirectByDomain($domain)
	{
		if ($domain != ''){
			$to = $domain . $this->request_uri;
			$this->redirectTo($to);
		}
	}
	
	private function redirectByLang($lang_code)
	{
		if ($lang_code != ''){
			$lang_url = ((strpos($this->request_uri,'?') === false) ? '?' : '&') . $this->lang_slug . '=' . urlencode($lang_code);
			$to = $this->site_url . $this->request_uri . $lang_url;
			$this->redirectTo($to);
		}
	}

	private function beforeRedirect()
	{
		if ($this->getRedirectDataFlag('only_once') == 1) {
			setcookie('wordpress_geo_redirect_once', '1', time()+60*60*24*365, '/');
		} 
	}

    private function deepReplace( $search, $subject )
    {
    	$found = true;
    	$subject = (string) $subject;
    	while ( $found ) {
    		$found = false;
    		foreach ( (array) $search as $val ) {
    			while ( strpos( $subject, $val ) !== false ) {
    				$found = true;
    				$subject = str_replace( $val, '', $subject );
    			}
    		}
    	}

    	return $subject;
    }

    private function sanitizeRedirect($location)
    {
    	$location = preg_replace('|[^a-z0-9-~+_.?#=&;,/:%!]|i', '', $location);
    	$location = wp_kses_no_null($location);

    	// remove %0d and %0a from location
    	$strip = array('%0d', '%0a', '%0D', '%0A');
    	$location = $this->deepReplace($strip, $location);
    	return $location;
    }
	
	private function redirectTo($to)
	{
        $this->beforeRedirect();
        $to = $this->sanitizeRedirect($to);
        header("Location: " . $to);
		exit;
	}

	private function getRedirectDataFlag($name, $default = 0)
	{
		return (isset($this->geo_redirect_data[$name])) ? $this->geo_redirect_data[$name] : $default;
	}

	private function checkOnceCookie()
	{
		if ($this->getRedirectDataFlag('only_once') == 1) {
			if (isset($_COOKIE['wordpress_geo_redirect_once'])) {
				return true;
			}
		}
		return false;
	}

    private function checkRoot()
    {
        if ($this->getRedirectDataFlag('only_root') == 1) {
            if (trim($this->request_uri,'/') != '')
                return true;
        }
        return false;
    }
	
	private function checkReferer()
	{
		if ($this->getRedirectDataFlag('only_outsite') == 1) {
			if (empty($this->referer))
				return true;

			$insite = parse_url($this->site_url);
            $outsite = parse_url($this->referer);
			if ($insite['scheme'] . '://' . $insite['host'] != $outsite['scheme'] . '://' . $outsite['host'])
				return false;
				
			return true;
		}
		return false;
	}
	
	public function __destruct()
	{
		geoip_close($this->gi);
	}	
	
}

function geo_redirect_client_location() {
	$geo = new Geo_Redirect();
	$geo->checkIfRedirectNeeded();
}

if (!is_admin()) {
	do_action('check_client_location');
}

?>