<?php

/*

FIXME:

- CRITICAL: find cause of high cpu (99%) and load average (1.00+) 
  - get rid of in_array() calls...
    10,000 in the test below...
      isset:    0.009623
      in_array: 1.738441

- add option to produce json list of bad links, for use in apps (in-page link check highlighting)

    "http://xyz.com/some/page": {
        "/some/other/page": "error",
        "http://abc.com/page": "error...",
        "../file": "error...",
        "http://abc.com/doc": "warn...",
    }

- youtube quits responding after X checks
  - per host delay setting?
  - maybe robots.txt has max rate?
- links that are non-200 but good in browser
  - http://fcms/t/checklink.php
  - respect robots.txt: not allowed, max rate, etc
  - set UA, other headers, to recent chrome's
  - http://www.wetland.org/education_wow.htm -- 400 bad request, but works in browser: requires UA, other headers?
    see: https://websiteadvantage.com.au/Request-HTTP-Header-Info
  - add no ssl verify to curl, option for curl strict ssl cert checking
- use curl to feed dom object, so it is subject to same headers, timeout, etc.
- cache last used hostnames, and only apply delay for same host (speed up)
- build_earl() needs a rewrite
- re-enable max $depth fail safe, this time using path parts count

*/

class LinkChecker
{
// FIXME: move these to constructor...
private $depth = 8; // FIXME: no longer does anything
private $url;
private $results = array();
//private $same_host = false;
private $host;

public function setDepth($depth) { $this->depth = $depth; }
public function setHost($host) { $this->host = $host; }
public function getResults() { return $this->results; }
//public function setSameHost($same_host) { $this->same_host = $same_host; }

    //public function __construct($url = null, $depth = null, $same_host = false)
    public function __construct($url = null, $depth = null)
    {
    $this->bugger = true;
    //$this->bugger_stop = -1; // no stop
    // $this->bugger_stop = 3; // stop at $this->bugger_stop pages

    $this->curl_timeout = 30;
    $this->delay = 2;
    $this->site_map = array ();
    $this->redirects = array ();
    $this->seen_hashes = array ();
    $this->page_aliases = array ();
    $this->ignore_urls = array ();
    $this->translate_urls = array ();
    $this->page_pointer = false;
    $this->earl_scheme = false;
    $this->stats = array (
        'pages' => 0,
        'links' => 0,
        'bad_links' => 0,
        // FIXME: more stats...
    );
    $this->seen_earls = array (); // FIXME: in_array(x, (statis) $seen) replacement
    $this->translated_site_map = array (); // FIXME: page => {href => earl} map

    // FIXME: config file for ignore urls, etc.
    if (! $this->str_starts_with($url, 'http') && file_exists ($url)) $this->parse_config($url);
    else if (! empty ($url)) $this->set_earl($url);


    // FIXME: use getopt() or maybe take $ARGV[1] as the required config?
    //        --html      print html report
    //        --config    config file for ignore urls, etc.


    if (isset ($depth) && ! is_null ($depth)) $this->setDepth($depth);
    //$this->setSameHost($same_host);
    }

    /*********************
    *                    *
    *   parse_config()   *
    *                    *
    *********************/
    function parse_config ($cf)
    {
    if (! file_exists ($cf)) die ('no config file ' . $cf . ' found');
    $cfh = file ($cf);
    $conf = array ();

        for ($i = 0; $i < count ($cfh); $i++)
        {
        preg_match ("/^\s*([^#\s]+)\s*=\s*\"?([^\"]+)\"?/", $cfh[$i], $matches); 
        $k = isset ($matches[1]) ? $matches[1] : false;
        $v = isset ($matches[2]) ? trim ($matches[2]) : false;

            if ($k && $v)
            {
                if (isset ($conf[$k]))
                {
                    if (! is_array ($conf[$k]))
                    {
                    $oval = $conf[$k];
                    $conf[$k] = array ();
                    array_push ($conf[$k], $oval);
                    }

                array_push ($conf[$k], $v);
                }
                else
                {
                $conf[$k] = $v;
                }
            }
        }

    $this->set_earl($conf['site']);

        if (isset ($conf['ignore']))
        {
        if (! is_array ($conf['ignore'])) $this->ignore_urls = array ($conf['ignore']);
        else $this->ignore_urls = $conf['ignore'];
        }
        
        if (isset ($conf['translate']))
        {
        $tmp = array ();
        if (! is_array ($conf['translate'])) $tmp = array ($conf['translate']);
        else $tmp = $conf['translate'];

            foreach ($tmp as $t)
            {
            $parts = preg_split ('/\s+/', $t);
            $this->translate_urls[$parts[0]] = $parts[1];
            }
        }        
    }

