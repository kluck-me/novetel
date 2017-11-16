<?php

class NeetelAgent {
  // -- Utils
  public static function arrayGet(&$value, $default = null) {
    return isset($value) ? $value : $default;
  }

  public static function errorData($code, $message) {
    return array(
      'error' => array(
        'code' => $code,
        'message' => $message,
      )
    );
  }

  private static function httpBuildCookieHeader($cookies) {
    if (!$cookies) return null;
    $cookies_array = array();
    foreach ($cookies as $cookie_key => $cookie_val) {
      $cookies_array[] = urlencode($cookie_key) . '=' . urlencode($cookie_val);
    }
    return join('; ', $cookies_array);
  }

  private static function httpBuildHeader($header_pairs) {
    $headers = array();
    foreach ($header_pairs as $k => $v) {
      if ($k === 'Cookie' && is_array($v)) {
        $v = self::httpBuildCookieHeader($v);
      }
      if ($v === null) continue;
      $headers[] = $k . ': ' . $v;
    }
    return join("\r\n", $headers);
  }

  public static function htmlEntityDecode($html) {
    return html_entity_decode($html, ENT_QUOTES, 'UTF-8');
  }

  public static function htmlTagParseCore($tagreg, $html) {
    $tags = array();
    if (preg_match_all('/<' . $tagreg . '>(?:([\s\S]*?)<\/\1>)?/', $html, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $m) {
        $tag = array(
          'tagName' => $m[1],
          'innerHTML' => isset($m[3]) ? $m[3] : null,
        );
        if (preg_match_all('/([\w-]+)(?:=(?:"([^"]*)"|\'([^\']*)\'|(\w+)))?/', $m[2], $ams, PREG_SET_ORDER)) {
          foreach ($ams as $am) {
            $aval = isset($am[2]) ? $am[2] : (isset($am[3]) ? $am[3] : (isset($am[4]) ? $am[4] : $am[1]));
            $tag[$am[1]] = self::htmlEntityDecode($aval);
          }
        }
        $tags[] = $tag;
      }
    }
    return $tags;
  }

  public static function htmlTagParse($tag, $html) {
    return self::htmlTagParseCore('(' . $tag . ')([^>]*)', $html);
  }

  // -- Agent
  private $cookie;

  public function __construct($cookie = array()) {
    $this->cookie = $cookie;
  }

  public function httpRequest($method, $url, $data = null, $header_pairs = array()) {
    if ($data) {
      $header_pairs['Content-Length'] = strlen($data);
    }
    $header_pairs['Referer'] = $url;
    $header_pairs['Cookie'] = $this->cookie;

    $options = array(
      'http' => array(
        'method' => $method,
        'header' => self::httpBuildHeader($header_pairs),
        'content' => $data,
      ),
    );
    $stream = stream_context_create($options);
    $http_response_contents = file_get_contents($url, FALSE, $stream);

    foreach ($http_response_header as $header) {
      if (preg_match('/^Set-Cookie: ([^=]*)=([^;]*)/', $header, $m)) {
        $this->cookie[urldecode($m[1])] = urldecode($m[2]);
      }
    }

    return $http_response_contents;
  }

  public function httpPost($url, $query_data = array()) {
    return $this->httpRequest('POST', $url, http_build_query($query_data), array(
      'Content-Type' => 'application/x-www-form-urlencoded',
    ));
  }

  public function httpGet($url) {
    return $this->httpRequest('GET', $url);
  }

  // -- Neetel
  private static function neetelParseLoginStatus($html) {
    if (!preg_match('/<h3>新都社 作品ログイン<\/h3>/', $html)) return null;
    return self::errorData(1, 'ログインが必要です。');
  }

  private static function neetelParseComicInfo($html) {
    $data = array();
    $forms = self::htmlTagParse('form', $html);
    $form_html = $forms[0]['innerHTML'];
    foreach (self::htmlTagParse('input', $form_html) as $input) {
      if ($input['type'] === 'submit') continue;
      if ($input['type'] === 'radio') continue;
      $data[$input['name']] = $input['value'];
    }
    foreach (self::htmlTagParse('select', $form_html) as $select) {
      $options = self::htmlTagParse('option', $select['innerHTML']);
      foreach ($options as $option) {
        if (!isset($option['selected'])) continue;
        $data[$select['name']] = $option['value'];
        break;
      }
    }
    return $data;
  }

  private static function neetelParseComicStories($html) {
    $data = array();
    $selects = self::htmlTagParseCore('(select)( name="delete_num")', $html);
    $options = self::htmlTagParse('option', $selects[0]['innerHTML']);
    $page_to_num = array();
    foreach ($options as $option) {
      $page_to_num[$option['innerHTML']] = $option['value'];
    }
    $views = self::htmlTagParseCore('(div)( id="view")', $html);
    $view_html = $views[0]['innerHTML'];
    foreach (self::htmlTagParse('a', $view_html) as $a) {
      $url = parse_url($a['href']);
      parse_str($url['query'], $queries);
      if (isset($queries['story'])) {
        $data[] = array(
          'title' => $a['innerHTML'],
          'story' => $queries['story'],
          'pages' => array(),
        );
      } else if (isset($queries['page'])) {
        $data[count($data) - 1]['pages'][] = array(
          'title' => $a['innerHTML'],
          'page' => $queries['page'],
          'delete_num' => $page_to_num[$queries['page']],
        );
      }
    }
    return $data;
  }

