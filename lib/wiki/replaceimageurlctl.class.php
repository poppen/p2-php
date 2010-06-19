<?php
/*
ReplaceImageURL(url)        メイン関数
save(array)                 データを保存
load()                      データを読み込んで返す(自動的に実行される)
clear()                     データを削除
autoLoad()                  loadされていなければ実行
*/

require_once P2_LIB_DIR . '/FileCtl.php';

class ReplaceImageURLCtl
{
    var $filename = "p2_replace_imageurl.txt";
    var $data = array();
    var $isLoaded = false;

    // replaceの結果を外部ファイルにキャッシュする
    // とりあえず外部リクエストの発生する$EXTRACT入りの場合のみ対象
    // 500系のエラーだった場合は、キャッシュしない
    var $cacheFilename = "p2_replace_imageurl_cache.txt";
    var $cacheData = array();
    var $cacheIsLoaded = false;

    // 全エラーをキャッシュして無視する(永続化はしないので今回リクエストのみ）
    var $extractErrors = array();
    // 全replaceImageURLをキャッシュする(永続化はしないので今回リクエストのみ）
    var $onlineCache = array();

    function clear() {
        global $_conf;
        $path = $_conf['pref_dir'] . '/' . $this->filename;

        return @unlink($path);
    }

    function autoLoad() {
        if (!$this->isLoaded) $this->load();
        if (!$this->cacheIsLoaded) $this->load_cache();
    }

    function load() {
        global $_conf;

        $lines = array();
        $path = $_conf['pref_dir'].'/'.$this->filename;
        if ($lines = @file($path)) {
            foreach ($lines as $l) {
                if (substr($l, 0, 1) === ';' || substr($l, 0, 1) === "'" ||
                    substr($l, 0, 1) === '#' || substr($l, 0, 2) === '//') {
                    //"#" ";" "'" "//"から始まる行はコメント
                    continue;
                }
                $lar = explode("\t", trim($l));
                if (strlen($lar[0]) == 0 || count($lar) < 2) {
                    continue;
                }
                $ar = array(
                    'match'   => $lar[0], // 対象文字列
                    'replace' => $lar[1], // 置換文字列
                    'referer' => $lar[2], // リファラ
                    'extract' => $lar[3], // EXTRACT
                    'source'  => $lar[4], // EXTRACT正規表現
                    'recheck'  => $lar[5], // EXTRACTしたページを次回もチェックしたいか
                );

                $this->data[] = $ar;
            }
        }
        $this->isLoaded = true;
        return $this->data;
    }

    /**
     * saveReplaceImageURL
     * $data[$i]['match']       Match
     * $data[$i]['replace']     Replace
     * $data[$i]['del']         削除
     */
    function save($data)
    {
        global $_conf;

        $path = $_conf['pref_dir'] . '/' . $this->filename;

        $newdata = '';

        foreach ($data as $na_info) {
            $a[0] = strtr(trim($na_info['match']  , "\t\r\n"), "\t\r\n", "   ");
            $a[1] = strtr(trim($na_info['replace'], "\t\r\n"), "\t\r\n", "   ");
            $a[2] = strtr(trim($na_info['referer'], "\t\r\n"), "\t\r\n", "   ");
            $a[3] = strtr(trim($na_info['extract'], "\t\r\n"), "\t\r\n", "   ");
            $a[4] = strtr(trim($na_info['source'] , "\t\r\n"), "\t\r\n", "   ");
            $a[5] = strtr(trim($na_info['recheck'] ,"\t\r\n"), "\t\r\n", "   ");
            if ($na_info['del'] || ($a[0] === '' || $a[1] === '')) {
                continue;
            }
            $newdata .= implode("\t", $a) . "\n";
        }
        return FileCtl::file_write_contents($path, $newdata);
    }


    function load_cache() {
        global $_conf;
        $lines = array();
        $path = $_conf['pref_dir'].'/'.$this->cacheFilename;
        FileCtl::make_datafile($path);
        if ($lines = @file($path)) {
            foreach ($lines as $l) {
                list($key, $data) = explode("\t", trim($l));
                if (strlen($key) == 0 || strlen($data) == 0) continue;
                $this->cacheData[$key] = unserialize($data);
            }
        }
        $this->cacheIsLoaded = true;
        return $this->cacheData;
    }

