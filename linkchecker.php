<?php

class LinkChecker
{
    public function __construct ($url = false)
    {
    $this->exe_start = microtime (true);

    $this->bugger = false;
    $this->bugger_stop = -1; // stop at $this->bugger_stop pages for testing, -1 = no stop

    $this->VERSION = '0.9a';
    $this->site_url = false;
    $this->site_url_object = false;
    $this->site_scheme = false;
    $this->site_host = false;
    $this->same_host = false;
    $this->config = array (
        'curl_timeout' => 30,
        'site_throttle' => 3,
        'ext_site_throttle' => 10,
        'ignore_urls' => array (),
        'translate_urls' => array (),
    );
    $this->crawl_queue = array ();
    $this->page_pointer = false;
    $this->results = array ();
    $this->site_map = array ();
    $this->redirects = array ();
    $this->seen_hashes = array ();
    $this->page_aliases = array ();
    $this->host_cache = array ();
    $this->stats = array (
        'pages' => 0,
        'links' => 0,
        'bad_links' => 0,
        'links_examined' => 0,
        'run_time' => 0,
        'throttle_time' => 0,
        'cpu_time' => 0,
        'memory' => 0,
    );

    if (strpos ($url, 'http') !== 0 && file_exists ($url)) $this->parse_config($url);
    else if (! empty ($url)) $this->set_earl($url);
    }

    /**************
    *             *
    *   crawl()   *
    *             *
    **************/
    public function crawl ()
    {
    $this->_crawl($this->site_url_object);
    $this->mk_site_map();
    }

    /***************
    *              *
    *   _crawl()   *
    *              *
    ***************/
    private function _crawl ($urlobj)
    {
    $this->bugger_add('_crawl()...', ''); // bugger
    $url = $urlobj->url;
    $this->bugger_add('_crawl', $url); // bugger

    if (empty ($url)) return;

    $hinfo = $this->get_resource($urlobj);
    $this->stats['links']++;
    if ($hinfo['status'] == 500 || $hinfo['status'] == 405)
        $hinfo = $this->get_resource($urlobj, true); // FIXME: wtf? some servers don't like HEAD?
    if ($hinfo['redirect']) $this->redirects[$url] = $hinfo['redirect'];

    $this->results[$url] = array (
        'status' => $hinfo['status'],
    );
    if ($this->page_pointer != 0 && ! isset ($this->site_map[$this->page_pointer][$url])) 
        $this->site_map[$this->page_pointer][$url] = array (
            'status' => $this->results[$url]['status'],
        );

        if ($this->is_type_html($hinfo['content_type']) && $urlobj->same_host && $hinfo['status'] == 200) 
        {
        if ($this->bugger_stop == 0) $this->bugger_add('bugger_stop', '.'); // bugger
        if ($this->bugger_stop == 0) return; // bugger // stop N pages, for testing only
        $this->bugger_stop--; // bugger
        $this->bugger_add(' get dom', $url); // bugger

        if (! isset ($this->site_map[$url])) $this->site_map[$url] = array ();
        $this->page_pointer = $url;
        $this->bugger_add('  page_pointer', $this->page_pointer); // bugger

        $dom = new DOMDocument('1.0');


        $hinfo = $this->get_resource($urlobj, true);
        $this->stats['pages']++;
        @$dom->loadHTML($hinfo['content']); // from string
        $sig = md5 ($hinfo['content']);


        if (! isset ($this->seen_hashes[$sig])) $this->seen_hashes[$sig] = $url;
        else return $this->is_alias($sig, $url);

        $anchors = $dom->getElementsByTagName('a');

            foreach ($anchors as $element)
            {
            $a_href = $element->getAttribute('href');
            $this->stats['links_examined']++;
            $this->bugger_add('  each $a_href', $a_href); // bugger

            $ubh = new UrlBuilder ($a_href, $urlobj, array ('strip_fragment' => true,));
            $this->bugger_add('   UrlBuilder() $a_href', $ubh->url); // bugger

            if (! $ubh->url) continue;
            if ($this->is_ignore($ubh->url)) continue;

            if ($this->is_translate($ubh->url)) $ubh->url = $this->config['translate_urls'][$ubh->url];

            $this->bugger_add('    add to site_map $ubh->url', $ubh->url); // bugger
            $this->site_map[$this->page_pointer][$ubh->url] = false;
            
            if (! isset ($this->results[$ubh->url])) $this->crawl_queue[] = $ubh;
            }
                                
            while (count ($this->crawl_queue) > 0)
            {
            $this->bugger_add('$this->crawl_queue', count ($this->crawl_queue)); // bugger
            $target = array_shift ($this->crawl_queue);
            if (! isset ($this->results[$target->url])) $this->_crawl($target);
            else $this->bugger_add('  no crawl!', $target->url); // bugger
            }
        }

    return $urlobj;
    }