  public function httpPostNeetel($data) {
    if (!$this->isLoggedIn()) return self::errorData(1, 'ログインが必要です。');
    $html = $this->httpPost('http://neetsha.jp/inside/edit.php?id=' . $this->getComicID(), $data);
    $error = self::neetelParseLoginStatus($html);
    if ($error) return $error;
    if (preg_match('/<div><b style="color:red;">([\s\S]*?)<\/b>/', $html, $m)) {
      return self::errorData(2, self::htmlEntityDecode(trim($m[1])));
    }
    if (preg_match('/<div style="text-align:center;margin-top:200px;">([\s\S]*?)<br>/', $html, $m)) {
      return array('message' => self::htmlEntityDecode(trim($m[1])));
    }
    return self::errorData(0, '予期しないエラーが発生しました。');
  }

  public function getComicID() {
    $comicid = self::arrayGet($this->cookie['comicid3']);
    return preg_match('/\D/', $comicid) ? 0 : (int) $comicid;
  }

  public function isLoggedIn() {
    return !!$this->getComicID();
  }

  public function login($comicid, $password) {
    $this->httpPost('http://neetsha.jp/inside/edit.php', array(
      'loginform' => '1',
      'login_id' => $comicid,
      'login_password' => $password,
    ));
    return $this->isLoggedIn() ? $this->cookie : self::errorData(1, 'ログインに失敗しました。');
  }

  public function getComicData() {
    $html = $this->httpGet('http://neetsha.jp/inside/edit.php?id=' . $this->getComicID());
    $error = self::neetelParseLoginStatus($html);
    return $error ? $error : array(
      'info' => self::neetelParseComicInfo($html),
      'stories' => self::neetelParseComicStories($html),
    );
  }

  public function postComicInfo($data) {
    $data['id'] = $this->getComicID();
    $data['methodid'] = self::arrayGet($data['methodid'], '2'); // 1 OR 2
    $data['submit'] = 'ステータス更新'; // 無いと文字化け警報が出る
    return $this->httpPostNeetel($data);
  }

  public function createComicPageText($afterPage, $text) {
    if (!$afterPage) $afterPage = 'new';
    return $this->httpPostNeetel(array(
      'id' => $this->getComicID(),
      'methodid' => '12',
      'e_page' => $afterPage,
      'uptext' => $text,
      'submit' => 'upload',
    ));
  }

  public function deleteComicPageByNum($delete_num) {
    return $this->httpPostNeetel(array(
      'id' => $this->getComicID(),
      'methodid' => '13',
      'delete_num' => $delete_num,
      'submit' => '削除',
    ));
  }

  public function updateComicStory($afterPage, $title) {
    return $this->httpPostNeetel(array(
      'id' => $this->getComicID(),
      'methodid' => '14',
      'e_page' => $afterPage,
      'separate' => $title,
      'delete_num' => $delete_num,
      'submit' => 'セパレート',
    ));
  }

  public function deleteComicStory($target) {
    return $this->httpPostNeetel(array(
      'id' => $this->getComicID(),
      'methodid' => '15',
      'e_page' => $target,
      'submit' => '削除',
    ));
  }

  public static function getNeetshaDataDirectoryURL($id) {
    $id = (int) $id;
    $str = (strlen($id) < 4) ? substr('0000' . $id, -4) : (string) $id;
    return 'http://neetsha.jp/inside/up/' . $str[0] . '/' . $str[1] . '/' . $id . '/';
  }

  public static function convertToUTF8($text) {
    $encoding = @mb_detect_encoding($text, 'UTF-8,eucJP-win,SJIS-win,ASCII') or 'SJIS-win';
    $text = @mb_convert_encoding($text, 'UTF-8', $encoding);
    return $text;
  }

  public static function extractContentType($headers) {
    foreach ($headers as $header) {
      if (preg_match('/^Content-Type: (\S+)/', $header, $m)) {
        return $m[1];
      }
    }
    return 'application/octet-stream';
  }

  public function getComicPageData($page) {
    $id = $this->getComicID();
    $query = http_build_query(array(
      'id' => $id,
      'page' => (int) $page,
    ));
    $html = file_get_contents('http://neetsha.jp/inside/comic.php?' . $query);

    if (preg_match('/<div class="text" title="([^"]+)">/', $html, $m)) {
      $url = self::getNeetshaDataDirectoryURL($id) . $m[1];
      $text_contents = file_get_contents($url);
      return array(
        'type' => 'text',
        'url' => $url,
        'data' => self::convertToUTF8($text_contents),
      );
    }

    if (preg_match('/<div class="image">.*?<img src="[^"]+?(\d+\.\w+)"/', $html, $m)) {
      $url = self::getNeetshaDataDirectoryURL($id) . $m[1];
      $image_contents = file_get_contents($url);
      $content_type = self::extractContentType($http_response_header);
      return array(
        'type' => 'image',
        'url' => $url,
        'data' => 'data:' . $content_type . ';base64,' . base64_encode($image_contents),
      );
    }

    return self::errorData(0, '予期しないエラーが発生しました。');
  }
}