    function storeCache($key, $data) {
        global $_conf;

        if ($this->cacheData[$key]) {
            // overwrite the cache file
            $this->cacheData[$key] = $data;
            $body = '';
            foreach ($this->cacheData as $_k => $_v) {
                $body .= implode("\t", array($_k,
                    serialize(ReplaceImageURLCtl::sanitizeForCache($_v)))
                ) . "\n";
            }
            return FileCtl::file_write_contents($_conf['pref_dir'] . '/'
                . $this->cacheFilename, $body);
        } else {
            // append to the cache file
            $this->cacheData[$key] = $data;
            return FileCtl::file_write_contents(
                $_conf['pref_dir'] . '/' . $this->cacheFilename,
                implode("\t", array($key,
                    serialize(ReplaceImageURLCtl::sanitizeForCache($data)))
                ) . "\n",
                FILE_APPEND
            );
        }
    }

    static function sanitizeForCache($data) {
        if (is_array($data)) {
            foreach(array_keys($data) as $k) {
                $data[$k] = ReplaceImageURLCtl::sanitizeForCache($data[$k]);
            }
            return $data;
        } else {
            return str_replace(array("\t", "\r", "\n"), '', $data);
        }
    }

    /**
     * replaceImageURL
     * リンクプラグインを実行
     * return array
     *      $ret[$i]['url']     $i番目のURL
     *      $ret[$i]['referer'] $i番目のリファラ
     */
    function replaceImageURL($url) {
        global $_conf;
        // http://janestyle.s11.xrea.com/help/first/ImageViewURLReplace.html
        $this->autoLoad();
        $src = FALSE;

        if (array_key_exists($url, $this->onlineCache)) {
            return $this->onlineCache[$url];
        }

        if ($this->cacheData[$url]) {
            if ($_conf['wiki.replaceimageurl.extract_cache'] == 1) {
                // キャッシュがあればそれを返す
                return $this->_reply($url, $this->cacheData[$url]['data']);
            }
            if ($this->cacheData[$url]['lost']) {
                // ページが消えている場合キャッシュを返す
                return $this->_reply($url, $this->cacheData[$url]['data']);
            }
            if ($_conf['wiki.replaceimageurl.extract_cache'] == 3) {
                // 前回キャッシュで結果が得られてなければやめ
                if (array_key_exists('data', $this->cacheData[$url]) && is_array($this->cacheData[$url]['data'])
                    && count($this->cacheData[$url]['data']) == 0) {
                    return $this->_reply($url, $this->cacheData[$url]['data']);
                }
            }
        }
        foreach ($this->data as $v) {
            if (preg_match('{^'.$v['match'].'$}', $url)) {
                $match = $v['match'];
                $replace = str_replace('$&', '$0', $v['replace']);
                $referer = str_replace('$&', '$0', $v['referer']);
                // 第一置換(Match→Replace, Match→Referer)
                $replace = @preg_replace ('{'.$match.'}', $replace, $url);
                $referer = @preg_replace ('{'.$match.'}', $referer, $url);
                // $EXTRACTがある場合
                // 注:$COOKIE, $COOKIE={URL}, $EXTRACT={URL}には未対応
                // $EXTRACT={URL}の実装は容易
                if (strstr($v['extract'], '$EXTRACT')){
                    if ($_conf['wiki.replaceimageurl.extract_cache'] == 2) {
                        if ($this->cacheData[$url] && !$v['recheck']) {
                            return $this->_reply($url, $this->cacheData[$url]['data']);
                        }
                    }
                    $source = $v['source'];
                    $return = $this->extractPage($url, $match, $replace, $referer, $source);
                } else {
                    $return[0]['url']     = $replace;
                    $return[0]['referer'] = $referer;
                }
                break;
            }
        }
        /* plugin_imageCache2で処理させるためコメントアウト
        // ヒットしなかった場合
        if (!$return[0]) {
            // 画像っぽいURLの場合
            if (preg_match('{^https?://.+?\\.(jpe?g|gif|png)$}i', $url)) {
                $return[0]['url']     = $url;
                $return[0]['referer'] = '';
            }
        }
        */
        return $this->_reply($url, $return);
    }