    /*****************
    *                *
    *   set_earl()   *
    *                *
    *****************/
    public function set_earl ($url)
    {
    $this->url = $url;
    $this->setHost($this->get_host($url));
    }

    /**************
    *             *
    *   crawl()   *
    *             *
    **************/
    public function crawl ()
    {
        if (empty ($this->url)) 
        {
        throw new \Exception('URL must be set');
        }

    $this->earl_scheme = $this->str_starts_with($this->url, 'https:') ? 'https' : 'http';
    $this->_crawl($this->url, $this->depth);
    $this->mk_site_map();

    return $this->results;
    }

    /********************
    *                   *
    *   mk_site_map()   *
    *                   *
    ********************/
    private function mk_site_map ()
    {
        foreach (array_keys ($this->site_map) as $k)
        {
            foreach (array_keys ($this->site_map[$k]) as $earl)
            {
                if (isset ($this->site_map[$k][$earl]) && $this->site_map[$k][$earl] === false)
                    $this->site_map[$k][$earl] = $this->results[$earl]['status'];

                if (isset ($this->page_aliases[$earl])) unset ($this->site_map[$k][$earl]);
            }

            if (isset ($this->page_aliases[$k])) unset ($this->site_map[$k]);
        }
    }

    /******************
    *                 *
    *   is_ignore()   *
    *                 *
    ******************/
    private function is_ignore ($earl)
    {
    $ignore = in_array ($earl, $this->ignore_urls) ? true : false; // FIXME: should take regex
    if ($ignore) $this->bugger_add('*** is_ignore ***', $earl); // bugger

    return $ignore;
    }

    /*********************
    *                    *
    *   is_translate()   *
    *                    *
    *********************/
    private function is_translate ($earl)
    {
    $translate = in_array ($earl, array_keys ($this->translate_urls)) ? true : false; // FIXME: regex maybe?
    if ($translate) $this->bugger_add('*** is_translate ***', $earl); // bugger

    return $translate;
    }

