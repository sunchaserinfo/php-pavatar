<?php
/**
 * Class Pavatar
 * @license http://creativecommons.org/publicdomain/zero/1.0/legalcode
 */

class Pavatar
{
    const METHOD_NOTFOUND   = 0;
    const METHOD_HEADER     = 1;
    const METHOD_HTML_LINK  = 2;
    const METHOD_HTML_META  = 3;
    const METHOD_DIRECT     = 4;
    const METHOD_FAILURE    = 5;

    private static $method;

    public static function Discover($url)
    {
        if (strpos($url, '://') === false) {
            $url = 'http://'. $url;
        }

        $avatar = self::getPavatarFrom($url);

        if (self::$method !== self::METHOD_DIRECT) {
            $avatar = self::getDirectUrl($avatar, $exists, false);

            if ($exists === false) {
                return false;
            }
        }

        return $avatar;
    }

    public static function GetLastMethod()
    {
        return self::$method;
    }

    private static function getPavatarFrom($url)
    {
        global $_pavatar_mime_type;
        $_url = '';

        if ($url) {
            $testurl = $url;
            do {
                $headers = self::getHeaders($testurl);
                $_url = @$headers['x-pavatar'];
                self::$method = self::METHOD_HEADER;
                $testurl = @$headers['location'];
            } while (!$_url && $testurl);
        }

        if (!$_url && $url) {
            if (class_exists('DOMDocument')) {
                $dom = new DOMDocument();
                if (@$dom->loadHTML(self::getUrlContents($url))) {
                    $links = $dom->getElementsByTagName('link');
                    $metas = $dom->getElementsByTagName('meta');

                    for ($i = 0; $i < $links->length; $i++) {
                        $rels = strtolower($links->item($i)->getAttribute('rel'));
                        $relsarr = preg_split('/\s+/', $rels);

                        if (array_search('pavatar', $relsarr) !== false) {
                            $_url = html_entity_decode($links->item($i)->getAttribute('href'));
                            $_pavatar_mime_type = $links->item($i)->getAttribute('type');
                            self::$method = self::METHOD_HTML_LINK;
                        }
                    }

                    for ($i = 0; $i < $metas->length; $i++) {
                        $httpequiv = strtolower($metas->item($i)->getAttribute('http-equiv'));

                        if ($httpequiv == 'x-pavatar') {
                            $_url = html_entity_decode($metas->item($i)->getAttribute('content'));
                            self::$method = self::METHOD_HTML_META;
                        }

                        if ($httpequiv == 'x-pavatar-type')
                            $_pavatar_mime_type = $metas->item($i)->getAttribute('content');
                    }

                    if ($_url && !$_pavatar_mime_type)
                        $_pavatar_mime_type = 'image/png';
                }
            }
        }

        if (!$_url && $url) {
            $_url = self::getDirectUrl($url, $exists);
            self::$method = self::METHOD_DIRECT;

            if (!$exists) {
                $urlp = parse_url($url);
                if (isset($urlp['port']))
                    $port = ':' . $urlp['port'];
                else
                    $port = '';

                $url = $urlp['scheme'] . '://' . $urlp['host'] . $port;
                $_url = self::getDirectUrl($url, $exists);
            }

            if (!$exists) {
                $_url = false;
                self::$method = self::METHOD_NOTFOUND;
            }
        }

        return $_url;
    }

    private static function getHeaders($url)
    {
        $ret = NULL;

        $headers = @get_headers($url);
        $headerc = count((array) $headers);

        for ($i = 0; $i < $headerc; $i++) {
            $ci = strpos($headers[$i], ':');
            $headn = strtolower(substr($headers[$i], 0, $ci));
            $headv = ltrim(substr($headers[$i], $ci + 1));
            $ret[$headn] = $headv;
        }

        return $ret;
    }

    private static function getUrlContents($url)
    {
        global $_pavatar_mime_type;

        $in_headers = true;
        $ret = '';

        do {
            $headers = self::getHeaders($url);
            if (@$headers['location']) {
                $url = $headers['location'];
            }
        } while (@$headers['location']);

        $urlp = parse_url($url);
        if (empty($urlp['port']))
            $urlp['port'] = 80;

        if (!@$urlp['path']) {
            $urlp['path'] = '/';
        }

        @$fh = fsockopen($urlp['host'], $urlp['port']);
        if ($fh) {
            fwrite($fh, 'GET ' . $urlp['path'] . ' HTTP/1.1' . "\r\n");
            fwrite($fh, 'Host: ' . $urlp['host'] . "\r\n");
            fwrite($fh, 'User-Agent: PHP-Pavatar' . "\r\n");
            fwrite($fh, "Connection: close\r\n");
            fwrite($fh, "\r\n");

            while (!feof($fh)) {
                if ($in_headers || !trim($ret))
                    $ret = '';

                $ret .= fgets($fh);

                if (!trim($ret))
                    $in_headers = false;
            }
        } else {
            $_pavatar_mime_type = 'text/plain';
        }

        @fclose($fh);
        return $ret;
    }

    private static function getDirectUrl($url, & $exists, $add_suffix = true)
    {
        $_url = $url;
        if ($add_suffix) {
            $sep = substr($url, -1, 1) == '/' ? '' : '/';
            $_url = $url . $sep . 'pavatar.png';
        }

        $headers = @get_headers($_url);

        $exists = $headers && (preg_match('/[45]\\d\\d/', $headers[0]) === 0);

        if (strstr($headers[0], '301') !== false) {
            $ret = self::getHeaders($_url, false);
            return self::getDirectUrl($ret['location'], $exists, false);
        }
        if (strstr($headers[0], '302') !== false) {
            $ret = self::getHeaders($_url, false);
            self::getDirectUrl($ret['location'], $exists, false);
            return $_url;
        }

        return $_url;
    }
}