    /*********************
    *                    *
    *   get_resource()   *
    *                    *
    *********************/
    private function get_resource ($urlobj, $use_get = false)
    {
    $earl = $urlobj->url;
    $this->bugger_add(' get_resource()', $earl); // bugger

    $tt = $this->get_throttle($urlobj->host, $urlobj->same_host);
    $this->throttle($tt);
    $res = array (
        'status' => 1,
        'redirect' => false,
    );

    $ch = curl_init ($earl);
    curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, true);

    if ($use_get) curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
    else curl_setopt ($ch, CURLOPT_NOBODY, true);

    curl_setopt ($ch, CURLOPT_TIMEOUT, $this->config['curl_timeout']);
    $retdata = curl_exec ($ch);
    $err = curl_error ($ch);
    // if ($err != '') $this->bugger_add('curl_error', $err); // bugger
    $info = curl_getinfo ($ch);
    curl_close ($ch);
    // $this->bugger_add('curl_getinfo', $info); // bugger
    $res['status'] = $info['http_code'];
    $res['content_type'] = $info['content_type'];
    $res['message'] = $info['http_code'] == 200 ? 'ok' : 'BAD LINK';
    if ($use_get) $res['content'] = $retdata;

        if ($err != '') 
        {
        if (preg_match ('/Maximum \(\n+\) redirects/i', $err)) $res['status'] = 2;
        else if (preg_match ('/resolve host/i', $err)) $res['status'] = 3;
        else if (preg_match ('/connection timed out/i', $err)) $res['status'] = 4;
        }        

    if ($info['redirect_count']) $res['redirect'] = $info['url'];