    function _reply($url, $data) {
        $this->onlineCache[$url] = $data;
        return $data;
    }

    function extractPage($url, $match, $replace, $referer, $source) {
        global $_conf;
        $ret = array();

        $source =  @preg_replace ('{'.$match.'}', $source, $url);
        $get_url = $referer;
        if ($this->extractErrors[$get_url]) {
            // 今回リクエストでエラーだった場合
            return ($this->cacheData[$url] && $this->cacheData[$url]['data'])
                ? $this->cacheData[$url]['data'] : $ret;
        }

        if (!class_exists('HTTP_Request', false)) {
            require 'HTTP/Request.php';
        }
        $params = array();
        $params['timeout'] = $_conf['http_conn_timeout'];
        $params['readTimeout'] = array($_conf['http_read_timeout'], 0);
        if ($_conf['proxy_use']) {
            $params['proxy_host'] = $_conf['proxy_host'];
            $params['proxy_port'] = $_conf['proxy_port'];
        }
        $req = new HTTP_Request($get_url, $params);
        if ($this->cacheData[$url] && $this->cacheData[$url]['responseHeaders']
                && $this->cacheData[$url]['responseHeaders']['last-modified']
                && strlen($this->cacheData[$url]['responseHeaders']['last-modified'])) {
            $req->addHeader("If-Modified-Since",
                $this->cacheData[$url]['responseHeaders']['last-modified']);
        }
        $req->addHeader('User-Agent',
            (!empty($_conf['expack.user_agent'])) ? $_conf['expack.user_agent']
            : $_SERVER['HTTP_USER_AGENT']);
        $response = $req->sendRequest();
        $code = $req->getResponseCode();
        if (PEAR::isError($response) || ($code != 200 && $code != 206 && $code != 304)) {
            $errmsg = PEAR::isError($response) ? $response->getMessage() : $code;
            // 今回リクエストでのエラーをオンラインキャッシュ
            $this->extractErrors[$get_url] = $errmsg;
            // サーバエラー以外なら永続キャッシュに保存
            if ($code && $code < 500) {
                // ページが消えている場合
                if ($this->_checkLost($url, $ret)) {
                    return $this->cacheData[$url]['data'];
                }
                $this->storeCache($url, array('code' => $code,
                    'errmsg' => $errmsg,
                    'responseHeaders' => $req->getResponseHeader(),
                    'data' => $ret));
            }
            return ($this->cacheData[$url] && $this->cacheData[$url]['data'])
                ? $this->cacheData[$url]['data'] : $ret;
        }
        if ($code == 304 && $this->cacheData[$url]) {
            return $this->cacheData[$url]['data'];
        }

        $body = $req->getResponseBody();
        preg_match_all('{' . $source . '}i', $body, $extracted, PREG_SET_ORDER);
        foreach ($extracted as $i => $extract) {
            $_url = $replace; $_referer = $referer;
            foreach ($extract as $j => $part) {
                if ($j < 1) continue;
                $_url       = str_replace('$EXTRACT'.$j, $part, $_url);
                $_referer   = str_replace('$EXTRACT'.$j, $part, $_referer);
            }
            if ($extract[1]) {
                $_url       = str_replace('$EXTRACT', $part, $_url);
                $_referer   = str_replace('$EXTRACT', $part, $_referer);
            }
            $ret[$i]['url']     = $_url;
            $ret[$i]['referer'] = $_referer;
        }

        // ページが消えている場合
        if ($this->_checkLost($url, $ret)) {
            return $this->cacheData[$url]['data'];
        }

        // 結果を永続キャッシュに保存
        $this->storeCache($url, array('code' => $code,
            'responseHeaders' => $req->getResponseHeader(),
            'data' => $ret));

        return $ret;
    }

    function _checkLost($url, $data) {
        if (count($data) == 0 && $this->cacheData[$url] &&
                $this->cacheData[$url]['data'] &&
                count($this->cacheData[$url]['data']) > 0) {
            $rec = $this->cacheData[$url];
            $rec['lost'] = true;
            $this->storeCache($url, $rec);
            return true;
        }
        return false;
    }

}