    /***************
    *              *
    *   _crawl()   *
    *              *
    ***************/
    private function _crawl ($url, $depth)
    {
    static $seen = array (); // FIXME: in_array() slow!

    $this->bugger_add('_crawl', $url); // bugger
    sleep ($this->delay); // be friendly :)
    //$this->bugger_add('$seen', $seen); // bugger

    if (empty ($url)) return;

    // FIXME: this already gets checked in the anchor tags loop, move before main crawl to abort on initial earl?
    //if (! $url = $this->build_earl($this->url, $url)) return;

        // FIXME: depth meant to prevent endless loops, fails.... 
        //if ($depth === 0 && $this->is_same_host($url)) 

        if (isset ($this->results[$url]))
        {
        $this->bugger_add(' isset ($this->results[$url]) RETURN', $url); // bugger
        if ($this->page_pointer != 0 && ! isset ($this->site_map[$this->page_pointer][$url])) 
            $this->site_map[$this->page_pointer][$url] = $this->results[$url]['status'];
        return;
        }

    $hinfo = $this->get_head($url);
    if ($hinfo['status'] == 500) $hinfo = $this->get_head($url, true); // some servers don't like HEAD
    if ($hinfo['redirect']) $this->redirects[$url] = $hinfo['redirect'];

    $this->results[$url] = array (
        'status' => $hinfo['status'],
        'depth' => $depth,
    );
    if ($this->page_pointer != 0 && ! isset ($this->site_map[$this->page_pointer][$url])) 
        $this->site_map[$this->page_pointer][$url] = $this->results[$url]['status'];


        if ($this->is_type_html($hinfo['content_type']) && $this->is_same_host($url) && $hinfo['status'] == 200) 
        {
        //$this->bugger_add('  $this->bugger_stop', $this->bugger_stop); // bugger
        //if ($this->bugger_stop == 0) return; // stop N pages, for testing only
        //$this->bugger_stop--;

        $this->bugger_add(' get dom', $url); // bugger

        if (! isset ($this->site_map[$url])) $this->site_map[$url] = array ();
        $this->page_pointer = $url;
        // $this->bugger_add('  page_pointer', $this->page_pointer); // bugger

        $dom = new \DOMDocument('1.0');
        @$dom->loadHTMLFile($url);

        $sig = md5 ($dom->saveHTML());
        if (! isset ($this->seen_hashes[$sig])) $this->seen_hashes[$sig] = $url;
        else return $this->is_alias($sig, $url);

        $anchors = $dom->getElementsByTagName('a');
        $crawled = $seen; // saving links to find difference later // FIXME: in_array() slow!

            foreach ($anchors as $element)
            {
            $a_href = $element->getAttribute('href');
            $this->bugger_add('  each $a_href', $a_href); // bugger
            $href = $this->build_earl($url, $a_href);
            $this->bugger_add('  build_earl() $href', $href); // bugger

            if (! $href) continue;
            if ($this->is_ignore($href)) continue;

            if ($this->is_translate($href)) $href = $this->translate_urls[$href];

            $this->bugger_add('  add to site_map $href', $href); // bugger
            $this->site_map[$this->page_pointer][$href] = false;
            if (! in_array ($href, $seen)) $seen[] = $href; // FIXME: in_array() slow!
            }
        
        // set array difference from links already marked to crawl
        $crawl = array_diff ($seen, $crawled); // FIXME: in_array() slow!
            
        // check if there are links to crawl
        if (! empty ($crawl)) array_map (array ($this, '_crawl'), $crawl, array_fill (0, count ($crawl), $depth - 1));
        }

    return $url;
    }

    /*****************
    *                *
    *   is_alias()   *
    *                *
    *****************/
    private function is_alias ($hash, $earl)
    {
    $this->page_aliases[$earl] = $hash;

    return false;
    }

    /*********************
    *                    *
    *   is_same_host()   *
    *                    *
    *********************/
    private function is_same_host ($url)
    {
    return $this->host == $this->get_host($url) ? true : false;
    }

    /*********************
    *                    *
    *   is_type_html()   *
    *                    *
    *********************/
    private function is_type_html ($ctype)
    {
    return preg_match ('/text\/html/', $ctype) ? true : false;
    }

    /*****************
    *                *
    *   get_head()   *
    *                *
    *****************/
    private function get_head ($earl, $use_get = false)
    {
    $this->bugger_add(' get_head()', $earl); // bugger
    $res = array (
        'status' => 1,
        'redirect' => false,
    );

    $ch = curl_init ($earl);
    curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, true);

    if ($use_get) curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
    else curl_setopt ($ch, CURLOPT_NOBODY, true);

    curl_setopt ($ch, CURLOPT_TIMEOUT, $this->curl_timeout);
    $retdata = curl_exec ($ch);
    $err = curl_error ($ch);
    //if ($err != '') $this->bugger_add('curl_error', $err); // bugger
    $info = curl_getinfo ($ch);
    curl_close ($ch);
    //$this->bugger_add('curl_getinfo', $info); // bugger
    $res['status'] = $info['http_code'];
    $res['content_type'] = $info['content_type'];
    $res['message'] = $info['http_code'] == 200 ? 'ok' : 'BAD LINK';

        if ($err != '') 
        {
        if (preg_match ('/Maximum \(\n+\) redirects/i', $err)) $res['status'] = 2;
        else if (preg_match ('/resolve host/i', $err)) $res['status'] = 3;
        else if (preg_match ('/connection timed out/i', $err)) $res['status'] = 4;
        }        

    if ($info['redirect_count']) $res['redirect'] = $info['url'];

