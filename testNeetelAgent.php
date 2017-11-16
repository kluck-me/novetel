<?php

if (!isset($argc)) exit;

require_once 'NeetelAgent.php';

function pp() {
  $args = func_get_args();
  foreach ($args as $arg) {
    var_export($arg);
    echo PHP_EOL;
  }
}

$na = new NeetelAgent;
if (!$na->isLoggedIn()) {
  pp($na->login('20635', '*******'));
}

// --
pp($na->postComicInfo(array(
  'name' => 'APIテスト',
  'category' => '更新テスト４',
  'author' => 'テスター',
  'tags' => 'テスト',
  'xurl' => '',
  'summary' => '更新試験中４です。',
  'comment' => '',
  'magazine' => '9',
  'state' => '5',
  'taggable' => '1',
)));
pp($na->getComicData()['info']);
// --
pp($na->createComicPageText(0, '新しい新しいページ。'));
pp($na->getComicData()['stories']);
// --
pp($na->deleteComicPageByNum(13));
pp($na->getComicData()['stories']);
// --
pp($na->updateComicStory(8, 'その次の新しい新しい区切り'));
pp($na->getComicData()['stories']);
// --
pp($na->deleteComicStory(7));
pp($na->getComicData()['stories']);
// --
pp($na->getComicPageData(10));
pp($na->getComicPageData(1));
