<?php
// {{{ findproxy()
function findproxy($query)
{
    require_once './simplehtmldom/simple_html_dom.php';
    global $_conf;
    // q -> STR
    // n -> COUNT
    // s -> ???
    // b -> ???
    // c -> ???
    // p -> OFFSET

    //    require_once 'cconsole.php';

    parse_str($query, $query_array);
    $max_count = 50;
    global $limit;
    $limit = $query_array['n'] > $max_count ? $max_count : $query_array['n'];
/*
    $f2query_array = array(
                           'STR' => mb_convert_encoding($query_array['q'], "EUCJP-WIN","UTF-8")
                           ,'TYPE' => 'TITLE'
                           ,'COUNT' => $limit
                           );
 */
    $f2query_array = array(
        'k' => mb_convert_encoding($query_array['q'], "Shift-JIS","UTF-8")
    );


    if(isset($query_array['p'])){
        $f2query_array['OFFSET'] = (intval($query_array['p']) - 1) * intval($limit);
    }

    //    cconsole::info($f2query_array);
    //    print_r($f2query_array);
    ini_set('arg_separator.output', '&');
    $f2query = http_build_query($f2query_array);
    ini_set('arg_separator.output', '&amp;');

    //    print($query . ' -- > ' . $f2query);

    //    $find2ch = 'http://find.moritapo.jp/index.php';
    $find2ch = 'http://ttsearch.net/s2.cgi';

    $client = new HTTP_Client;
    $client->setDefaultHeader('User-Agent', 'p2-tgrep-client');
    //  print($find2ch . '?' . $f2query);
    $code = $client->get($find2ch . '?' . $f2query);
    if (PEAR::isError($code)) {
        p2die($code->getMessage());
    } elseif ($code != 200) {
        p2die("HTTP Error - {$code}");
    }

    $response = $client->currentResponse();
    $dom = str_get_html(urldecode($response['body']));


    $n1 = 0;
    //    foreach($dom->find('dt') as $dom2) {
    foreach($dom->find('div') as $dom2) {
        $element = $dom2->find('a', 0);
    {
        //        foreach($dom2->find('a') as $element) {
        if(ereg('^http://[A-Za-z0-9]+\.(2ch\.net|bbspink\.com)/test/.*read\.cgi/.*', $element->href)){
            // $name = mb_convert_encoding($element->plaintext,"UTF-8","Shift-JIS");
            $name = $element->plaintext;

            $urls = parse_url($element->href);
            $bbs = $tkey = '';
            if(ereg('^/test/read\.cgi/([0-9A-Za-z]+)/([0-9]+)/.*', $urls['path'], $hits)){
                $bbs = $hits[1];
                $tkey = $hits[2];
            }
            $result['threads'][$n1]->title = $name;
            $result['threads'][$n1]->host = $urls['host'];
            $result['threads'][$n1]->bbs = $bbs;
            $result['threads'][$n1]->tkey = $tkey;
            //                $result['profile']['regex'] = '/(.*)/i';
            //		$keyword = explode(" ", $f2query_array['STR']);
            $keyword = explode(" ", $query_array['q']);
            $result['profile']['regex'] = '/(' . $keyword[0] .')/i';

            $length = $dom2->find('span.length', 0);
            if(ereg('\(([0-9]+)\)', $length->plaintext, $hits)){
                $result['threads'][$n1]->resnum = $hits[1];
            }

            $board = $dom2->find('a.board', 0);
            // $name = mb_convert_encoding($board->plaintext,"UTF-8","Shift-JIS");
            $name = $borad->plaintext;
            $result['threads'][$n1]->ita = $name;

            $n1++;
        }
    }
    }
    $result['modified'] = $response['body']['date'];

    foreach($dom->find('font') as $element) {
        $name = mb_convert_encoding($element->find('text', 0)->plaintext,"CP932","EUC-JP");
        if(ereg('([0-9]+)スレ.*', $name, $hits)){
            $result['profile']['hits'] = $hits[1];
            break;
        }
    }

    $result['profile']['hits'] = $n1++;
    return $result;
}
// }}}
?>