    return $res;
    }

    /*********************
    *                    *
    *   get_throttle()   *
    *                    *
    *********************/
    function get_throttle ($host, $same_host) 
    {
    $pending_pause = $same_host ? $this->config['site_throttle'] : $this->config['ext_site_throttle'];
    $last_request = isset ($this->host_cache[$host]) ? $this->host_cache[$host] : 0;
    $this->host_cache[$host] = time ();
    $elapsed = time () - $last_request;

    if ($last_request == 0) $pending_pause = 0;
    else if ($elapsed > $pending_pause) $pending_pause = 0;
    else if ($elapsed < $pending_pause) $pending_pause = $pending_pause - $elapsed;

    return $pending_pause;
    }

    /*****************
    *                *
    *   throttle()   *
    *                *
    *****************/
    function throttle ($seconds) 
    {
    $this->bugger_add(' throttle', $seconds); // bugger
    sleep ($seconds);
    $this->stats['throttle_time'] += $seconds;
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

    $this->init_settings($conf);
    }

    /**********************
    *                     *
    *   init_settings()   *
    *                     *
    **********************/
    private function init_settings (&$conf)
    {
    $this->set_earl($conf['site']);
    if (isset ($conf['site_throttle'])) $this->config['site_throttle'] = $conf['site_throttle'];
    if (isset ($conf['ext_site_throttle'])) $this->config['ext_site_throttle'] = $conf['ext_site_throttle'];

        if (isset ($conf['ignore']))
        {
        if (! is_array ($conf['ignore'])) $this->config['ignore_urls'] = array ($conf['ignore']);
        else $this->config['ignore_urls'] = $conf['ignore'];
        }
        
        if (isset ($conf['translate']))
        {
        $tmp = array ();
        if (! is_array ($conf['translate'])) $tmp = array ($conf['translate']);
        else $tmp = $conf['translate'];

            foreach ($tmp as $t)
            {
            $parts = preg_split ('/\s+/', $t);
            $this->config['translate_urls'][$parts[0]] = $parts[1];
            }
        }        
    }

    /*****************
    *                *
    *   set_earl()   *
    *                *
    *****************/
    private function set_earl ($url)
    {
    $ubh = new UrlBuilder ($url);
    if (! $ubh->url) die ('url is required');
    $this->site_url_object = $ubh;
    $this->site_scheme = $this->site_url_object->scheme;
    $this->site_url = $ubh->url;
    $this->site_host = $ubh->host;
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
                    $this->site_map[$k][$earl] = array ('status' => $this->results[$earl]['status'],);

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
    $ignore = in_array ($earl, $this->config['ignore_urls']) ? true : false; // FIXME: should take regex
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
    $translate = in_array ($earl, array_keys ($this->config['translate_urls'])) ? true : false; // FIXME: regex maybe?
    if ($translate) $this->bugger_add('*** is_translate ***', $earl); // bugger

    return $translate;
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
    *   is_type_html()   *
    *                    *
    *********************/
    private function is_type_html ($ctype)
    {
    return preg_match ('/text\/html/', $ctype) ? true : false;
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
        if (is_array ($x) || is_object ($x)) error_log ($label . ': ' . print_r ($x, 1) . "\n", 3, 'LOG');
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

    /*****************
    *                *
    *   mk_stats()   *
    *                *
    *****************/
    function mk_stats ()
    {
        foreach (array_keys ($this->results) as $r)
        {
        if (! isset ($this->page_aliases[$r]) && $this->results[$r]['status'] != 200)
            $this->stats['bad_links']++;
        }

    $exe_end = microtime (true);
    $total_time = $exe_end - $this->exe_start;
    $this->stats['memory'] = memory_get_peak_usage ();
    $this->stats['run_time'] = $total_time;
    $this->stats['cpu_time'] = $total_time - $this->stats['throttle_time'];
    $this->bugger_add('$this->stats', $this->stats); // bugger
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
                if ($this->site_map[$page][$link]['status'] != 200)
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

    print "<h1>Linkchecker Report for " . $this->site_url . "</h1>\n";
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
                if ($this->site_map[$page][$link]['status'] != 200)
                    print "<li><a href=\"" . $link . "\" target=\"_blank\">" . $link . "</a> " . 
                        $this->mk_pretty_status($this->site_map[$page][$link]['status']) . "</li>\n";
                }

            print "</ul>\n";
            }
        }
        else
        {
        print "<h2>zero bad links :)</h2>\n";
        }

    print "<hr>\n";

    $this->mk_stats();
    print 'pages: ' . number_format ($this->stats['pages']) . "<br>\n";
    print 'unique links: ' . number_format ($this->stats['links']) . "<br>\n";
    print 'examined links: ' . number_format ($this->stats['links_examined']) . "<br>\n";
    print 'bad links: ' . number_format ($this->stats['bad_links']) . "<br>\n";
    print 'memory: ' . number_format ($this->stats['memory']) . " bytes <br>\n";
    print 'run time: ' . number_format ($this->stats['run_time'], 3) . " seconds <br>\n";
    print 'wait time: ' . number_format ($this->stats['throttle_time'], 3) . " seconds <br>\n";
    print 'cpu time: ' . number_format ($this->stats['cpu_time'], 3) . " seconds <br>\n";
    print 'completed on: ' . date ('Y-m-d H:i:s') . "<br><br>\n";
    print "<a href=\"https://github.com/sapinva/php-linkchecker\"" . 'php linkchecker v ' . $this->VERSION . "</a>\n";
    print "</body>\n";
    print "</html>\n";
    }
}

class UrlBuilder
{
    function __construct ($href, $context = false, $opts = false)
    {
    $this->href = $href;
    $this->context = $context;
    $this->scheme = false;
    $this->host = false;
    $this->port = false;
    $this->path = false;
    $this->query = false;
    $this->fragment = false;
    $this->depth = false;
    $this->same_host = true;
    $this->url = false;

    $this->set_opts($opts);
    if ($this->context && ! is_object ($this->context)) $this->context = new UrlBuilder ($this->context);
    $this->parse_href();
    }
    