    return $res;
    }

    /************************
    *                       *
    *   str_starts_with()   *
    *                       *
    ************************/
    private function str_starts_with ($str, $starts_with)
    {
    return strpos ($str, $starts_with) === 0 ? true : false;
    }

    /*******************
    *                  *
    *   build_earl()   *
    *                  *
    *******************/
    private function build_earl ($url, $href)
    {
    //$this->bugger_add('curl_getinfo', $info); // bugger
    $url = trim ($url);
    $href = trim ($href);

        if (! $this->str_starts_with($href, "http://") && ! $this->str_starts_with($href, "https://"))
        {
            if ($this->str_starts_with($href, 'javascript:') || 
                $this->str_starts_with($href, 'mailto:') ||
                $this->str_starts_with($href, 'tel:') ||
                $this->str_starts_with($href, '#')) return false;

        $path = '/' . ltrim ($href, '/');
        $parts = parse_url ($url);
        $new_href = $this->implode_earl($parts);
        $new_href .= $path;

            // Relative urls... (like ./viewforum.php)
            if (0 === strpos ($href, './') && ! empty ($parts['path']))
            {
                // If the path isn't really a path (doesn't end with slash)...
                if (! preg_match ('@/$@', $parts['path'])) 
                {
                $path_parts = explode ('/', $parts['path']);
                array_pop ($path_parts);
                $parts['path'] = implode ('/', $path_parts) . '/';
                }

            $new_href = $this->implode_earl($parts) . $parts['path'] . ltrim ($href, './');
            }

        $href = $new_href;
        }

    // normalize query params, if exists
    $test_qs = parse_url ($href, PHP_URL_QUERY);
    if ($test_qs !== null) $href = $this->normalize_params($href);
    else if (preg_match ('/#/', $href) $href = preg_replace ('/#.*$/', '', $href); // get rid of fragments
    
    // FIXME: preserve option to not check ext links, can be used for site-map generation
    // if ($this->same_host && $this->host != $this->get_host($href)) return false;

    return $href;
    }

    /*************************
    *                        *
    *   normalize_params()   *
    *                        *
    *************************/
    function normalize_params ($earl)
    {
    $parts = parse_url ($earl);
    $parts['query'] = $this->sort_params($parts['query']);

    $nearl = $parts['scheme'] . "://";
    // FIXME: use of user:pass@ is depreciated
    if (isset ($parts['user']) && isset ($parts['pass'])) $new_href .= $parts['user'] . ':' . $parts['pass'] . '@';
    $nearl .= $parts['host'];
    if (isset ($parts['port'])) $nearl .= ':' . $parts['port'];
    $nearl .= '' . $parts['path'];
    $nearl .= '?' . $parts['query'];

    return $nearl;
    }

    /********************
    *                   *
    *   sort_params()   *
    *                   *
    ********************/
    function sort_params ($qs)
    {
    $qs_struct = array ();
    $pair = explode ('&', $qs);
    $newpairs = array ();

        foreach ($pair as $p)
        {
        $kv = explode ('=', $p);
        if (! isset ($kv[0]) || empty ($kv[0])) continue;
        $k = $kv[0];
        $v = isset ($kv[1]) ? $kv[1] : '';

            if (! isset ($qs_struct[$k])) 
            {
            $qs_struct[$k] = $v;
            }
            else
            {
                if (! is_array ($qs_struct[$k])) 
                {
                $tmpval = $qs_struct[$k];
                $qs_struct[$k] = array ($tmpval);
                }

            $qs_struct[$k][] = $v;
            }
        }

        foreach (array_keys ($qs_struct) as $k)
        {
        if (is_array ($qs_struct[$k])) sort ($qs_struct[$k]);
        }
        
    ksort ($qs_struct);

        foreach (array_keys ($qs_struct) as $k)
        {
            if (is_array ($qs_struct[$k]))
            {
            foreach ($qs_struct[$k] as $v) $newpairs[] = $k . '=' . $v;
            }
            else
            {
            $newpairs[] = $k . '=' . $qs_struct[$k];
            }
        }

    return implode ('&', $newpairs);
    }

    /*********************
    *                    *
    *   implode_earl()   *
    *                    *
    *********************/
    private function implode_earl ($parts)
    {
    if (! isset ($parts['scheme']) || empty ($parts['scheme'])) $parts['scheme'] = $this->earl_scheme;
    $new_href = $parts['scheme'] . "://";
    // FIXME: use of user:pass@ is depreciated
    if (isset ($parts['user']) && isset ($parts['pass'])) $new_href .= $parts['user'] . ':' . $parts['pass'] . '@';
    $new_href .= $parts['host'];
    if (isset($parts['port'])) $new_href .= ':' . $parts['port'];
    
    return $new_href;
    }

    /*****************
    *                *
    *   get_host()   *
    *                *
    *****************/
    private function get_host ($url)
    {
    $parts = parse_url ($url);
    preg_match ("@([^/.]+)\.([^.]{2,6}(?:\.[^.]{2,3})?)$@", $parts['host'], $host);

    return array_shift ($host);
    }

    /*******************
    *                  *
    *   bugger_add()   *
    *                  *
    *******************/
    function bugger_add ($label, $x)
    {
        if ($this->bugger)
        {
        if (is_array ($x)) error_log ($label . ': ' . print_r ($x, 1) . "\n", 3, 'LOG');
        else error_log ($label . ': ' . $x . "\n", 3, 'LOG');
        }
    }

    /*************************
    *                        *
    *   mk_pretty_status()   *
    *                        *
    *************************/
    function mk_pretty_status ($code)
    {
    $codes = array (
        '1' => 'Unknown Error',
        '2' => 'Too Many Redirects',
        '3' => 'Could Not Resolve Host (DNS)',
        '4' => 'Connection Timed Out',
        '301' => 'Moved Permanently',
        '400' => 'Bad Request',
        '401' => 'Unauthorized',
        '403' => 'Forbidden',
        '404' => 'Not Found',
        '405' => 'Method Not Allowed',
        '408' => 'Request Timeout',
        '500' => 'Internal Server Error',
    );
    $out = str_pad ($code, 3, '0', STR_PAD_LEFT);
    if (isset ($codes[$code])) $out .= ' ' . $codes[$code];

    return $out;
    }

    /******************
    *                 *
    *   mk_report()   *
    *                 *
    ******************/
    function mk_report ()
    {
    date_default_timezone_set ('America/New_York');
    $pages = array ();

        foreach (array_keys ($this->site_map) as $page)
        {
            foreach (array_keys ($this->site_map[$page]) as $link)
            {
                if ($this->site_map[$page][$link] != 200)
                {
                $pages[] = $page;
                break;
                }
            }
        }

    print "<html>\n";
    print "<head>\n";
    print "<style> body { font-size: 12px; } h1, h2 { text-align: center; } </style>\n";
    print "</head>\n";
    print "<body>\n";

    print "<h1>Linkchecker Report for " . $this->url . "</h1>\n";
    print "<h2>generated on " . date ('Y-m-d') . "</h2>\n";

        if (count ($pages) > 0)
        {
            foreach ($pages as $page)
            {
            print "<hr>\n";
            print "<h3>Page <a href=\"" . $page . "\" target=\"_blank\">" . $page . "</a></h3>\n";
            print "<ul>\n";

                foreach (array_keys ($this->site_map[$page]) as $link)
                {
                if ($this->site_map[$page][$link] != 200)
                    print "<li><a href=\"" . $link . "\" target=\"_blank\">" . $link . "</a> " . 
                        $this->mk_pretty_status($this->site_map[$page][$link]) . "</li>\n";
                }

            print "</ul>\n";
            }
        }
        else
        {
        print "<h2>zero bad links :)</h2>\n";
        }

    print "<hr>\n";
    print "</body>\n";
    print "</html>\n";
    }
}


$lch = new LinkChecker ($argv[1], 3);
$res = $lch->crawl();

$lch->bugger_add('results', print_r ($res, 1));
$lch->bugger_add('', "\n");
$lch->bugger_add('redirects', print_r ($lch->redirects, 1));
$lch->bugger_add('', "\n");
$lch->bugger_add('seen_hashes', print_r ($lch->seen_hashes, 1));
$lch->bugger_add('', "\n");
$lch->bugger_add('page_aliases', print_r ($lch->page_aliases, 1));
$lch->bugger_add('', "\n");
$lch->bugger_add('site_map', print_r ($lch->site_map, 1));

$lch->mk_report();

exit;























?>
