<?php

require_once 'NeetelAgent.php';

function array_get(&$value, $default = null) {
  return isset($value) ? $value : $default;
}

function array_extract($keys, $src) {
  $dist = array();
  foreach ($keys as $k) {
    $v = array_get($src[$k], null);
    if ($v) $dist[$k] = $v;
  }
  return $dist;
}

function main($action) {
  $cookie = array_extract(array('editversion', 'CCSSID2', 'userlogin3', 'comicid3'), $_COOKIE);
  $na = new NeetelAgent($cookie);

  switch ($action) {
    case 'login':
      $cookie = $na->login(array_get($_POST['id']), array_get($_POST['password']));
      foreach ($cookie as $k => $v) {
        setcookie($k, (string) $v, 0, '/');
      }
      return $cookie;
    case 'get.data':
      return $na->getComicData();
    case 'get.page':
      return $na->getComicPageData((int) array_get($_GET['page']));
    case 'update.info':
      $info = array_extract(array('name', 'category', 'author', 'tags', 'xurl', 'summary', 'comment', 'magazine', 'state', 'taggable'), $_POST);
      return $na->postComicInfo($info);
    case 'update.story':
      return $na->updateComicStory((int) array_get($_POST['story']), array_get($_POST['title']));
    case 'delete.story':
      return $na->deleteComicStory((int) array_get($_POST['story']));
    case 'create.page':
      return $na->createComicPageText((int) array_get($_POST['after_page']), array_get($_POST['text']));
    case 'delete.page':
      return $na->deleteComicPageByNum((int) array_get($_POST['delete_num']));
    default:
      return NeetelAgent::errorData(0, 'Not support: ' . $action);
  }
}

$action = array_get($_POST['action'], array_get($_GET['action']));

if ($action !== 'debug') {
  echo json_encode(main($action));
}
