<?php

/* 
 * 
 * https://github.com/sapinva/php-linkchecker
 * author: sapinva --at-- gmail --dot-- com
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * see http://www.gnu.org/licenses
 * 
 */

date_default_timezone_set (@date_default_timezone_get ());

/******************
*                 *
*   LinkChecker   *
*                 *
******************/
class LinkChecker
{
    public function __construct ($url = false)
    {
    $this->exe_start = microtime (true);
    $this->VERSION = '0.9.2';
    $this->site_url = false;
    $this->config = array ();
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
        'redirected_links' => 0,
        'links_examined' => 0,
        'run_time' => 0,
        'throttle_time' => 0,
        'cpu_time' => 0,
        'memory' => 0,
    );
    $this->page_countdown = false;
    $copts = getopt ('', array ('help', 'site-config'));

    if (isset ($copts['help'])) $this->mk_help();
    else if (isset ($copts['site-config'])) $this->mk_config_template();
    else if (strpos ($url, 'http') !== 0 && file_exists ($url)) $this->parse_config($url);
    else if (! empty ($url)) $this->quick_crawl($url);
    }

    /********************
    *                   *
    *   quick_crawl()   *
    *                   *
    ********************/
    private function quick_crawl ($url)
    {
    $config = array ('site' => $url);
    $this->init_settings($config);
    }

    /****************
    *               *
    *   mk_help()   *
    *               *
    ****************/
    private function mk_help ()
    {
    print "\n";
    print "usage:" . "\n";
    print "\n";
    print "  php linkchecker.php http://abc.com/ > report.html" . "\n";
    print "\n";
    print "  php linkchecker.php path/to/config" . "\n";
    print "\n";
    print "  php linkchecker.php [options...]" . "\n";
    print "\n";
    print "    --help" . "\n";
    print "      you're reading it" . "\n";
    print "\n";
    print "    --site-config" . "\n";
    print "      outputs an example site config with all available options" . "\n";
    print "\n";
    print "  config options:\n";
    $this->mk_config_template(true);
    exit;
    }

    /***************************
    *                          *
    *   mk_config_template()   *
    *                          *
    ***************************/
    private function mk_config_template ($is_help = false)
    {
    $opts = $this->get_config_template();
    print "\n";
    $pad = $is_help ? '    ' : '';
    $cmt = $is_help ? '' : '# ';

        foreach (array_keys ($opts) as $k)
        {
        print $pad . "# " . $opts[$k]['txt'] . "\n";
        if (isset ($opts[$k]['xmp'])) print $pad . $cmt . $k . " = " . $opts[$k]['xmp'] . "\n";
        else print $pad . $cmt . $k . " = " . $opts[$k]['def'] . "\n";
        print "\n";
        }

    exit;
    }

    /****************************
    *                           *
    *   get_config_template()   *
    *                           *
    ****************************/
    private function get_config_template ()
    {
    return array (
        'site' => array (
            'type' => false,
            'txt' => 'base url to check, the only required setting',
            'xmp' => "\"https://abc.com/\"",
        ),
        'log_file' => array (
            'def' => false,
            'type' => 'val',
            'txt' => 'log file path (default none)',
            'xmp' => "\"/path/to/linkchecker_log\"",
        ),
        'log_level' => array (
            'def' => 0,
            'type' => 'val',
            'txt' => 'if log_file, log level 0-9',
        ),
        'truncate_log_file' => array (
            'def' => false,
            'type' => 'bool',
            'txt' => 'if log_file, set to 1 to truncate log on each run',
            'xmp' => '0',
        ),
        'max_pages' => array (
            'def' => 100000,
            'type' => 'val',
            'txt' => 'stop after max_pages',
        ),
        'max_depth' => array (
            'def' => 16,
            'type' => 'val',
            'txt' => 'ignore dir levels deeper than max_depth',
        ),
        'request_timeout' => array (
            'def' => 30,
            'type' => 'val',
            'txt' => 'timeout for all requests',
        ),
        'site_throttle' => array (
            'def' => 3,
            'type' => 'val',
            'txt' => 'minimum pause between requests to site host',
        ),
        'ext_site_throttle' => array (
            'def' => 10,
            'type' => 'val',
            'txt' => 'minimum pause between requests to check external links, per host',
        ),
        'user_agent' => array (
            'def' => 'Mozilla/5.0 (X11; Linux x86_64) plc/23.0',
            'type' => 'val',
            'txt' => 'set the user agent',
            'xmp' => "\"MyBot was here 1.0\"",
        ),
        'strict_ssl_checking' => array (
            'def' => false,
            'type' => 'bool',
            'txt' => 'verify ssl host and peer',
            'xmp' => '0',
        ),
        'ignore' => array (
            'def' => array (),
            'type' => 'list',
            'txt' => 'urls to ignore, repeat for each',
            'xmp' => "\"https://twitter.com/\"",
        ),
        'ignore_regex' => array (
            'def' => array (),
            'type' => 'list',
            'txt' => 'url patterns to ignore (include the slashes), repeat for each',
            'xmp' => "\"/\?C=\w;O$/\"",
        ),
        'translate' => array (
            'def' => array (),
            'type' => 'kv_list',
            'txt' => 'space separated url pair to translate, repeat for each',
            'xmp' => "\"http://www.youtube.com/BobJohnson https://www.youtube.com/user/BobJohnson\"",
        ),
        'retry_with_get' => array (
            'def' => array ('403' => 1, '405' => 1),
            'type' => 'exists_list',
            'txt' => 'list status codes to retry with GET, space delimited',
            'xmp' => "\"405 403\"",
        ),
        'warn_redirect_to_other_host' => array (
            'def' => false,
            'type' => 'bool',
            'txt' => 'include warnings in report for redirects to different hosts (default none)',
            'xmp' => '0',
        ),
        'warn_all_redirect' => array (
            'def' => false,
            'type' => 'bool',
            'txt' => 'include warnings in report for all redirects (default none)',
            'xmp' => '0',
        ),
        'report_html' => array (
            'def' => false,
            'type' => 'val',
            'txt' => 'html report to file (instead of stdout)',
            'xmp' => "\"/path/to/report.html\"",
        ),
        'bad_links_report_json' => array (
            'def' => false,
            'type' => 'val',
            'txt' => 'create a bad links json report file (default none)',
            'xmp' => "\"/path/to/report.json\"",
        ),
    );
    }

    /**********************
    *                     *
    *   init_settings()   *
    *                     *
    **********************/
    private function init_settings (&$conf)
    {
    $opts = $this->get_config_template();

        foreach (array_keys ($opts) as $opt)
        {
        if ($opts[$opt]['type']) $this->config[$opt] = $opts[$opt]['def']; // set the defaults

            if (isset ($conf[$opt])) // handle config file vals
            {
                if ($opts[$opt]['type'] == 'bool') 
                    $this->config[$opt] = $this->config_bool($conf[$opt]);
                else if ($opts[$opt]['type'] == 'val') 
                    $this->config[$opt] = $conf[$opt];
                else if ($opts[$opt]['type'] == 'exists_list') 
                    $this->config_mk_exists_list($opt, $conf);
                else if ($opts[$opt]['type'] == 'list') 
                    $this->config_mk_list($opt, $conf);
                else if ($opts[$opt]['type'] == 'kv_list') 
                    $this->config_mk_kv_list($opt, $conf);
            }
        }

        if ($this->config['log_file'] && $this->config['log_level'] > 0)
        {
        $append = $this->config['truncate_log_file'] ? null : FILE_APPEND;
        if (! file_put_contents ($this->config['log_file'], "\n", $append)) 
            $this->fatal_error('log_file ' . $this->config['log_file'] . ' not writable!');
        else $this->config['log_file_h'] = fopen ($this->config['log_file'], 'a');
        }

        if ($this->config['bad_links_report_json'])
        {
        if (! file_put_contents ($this->config['bad_links_report_json'], "\n", FILE_APPEND)) 
            $this->fatal_error('bad_links_report_json ' . $this->config['bad_links_report_json'] . ' not writable!');
        }

        if ($this->config['report_html'])
        {
        if (! file_put_contents ($this->config['report_html'], "\n", FILE_APPEND)) 
            $this->fatal_error('report_html ' . $this->config['report_html'] . ' not writable!');
        }

    $this->page_countdown = $this->config['max_pages'];
    if ($this->config['site_throttle'] < 3) $this->config['site_throttle'] = 3;
    if ($this->config['ext_site_throttle'] < 10) $this->config['ext_site_throttle'] = 10;
    $this->set_site_url($conf['site']);

    $this->log_write('$this->config', $this->config, 3); // bugger
    }

    /**************
    *             *
    *   crawl()   *
    *             *
    **************/
    public function crawl ()
    {
    $this->_crawl($this->site_url);
    $this->mk_site_map();
    if ($this->config['bad_links_report_json']) $this->mk_bad_links_json();

    $this->log_write('$this->results', $this->results, 6);
    $this->log_write('$this->redirects', $this->redirects, 6);
    $this->log_write('$this->seen_hashes', $this->seen_hashes, 6);
    $this->log_write('$this->page_aliases', $this->page_aliases, 6);
    $this->log_write('$this->site_map', $this->site_map, 6);

    $this->mk_report();
    }

    /******************************
    *                             *
    *   get_redirected_status()   *
    *                             *
    ******************************/
    private function get_redirected_status ($urlobj)
    {
    $hinfo = $this->get_resource($urlobj, false, true);

    return $hinfo['status'];
    }

    /***************
    *              *
    *   _crawl()   *
    *              *
    ***************/
    private function _crawl ($urlobj)
    {
    if (! $urlobj->url) return;
    $this->log_write('_crawl', $urlobj->url, 4); // bugger
    $this->stats['links']++;

    $hinfo = $this->get_resource($urlobj);
    $redir_url = false;

    if ($hinfo['status'] != 200 && isset ($this->config['retry_with_get'][$hinfo['status']])) // some servers don't like HEAD
        $hinfo = $this->get_resource($urlobj, true, true);

        if ($hinfo['redirect']) 
        {
        $redir_url = new UrlBuilder ($hinfo['redirect'], $urlobj);
        $this->redirects[$urlobj->url] = $hinfo['redirect'];

        if ($this->config['warn_all_redirect'] ||
            ($this->config['warn_redirect_to_other_host'] && $hinfo['redirect_other_host']) ) // only if reported
                $hinfo['status'] = $this->get_redirected_status($urlobj);

        // if scheme switch, keep the scheme that server gives us on redirect, not the one link provided
        //if ($urlobj->host == $redir_url->host && $urlobj->scheme != $redir_url->scheme)
        //    $urlobj->set_scheme($redir_url->scheme);
        }

    $this->results[$urlobj->url] = array ('status' => $hinfo['status']);
    if ($hinfo['error']) $this->results[$urlobj->url]['error'] = $hinfo['error'];
    if ($this->page_pointer != 0 && ! isset ($this->site_map[$this->page_pointer][$urlobj->url]))
        $this->site_map_set($this->page_pointer, $urlobj->url, 'status', $this->results[$urlobj->url]['status']);

        if ($this->is_follow_page($urlobj, $hinfo, $redir_url))
        {
        $this->page_countdown--;
        $this->log_write(' get dom', $urlobj->url, 3); // bugger
        $this->site_map_set($urlobj->url);
        $this->page_pointer = $urlobj->url;
        $this->log_write('  page_pointer', $this->page_pointer, 4); // bugger

        $dom = new DOMDocument('1.0');
        $hinfo = $this->get_resource($urlobj, true, true);
        $this->stats['pages']++;
        @$dom->loadHTML($hinfo['content']);
        $sig = md5 ($hinfo['content']);
        if (! isset ($this->seen_hashes[$sig])) $this->seen_hashes[$sig] = $urlobj->url;
        else return $this->is_alias($sig, $urlobj->url);
        $anchors = $dom->getElementsByTagName('a');

            foreach ($anchors as $element)
            {
            $a_href = $element->getAttribute('href');
            $this->stats['links_examined']++;
            $this->log_write('  each $a_href', $a_href, 7); // bugger
            $ubh = new UrlBuilder ($a_href, $urlobj, array ('strip_fragment' => true,));
            $this->log_write('   UrlBuilder() $a_href', $ubh->url, 8); // bugger

            if (! $ubh->url) continue;
            if ($this->is_ignore($ubh->url) || $this->is_ignore_regex($ubh->url)) continue;
            if ($this->is_translate($ubh->url)) $ubh->url = $this->config['translate'][$ubh->url];

            $this->log_write('    add to site_map $ubh->url', $ubh->url, 7); // bugger

            // FIXME: maybe should be moved to after results, in case it is changed due to redirect
            $this->site_map_set($this->page_pointer, $ubh->url, 'href', $a_href);
            
            if (! isset ($this->results[$ubh->url])) $this->crawl_queue[] = $ubh;
            }
                                
            while (count ($this->crawl_queue) > 0)
            {
            $this->log_write('$this->crawl_queue', count ($this->crawl_queue), 8); // bugger
            $target = array_shift ($this->crawl_queue);
            if (! isset ($this->results[$target->url])) $this->_crawl($target);
            else $this->log_write('  already seen: ', $target->url, 9); // bugger
            }
        }

    return $urlobj;
    }

    /***********************
    *                      *
    *   is_follow_page()   *
    *                      *
    ***********************/
    private function is_follow_page (&$urlobj, &$hinfo, $redir = false)
    {
    $follow = false;

        if (
            $this->page_countdown != 0 && 
            $urlobj->same_host && 
            // $hinfo['status'] == 200 &&
            $hinfo['status'] < 400 &&
            $this->is_type_html($hinfo['content_type']) && 
            $urlobj->depth <= $this->config['max_depth']
            ) $follow = true;

        if ($redir)
        {
        if ($redir->host != $urlobj->host) $follow = false;
        if ($redir->scheme != $urlobj->scheme) $follow = false;
        if ($redir->port != $urlobj->port) $follow = false;
        }
        
        if ($this->jailed_subdir && strpos ($urlobj->path, $this->site_url->path) !== 0) $follow = false;

    return $follow;
    }

    /*********************
    *                    *
    *   site_map_set()   *
    *                    *
    *********************/
    private function site_map_set ($page_url, $link_url = false, $k = false, $v = false)
    {
        if (! isset ($this->site_map[$page_url])) 
            $this->site_map[$page_url] = array ();

        if ($link_url && ! isset ($this->site_map[$page_url][$link_url])) 
            $this->site_map[$page_url][$link_url] = array ();

        if ($link_url && $k) 
            $this->site_map[$page_url][$link_url][$k] = $v;
    }

    /*********************
    *                    *
    *   get_resource()   *
    *                    *
    *********************/
    private function get_resource ($urlobj, $follow = true, $use_get = false)
    {
    $this->log_write(' get_resource()', $urlobj->url . ($use_get ? ' (GET)' : ''), 5); // bugger
    $tt = $this->get_throttle($urlobj->host, $urlobj->same_host);
    $this->throttle($tt);
    $res = array (
        'status' => 1,
        'redirect' => false,
        'redirect_other_host' => false,
        'content_type' => false,
        'error' => false,
    );
    $ch = curl_init ($urlobj->url);
    if ($follow) curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, true);

    if ($use_get) curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
    else curl_setopt ($ch, CURLOPT_NOBODY, true);

    if (! $this->config['strict_ssl_checking']) curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, false);
    if (! $this->config['strict_ssl_checking']) curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
        
    $headers = array (
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*' . '/' . '*;q=0.8',
        'Accept-Encoding:	gzip, deflate',
        'Accept-Language:	en-US,en;q=0.5',
    );
    // 'User-Agent:	Mozilla/5.0 (X11; Linux x86_64; rv:23.0) Gecko/20100101 Firefox/23.0',
    if ($this->config['user_agent']) $headers[] = 'User-Agent:	' . $this->config['user_agent'];

    curl_setopt ($ch, CURLOPT_HTTPHEADER, $headers);

    curl_setopt ($ch, CURLOPT_TIMEOUT, $this->config['request_timeout']);
    $retdata = curl_exec ($ch);
    $err = curl_error ($ch);
    if ($err != '') $this->log_write('curl_error', $err, 7); // bugger
    $info = curl_getinfo ($ch);
    curl_close ($ch);
    $this->log_write('curl_getinfo', $info, 8); // bugger
    $res['status'] = $info['http_code'];
    $res['content_type'] = $info['content_type'];
    if ($err != '') $res['error'] = $err;
    if ($use_get) $res['content'] = $retdata;

        if ($err != '') 
        {
        if (preg_match ('/Maximum \(\n+\) redirects/i', $err)) $res['status'] = 902;
        else if (preg_match ('/resolve host/i', $err)) $res['status'] = 903;
        else if (preg_match ('/connection timed out/i', $err)) $res['status'] = 904;
        }        

        if ($info['redirect_count']) 
        {
        $res['redirect'] = $info['url'];

            if (! $this->is_same_host($info['url'], $urlobj))
            {
            $res['error'] = 'redirected to another host! -> ' . $info['url'] . ' ' . $res['error'];
            $res['redirect_other_host'] = true;
            }
        }
        
    return $res;
    }

    /*********************
    *                    *
    *   is_same_host()   *
    *                    *
    *********************/
    private function is_same_host ($url, $context) 
    {
    $ubh = new UrlBuilder ($url, $context);

    return $ubh->same_host ? true : false;
    }

    /*********************
    *                    *
    *   get_throttle()   *
    *                    *
    *********************/
    private function get_throttle ($host, $same_host) 
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
    private function throttle ($seconds) 
    {
    $this->log_write(' throttle', $seconds, 6); // bugger
    sleep ($seconds);
    $this->stats['throttle_time'] += $seconds;
    }

    /********************
    *                   *
    *   fatal_error()   *
    *                   *
    ********************/
    private function fatal_error ($x)
    {
    $this->log_write('fatal_error', $x, 1);
    fwrite (STDERR, $x . "\n");
    exit;
    }

    /*********************
    *                    *
    *   parse_config()   *
    *                    *
    *********************/
    private function parse_config ($cf)
    {
    if (! file_exists ($cf)) $this->fatal_error('no config file ' . $cf . ' found');
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

    /********************
    *                   *
    *   config_bool()   *
    *                   *
    ********************/
    private function config_bool ($v)
    {
    if (strtolower ($v) == 'true') return true;
    else if (strtolower ($v) == 'yes') return true;
    else if ($v == '1') return true;
    else if ($v == 1) return true;
    else return false;
    }

    /*********************
    *                    *
    *   set_site_url()   *
    *                    *
    *********************/
    private function set_site_url ($url)
    {
    $this->log_write(date ('Y-m-d H:i:s'), 'starting check of ' . $url, 1);

    $ubh = new UrlBuilder ($url);
    if (! $ubh->url) $this->fatal_error('url is required');
    $host = $ubh->host;
    $test = $this->get_resource($ubh); // test it first: rewrite if base url gets redirected

        if ($test['redirect'])
        {
        $this->log_write('base url ' . $url . ' was redirected, resetting', $test['redirect'], 1); // bugger
        $ubh = new UrlBuilder ($test['redirect']);
        if ($host != $ubh->host) $this->fatal_error('base url redirects to another host: ' . $ubh->host);
        }
        
    $this->jailed_subdir = strlen ($ubh->path) > 2 ? true : false;
    $this->site_url = $ubh;
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
            foreach (array_keys ($this->site_map[$k]) as $url)
            {
                if (isset ($this->site_map[$k][$url]) && ! isset ($this->site_map[$k][$url]['status']))
                    $this->site_map[$k][$url]['status'] = $this->results[$url]['status'];

                if (isset ($this->page_aliases[$url])) unset ($this->site_map[$k][$url]);
            }

            if (isset ($this->page_aliases[$k])) unset ($this->site_map[$k]);
        }
    }

    /**************************
    *                         *
    *   mk_bad_links_json()   *
    *                         *
    **************************/
    private function mk_bad_links_json ()
    {
    $fh = fopen ($this->config['bad_links_report_json'], 'w');

        if ($fh)
        {
        $tmp = array ();

            foreach (array_keys ($this->site_map) as $k)
            {
                foreach (array_keys ($this->site_map[$k]) as $url)
                {
                    if ($this->report_test($this->site_map[$k][$url]['status'], $url))
                    {
                    if (! isset ($tmp[$k])) $tmp[$k] = array ();
                    if (! isset ($tmp[$k][$url])) $tmp[$k][$url] = $this->site_map[$k][$url];
                    }
                }
            }

        if (defined ('JSON_PRETTY_PRINT')) fwrite ($fh, json_encode ($tmp, JSON_PRETTY_PRINT));
        else fwrite ($fh, json_encode ($tmp));
        fclose ($fh);
        }
    }

    /***********************
    *                      *
    *   config_mk_list()   *
    *                      *
    ***********************/
    private function config_mk_list ($opt, $conf)
    {
    if (! is_array ($conf[$opt])) $this->config[$opt] = array ($conf[$opt]);
    else $this->config[$opt] = $conf[$opt];
    }

    /******************************
    *                             *
    *   config_mk_exists_list()   *
    *                             *
    ******************************/
    private function config_mk_exists_list ($opt, $conf)
    {
    $items = preg_split ('/\s+/', trim ($conf[$opt]));
    $this->config[$opt] = array ();
    foreach ($items as $i) $this->config[$opt][$i] = 1;
    }

    /**************************
    *                         *
    *   config_mk_kv_list()   *
    *                         *
    **************************/
    private function config_mk_kv_list ($opt, $conf)
    {
    $tmp = array ();
    if (! is_array ($conf[$opt])) $tmp = array ($conf[$opt]);
    else $tmp = $conf[$opt];

        foreach ($tmp as $t)
        {
        $parts = preg_split ('/\s+/', $t);
        $this->config[$opt][$parts[0]] = $parts[1];
        }
    }

    /******************
    *                 *
    *   is_ignore()   *
    *                 *
    ******************/
    private function is_ignore ($url)
    {
    $ignore = in_array ($url, $this->config['ignore']) ? true : false;
    if ($ignore) $this->log_write('ignored per config', $url, 4); // bugger

    return $ignore;
    }

    /************************
    *                       *
    *   is_ignore_regex()   *
    *                       *
    ************************/
    private function is_ignore_regex ($url)
    {
    $ignore = false;

        foreach ($this->config['ignore_regex'] as $rx)
        {
            if (preg_match ($rx, $url))
            {   
            $ignore = true;
            $this->log_write('ignored pattern ' . $rx . ' per config', $url, 4); // bugger
            break;
            }
        }

    return $ignore;
    }

    /*********************
    *                    *
    *   is_translate()   *
    *                    *
    *********************/
    private function is_translate ($url)
    {
    $translate = in_array ($url, array_keys ($this->config['translate'])) ? true : false; // FIXME: regex maybe?
    if ($translate) $this->log_write('translated per config', $url, 4); // bugger

    return $translate;
    }

    /*****************
    *                *
    *   is_alias()   *
    *                *
    *****************/
    private function is_alias ($hash, $url)
    {
    $this->page_aliases[$url] = $hash;

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
    *   __destruct()   * 
    *                  *
    *******************/
    public function __destruct ()
    {
    $this->log_write(date ('Y-m-d H:i:s'), 'check complete', 1);
    if (isset ($this->config['log_file_h']) && $this->config['log_file_h']) fclose ($this->config['log_file_h']);
    }

    /******************
    *                 *
    *   log_write()   * 
    *                 *
    ******************/
    public function log_write ($label, $x, $level = 0)
    {
    $is_write = isset ($this->config['log_level']) && $level <= $this->config['log_level'] ? true : false;
    $fh = isset ($this->config['log_file_h']) ? $this->config['log_file_h'] : false;

        if ($fh && $is_write)
        {
        if (is_array ($x) || is_object ($x)) fwrite ($fh, $label . ': ' . print_r ($x, 1) . "\n");
        else fwrite ($fh, $label . ': ' . $x . "\n");
        }
    }

    /*************************
    *                        *
    *   mk_pretty_status()   *
    *                        *
    *************************/
    private function mk_pretty_status ($url, $code)
    {
    $out = '';

    if ($text = HttpCodes::get($code)) $out .= $code . ' ' . $text;
    if (isset ($this->results[$url]['error']))  $out .= ' - ' . $this->results[$url]['error'];

    return $out;
    }

    /*****************
    *                *
    *   mk_stats()   *
    *                *
    *****************/
    private function mk_stats ()
    {
        foreach (array_keys ($this->results) as $r)
        {
            if (! isset ($this->page_aliases[$r]))
            {
            if ($this->results[$r]['status'] > 399) $this->stats['bad_links']++;
            }
        }

    $this->stats['redirected_links'] = count ($this->redirects);
    $exe_end = microtime (true);
    $total_time = $exe_end - $this->exe_start;
    $this->stats['memory'] = memory_get_peak_usage ();
    $this->stats['run_time'] = $total_time;
    $this->stats['cpu_time'] = $total_time - $this->stats['throttle_time'];
    $this->log_write('$this->stats', $this->stats, 3); // bugger
    }

    /********************
    *                   *
    *   pretty_time()   *
    *                   *
    ********************/
    public function pretty_time ($seconds)
    {
    $pretty = '';

        if ($seconds > 3600)
        {
        $hrs = intval ($seconds / 3600);
        $pretty .= str_pad ($hrs, 2, '0', STR_PAD_LEFT) . ':';
        $seconds = $seconds - ($hrs * 3600);
        }
        else
        {
        $pretty .= '00:';
        }

        if ($seconds > 60)
        {
        $mins = intval ($seconds / 60);
        $pretty .= str_pad ($mins, 2, '0', STR_PAD_LEFT) . ':';
        $seconds = $seconds - ($mins * 60);
        }
        else
        {
        $pretty .= '00:';
        }

        if ($seconds > 0)
        {
        $secint = intval ($seconds);
        $rem = str_replace ('0.', '', number_format ($seconds - $secint, 3));
        $pretty .= str_pad (intval ($seconds), 2, '0', STR_PAD_LEFT) . '.' . $rem;
        }
        else
        {
        $pretty .= '00';
        }

    return $pretty;
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
    *   report_test()   *
    *                   *
    ********************/
    private function report_test ($code, $url)
    {
    $report = false;

        if ($code > 399) $report = 'error';
        else if ($this->config['warn_redirect_to_other_host'] && 
                 isset ($this->results[$url]['error']) &&
                 $this->str_starts ($this->results[$url]['error'], 'redirected to another host!'))
                    $report = 'warn1';
        else if ($this->config['warn_all_redirect'] && $code > 299 && $code < 400) $report = 'warn2';

    return $report;
    }

    /******************
    *                 *
    *   mk_report()   *
    *                 *
    ******************/
    public function mk_report ()
    {
    $pages = array ();
    $fh = false;
    if ($this->config['report_html']) $fh = fopen ($this->config['report_html'], 'w');
    else $fh = STDOUT;

        foreach (array_keys ($this->site_map) as $page)
        {
            foreach (array_keys ($this->site_map[$page]) as $url)
            {
                if ($this->report_test($this->site_map[$page][$url]['status'], $url))
                {
                $pages[] = $page;
                break;
                }
            }
        }

    fwrite ($fh, "<html>\n");
    fwrite ($fh, "<head>\n");
    fwrite ($fh, "<style> body { font-size: 12px; } h1, h2 { text-align: center; } </style>\n");
    fwrite ($fh, "</head>\n");
    fwrite ($fh, "<body>\n");

    fwrite ($fh, "<h1>Linkchecker Report for " . $this->site_url->url . "</h1>\n");
    fwrite ($fh, "<h2>generated on " . date ('Y-m-d') . "</h2>\n");

        if (count ($pages) > 0)
        {
            foreach ($pages as $page)
            {
            fwrite ($fh, "<hr>\n");
            fwrite ($fh, "<h3>Page <a href=\"" . $page . "\" target=\"_blank\">" . $page . "</a></h3>\n");
            fwrite ($fh, "<ul>\n");

                foreach (array_keys ($this->site_map[$page]) as $url)
                {
                if ($this->report_test($this->site_map[$page][$url]['status'], $url))
                    fwrite ($fh, "<li><a href=\"" . $url . "\" target=\"_blank\">" . $url . "</a> " . 
                        $this->mk_pretty_status($url, $this->site_map[$page][$url]['status']) . "</li>\n");
                }

            fwrite ($fh, "</ul>\n");
            }
        }
        else
        {
        fwrite ($fh, "<h2>zero bad links :)</h2>\n");
        }

    fwrite ($fh, "<hr>\n");

    $this->mk_stats();
    fwrite ($fh, 'pages: ' . number_format ($this->stats['pages']) . "<br>\n");
    fwrite ($fh, 'unique links: ' . number_format ($this->stats['links']) . "<br>\n");
    fwrite ($fh, 'examined links: ' . number_format ($this->stats['links_examined']) . "<br>\n");
    fwrite ($fh, 'bad links: ' . number_format ($this->stats['bad_links']) . "<br>\n");
    fwrite ($fh, 'redirected links: ' . number_format ($this->stats['redirected_links']) . "<br>\n");
    fwrite ($fh, 'memory: ' . number_format ($this->stats['memory']) . " bytes <br>\n");
    fwrite ($fh, 'run time: ' . $this->pretty_time($this->stats['run_time']) . " <br>\n");
    fwrite ($fh, 'execution time: ' . $this->pretty_time($this->stats['cpu_time']) . " <br>\n");
    fwrite ($fh, 'sleep time: ' . $this->pretty_time($this->stats['throttle_time']) . " <br>\n");
    fwrite ($fh, 'completed on: ' . date ('Y-m-d H:i:s') . "<br><br>\n");
    fwrite ($fh, "<a href=\"https://github.com/sapinva/php-linkchecker\" target=\"_blank\" rel=\"noreferrer\">" . 
            'php linkchecker v' . $this->VERSION . "</a>\n");
    fwrite ($fh, "</body>\n");
    fwrite ($fh, "</html>\n");
    if ($this->config['report_html']) fclose ($fh);
    }
}

/*****************
*                *
*   UrlBuilder   *
*                *
*****************/
class UrlBuilder
{
    public function __construct ($href, $context = false, $opts = false)
    {
    $this->href = $href;
    $this->context = $context;
    $this->scheme = false;
    $this->host = false;
    $this->port = false;
    $this->path = false;
    $this->dir_path = false;
    $this->query = false;
    $this->fragment = false;
    $this->depth = false;
    $this->same_host = true;
    $this->url = false;

    $this->set_opts($opts);
    if ($this->context && ! is_object ($this->context)) $this->context = new UrlBuilder ($this->context);
    $this->parse_href();
    }
    
    /*******************
    *                  *
    *   set_scheme()   *
    *                  *
    *******************/
    public function set_scheme ($scheme)
    {
    $this->scheme = $scheme;
    $this->url = $this->implode_url();
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
    else if ($this->str_starts(strtolower ($this->href), 'mailto:')) return;
    else if ($this->str_starts(strtolower ($this->href), 'javascript:')) return;
    else if ($this->str_starts(strtolower ($this->href), 'tel:')) return;
    else if ($this->str_starts($this->href, '#')) return;

    $parts = parse_url (trim ($this->href));
    $this->scheme = ! empty ($parts['scheme']) ? $parts['scheme'] : $this->find_scheme();
    $this->host = ! empty ($parts['host']) ? strtolower ($parts['host']) : false;
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

            if ($this->context)
            {
            $pparts = explode ('/', $this->context->path);
            array_pop ($pparts);
            $this->context->dir_path = implode ('/', $pparts) . '/';
            }

            if ($this->str_starts($tmp_path, './')) // "./path" relative
            {
            $this->path = $this->context->path . preg_replace ('#^\.\/#', '', $tmp_path);
            }
            else if ($this->str_starts($this->href, '?')) // "?some=query" relative
            {
            $this->path = $this->context->path . $tmp_path;
            }
            else if ($this->str_starts($tmp_path, '../')) // "../path" relative
            {
            $this->path = $this->cancel_out_doubles($tmp_path, $this->context);
            if (! $this->path) return;
            }
            else if ($this->str_starts($tmp_path, '/'))
            {
            $this->path = $tmp_path;
            }
            else // "some/path" relative
            {
            $this->path = $this->context->dir_path . $tmp_path;
            }

        $this->depth = substr_count ($this->path, '/');
        $this->url = $this->implode_url();
        }
        else
        {
        $this->depth = substr_count ($this->href, '/') - 2;
        $this->path = isset ($parts['path']) ? $parts['path'] : false;

        $this->url = $this->implode_url();
        }
    }

    /***************************
    *                          *
    *   cancel_out_doubles()   *
    *                          *
    ***************************/
    private function cancel_out_doubles ($tmp_path, &$context)
    {
    $path = false;
    $ups = substr_count ($tmp_path, '../');
    $d_parts = array ();
    $d_tmp = explode ('/', $context->dir_path);
    foreach ($d_tmp as $d) if (! empty ($d)) $d_parts[] = $d;
    $d_parts = array_reverse ($d_parts);

        if ($ups < $context->depth)
        {
        $tmp_context_path = $context->dir_path;

            for ($i = 0; $i < $ups; $i++)
            {
            $tmp_path = str_replace ('../', '', $tmp_path);
            $tmp_context_path = str_replace ($d_parts[$i] . '/', '', $tmp_context_path);
            }

        $path = $tmp_context_path . $tmp_path;
        }

    return $path;
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

    /********************
    *                   *
    *   implode_url()   *
    *                   *
    ********************/
    private function implode_url ()
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

/****************
*               *
*   HttpCodes   *
*               *
****************/
class HttpCodes
{
    public static function get ($c)
    {
    $codes = array (
        // 1xx Informational
        '100' => 'Continue',
        '101' => 'Switching Protocols',
        '102' => 'Processing',
        // 2xx Success
        '200' => 'Found OK',
        '201' => 'Created',
        '202' => 'Accepted',
        '203' => 'Non-authoritative Information',
        '204' => 'No Content',
        '205' => 'Reset Content',
        '206' => 'Partial Content',
        '207' => 'Multi-Status',
        '208' => 'Already Reported',
        '226' => 'IM Used',
        // 3xx Redirection
        '300' => 'Multiple Choices',
        '301' => 'Moved Permanently',
        '302' => 'Found',
        '303' => 'See Other',
        '304' => 'Not Modified',
        '305' => 'Use Proxy',
        '307' => 'Temporary Redirect',
        '308' => 'Permanent Redirect',
        // 4xx Client Error
        '400' => 'Bad Request',
        '401' => 'Unauthorized',
        '402' => 'Payment Required',
        '403' => 'Forbidden',
        '404' => 'Not Found',
        '405' => 'Method Not Allowed',
        '406' => 'Not Acceptable',
        '407' => 'Proxy Authentication Required',
        '408' => 'Request Timeout',
        '409' => 'Conflict',
        '410' => 'Gone',
        '411' => 'Length Required',
        '412' => 'Precondition Failed',
        '413' => 'Payload Too Large',
        '414' => 'Request-URI Too Long',
        '415' => 'Unsupported Media Type',
        '416' => 'Requested Range Not Satisfiable',
        '417' => 'Expectation Failed',
        '418' => 'I\'m a teapot',
        '421' => 'Misdirected Request',
        '422' => 'Unprocessable Entity',
        '423' => 'Locked',
        '424' => 'Failed Dependency',
        '426' => 'Upgrade Required',
        '428' => 'Precondition Required',
        '429' => 'Too Many Requests',
        '431' => 'Request Header Fields Too Large',
        '444' => 'Connection Closed Without Response',
        '451' => 'Unavailable For Legal Reasons',
        '499' => 'Client Closed Request',
        // 5xx Server Error
        '500' => 'Internal Server Error',
        '501' => 'Not Implemented',
        '502' => 'Bad Gateway',
        '503' => 'Service Unavailable',
        '504' => 'Gateway Timeout',
        '505' => 'HTTP Version Not Supported',
        '506' => 'Variant Also Negotiates',
        '507' => 'Insufficient Storage',
        '508' => 'Loop Detected',
        '510' => 'Not Extended',
        '511' => 'Network Authentication Required',
        '599' => 'Network Connect Timeout Error',
        // 9xx curl, other errors
        '901' => 'Unknown Error',
        '902' => 'Too Many Redirects',
        '903' => 'Could Not Resolve Host (DNS)',
        '904' => 'Connection Timed Out',
    );

    return isset ($codes[$c]) ? $codes[$c] : $codes['901'];
    }
}

$lch = new LinkChecker ($argv[1]);
$lch->crawl();

exit;


?>