    /*****************
    *                *
    *   set_opts()   *
    *                *
    *****************/
    private function set_opts ($opts)
    {
    $this->strip_fragment = isset ($opts['strip_fragment']) ? $opts['strip_fragment'] : false;
    $this->strip_query = isset ($opts['strip_query']) ? $opts['strip_query'] : false;
    }
    
    /*******************
    *                  *
    *   parse_href()   *
    *                  *
    *******************/
    private function parse_href ()
    {
    if (empty ($this->href)) return;
    else if ($this->str_starts($this->href, 'mailto:')) return;
    else if ($this->str_starts($this->href, 'javascript:')) return;
    else if ($this->str_starts($this->href, 'tel:')) return;
    else if ($this->str_starts($this->href, '#')) return;

    $parts = parse_url (trim ($this->href));
    $this->scheme = ! empty ($parts['scheme']) ? $parts['scheme'] : $this->find_scheme();
    $this->host = ! empty ($parts['host']) ? $parts['host'] : false;
    $this->port = ! empty ($parts['port']) ? $parts['port'] : false;
    $this->query = ! empty ($parts['query']) ? $this->sort_params($parts['query']) : false;
    $this->fragment = ! empty ($parts['fragment']) ? $parts['fragment'] : false;
    $this->url = false;
    $tmp_path = ! empty ($parts['path']) ? $parts['path'] : false;

        if ($this->context)
        {
        if (! $this->host) $this->host = $this->context->host;
        if (! $this->port) $this->port = $this->context->port;
        if ($this->host != $this->context->host) $this->same_host = false;
        }
        else
        {
        $this->path = ! empty ($parts['path']) ? $parts['path'] : '';
        }

        if ($this->context && $this->scheme != 'http' && $this->scheme != 'https')
        {
        $this->scheme = $this->context->scheme;

            if (! preg_match ('/\/$/', $this->context->path))
            {
            $pparts = explode ('/', $this->context->path);
            array_pop ($pparts);
            $this->context->path = implode ('/', $pparts);
            }

            if ($this->str_starts($tmp_path, './')) // "./path" relative
            {
            $this->path = $this->context->path . preg_replace ('#^\.\/#', '', $tmp_path);
            }
            else if ($this->str_starts($tmp_path, '../')) // "../path" relative
            {
            $ups = substr_count ($tmp_path, '../');
            if ($ups >= $this->context->depth) return;
            $this->path = $this->context->path . $tmp_path;
            }
            else if ($this->str_starts($tmp_path, '/'))
            {
            $this->path = $tmp_path;
            }
            else // "some/path" relative
            {
            $this->path = $this->context->path . $tmp_path;
            }

        $this->depth = substr_count ($this->path, '/');
        $this->url = $this->implode_earl();
        }
        else
        {
        $this->depth = substr_count ($this->href, '/') - 2;
        $this->path = isset ($parts['path']) ? $parts['path'] : false;

        $this->url = $this->implode_earl();
        }
    }

    /********************
    *                   *
    *   find_scheme()   *
    *                   *
    ********************/
    private function find_scheme ()
    {
        if ($this->str_starts($this->href, "//"))
        {
        if (isset ($this->context->scheme)) 
            return $this->context->scheme;
        else
            return 'http';
        }
    }

    /*********************
    *                    *
    *   implode_earl()   *
    *                    *
    *********************/
    private function implode_earl ()
    {
    $url = $this->scheme . "://" . $this->host;
    if ($this->port) $url .= ':' . $this->port;
    $url .= '/';
    if ($this->path) $url .= ($this->str_starts($this->path, '/') ? substr ($this->path, 1) : $this->path);
    if ($this->query && ! $this->strip_query) $url .= '?' . $this->query;
    if ($this->fragment && ! $this->strip_fragment) $url .= '#' . $this->fragment;

    return $url;
    }

    /*******************
    *                  *
    *   str_starts()   *
    *                  *
    *******************/
    private function str_starts ($str, $starts)
    {
    return strpos ($str, $starts) === 0 ? true : false;
    }

    /********************
    *                   *
    *   sort_params()   *
    *                   *
    ********************/
    private function sort_params ($qs)
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
}


$lch = new LinkChecker ($argv[1]);
$lch->crawl();

$lch->bugger_add('results', print_r ($lch->results, 1));
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
