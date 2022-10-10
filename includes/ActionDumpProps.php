<?php

use Action;
use MediaWikiServices;

class ActionDumpProps extends Action {
  public function getName() {
		return 'propsdump';
	}
  
  public function show() {
    $out = $this->getOutput();
    $request = $this->getRequest();
    $title = $this->page->getTitle();
    
    $dbl = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr = $dbl->getConnection( DB_REPLICA );
    $props = $dbr->select( 'page_props', // table to use
        ['name' => 'pp_propname', 'value' => 'pp_value'], // Field to select
        [ 'pp_page' => $title->getArticleID()], // where conditions
        __METHOD__
    );
    
    $ret = [];
    foreach($props as $row) {
      $ret[$row->name] = $row->value;
    }
    
    echo json_encode($ret);
    die();
  }
}
