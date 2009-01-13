<?php
require_once (PATH_t3lib.'class.t3lib_page.php');

require_once (t3lib_extMgm::extPath('nawork_uri').'/lib/class.tx_naworkuri_cache.php');
require_once (t3lib_extMgm::extPath('nawork_uri').'/lib/class.tx_naworkuri_helper.php');

class tx_naworkuri_transformer {
	
	private $conf;
	private $domain;
	private static $instance = null;
	
	/*
	 * @var tx_naworkuri_cache
	 */
	private $cache;

	/**
	 * Constructor
	 *
	 * @param SimpleXMLElement $configXML
	 */
	public function __construct ($configXML=false, $multidomain=false){
			// read configuration
		if ($configXML) {
			$this->conf = $configXML;
		} else {
			$this->conf = new SimpleXMLElement(file_get_contents( t3lib_extMgm::extPath('nawork_uri').'/lib/default_UriConf.xml'));
		}
			// check multidomain mode
		if ( $multidomain ) {
			$this->domain = t3lib_div::getIndpEnv('TYPO3_HOST_ONLY');
		} else {
			$this->domain = '';
		}
		
		$this->cache  = t3lib_div::makeInstance('tx_naworkuri_cache');
		$this->helper = t3lib_div::makeInstance('tx_naworkuri_helper');
		
	}
	
	/**
	 * Create a singleton instance of tx_naworkuri_transformer
	 *
	 * @param SimpleXMLElement $xml_config_file
	 * @return tx_naworkuri_transformer
	 */
	public static function getInstance( $xml_config_file ) {
		if (!self::$instance) {
				 
			$confArray = unserialize( $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['nawork_uri']);
			$config_xml = false;
			
			if (file_exists( PATH_site.$confArray['XMLPATH'] )){
				$config_xml = new SimpleXMLElement(file_get_contents( PATH_site.$confArray['XMLPATH']));
			} elseif (file_exists(PATH_typo3conf.'NaworkUriConf.xml')){
				$config_xml = new SimpleXMLElement(file_get_contents( PATH_typo3conf.'NaworkUriConf.xml'));
			}
			
			$multidomain = $confArray['MULTIDOMAIN'];
			
			self::$instance = new tx_naworkuri_transformer($config_xml, $multidomain);
		}
		return self::$instance;
	}
	
	/**
	 * get Current Configuration
	 *
	 * @return SimpleXMLElement Configuration
	 */
	public function getConfiguration(){
		return $this->conf;
	}
  
	/**
	 * Convert the uri path to the request parameters
	 *
	 * @param string $uri
	 * @return array Parameters extracted from path and GET
	 */
	public function uri2params ($uri = ''){
			// remove opening slash
		if (empty($uri)) return;
      
			// look into the db
		list($path,$params) = explode('?',$uri);

		$cache = $this->cache->read_path($path, $this->domain);
        if ($cache){
        		// cachedparams
			$cachedparams = Array();
        	parse_str($cache['params'], $cachedparams);
        	$cachedparams['id'] = $cache['pid'];
        	$cachedparams['L']  = $cache['sys_language_uid'];
        		// classic url params 
        	$getparams = Array();
        	parse_str($params, $getparams);
        		// merged result
        	$res = t3lib_div::array_merge_recursive_overrule($cachedparams, $getparams);
        	return $res;
        }
		return false;
	}
		
	/**
	 * Encode Parameters as URI-Path
	 *
	 * @param str $param_str Parameter string
	 * @return string $uri encoded uri
	 */
	public function params2uri ($param_str){
		
		$params = $this->helper->explode_parameters($param_str);
		
			// find already created uri with exactly these parameters
		$cache_uri = $this->cache->read_params($params, $this->domain);	
		if ( $cache_uri !== false ) {
			return $cache_uri;
		}
		
			// create new uri because no exact match was found in cache
		$original_params  = $params;
		$encoded_params   = array();
		$unencoded_params = $original_params;
		 
	 		// transform the parameters to path segments
  		$path = array();		 
		$path = array_merge($path, $this->params2uri_predefinedparts(&$original_params, &$unencoded_params, &$encoded_params) );
 		$path = array_merge($path, $this->params2uri_valuemaps(&$original_params, &$unencoded_params, &$encoded_params) );
		$path = array_merge($path, $this->params2uri_uriparts(&$original_params, &$unencoded_params, &$encoded_params) );
 		$path = array_merge($path, $this->params2uri_pagepath(&$original_params, &$unencoded_params, &$encoded_params) );
 		
  			// order the params like configured 
  		$ordered_params = array();
  		foreach ($this->conf->paramorder->param as $param) {
  			$param_name = (string)$param;
  			if (isset($path[$param_name]) && $segment = $path[$param_name]){
  				if ($segment) $ordered_params[]=$segment;
  				unset($path[$param_name]);
  			}
  		}
  			// add params with not specified order
  		foreach ($path as $param=>$path_segment) {
  			if ($path_segment) $ordered_params[]=$path_segment;
  		}
  			// return 
  		if (count($ordered_params)){
  			$encoded_uri = implode('/',$ordered_params).'/';
  		} else {
  			$encoded_uri = '';
  		}
		
  			// check for cache entry with these uri an create cache entry if needed 
		$cache_uri = $this->cache->read_params($encoded_params, $this->domain);
  		if ( $cache_uri !== false ) {
  			$uri = $cache_uri;
  		} else {
  			$debug_info = '';
  			$debug_info .= "original_params  : ".$this->helper->implode_parameters($original_params).chr(10);
  			$debug_info .= "encoded_params   : ".$this->helper->implode_parameters($encoded_params).chr(10);
  			$debug_info .= "unencoded_params : ".$this->helper->implode_parameters($unencoded_params).chr(10);

  			$cache_path   = $this->helper->sanitize_uri($encoded_uri);
  			$uri = $this->cache->write_params($encoded_params, $this->domain, $cache_path, $debug_info);
  		}
  		
  			// read not encoded parameters
  		$i =0; 
  		foreach ($unencoded_params as $key => $value){
  			$uri.= ( ($i>0)?'&':'?' ).$key.'='.$value;
  			$i++;
  		}
  		
  		return($uri);
  				
	}	
	

	/**
	 * Encode the predifined parts
	 * 
	 * @param array $original_params  : original Parameters
	 * @param array $unencoded_params : unencoded Parameters
	 * @param array $encoded_params   : encoded Parameters
	 * @return array : Path Elements for final URI 
	 */
	public function params2uri_predefinedparts(&$original_params, &$unencoded_params, &$encoded_params ){
		
		$parts = array();
  		foreach ($this->conf->predefinedparts->part as $part) {

			$param_name = (string)$part->parameter;
  			if ( $param_name && isset($unencoded_params[$param_name]) ) {
  				$value  = (string)$part->value;
  				$key    = (string)$part->attributes()->key;
			
  				if (!$key) {
  					if (!$value && $value!=='0' ) {
	  					$encoded_params[$param_name]=$unencoded_params[$param_name];
	  					unset($unencoded_params[$param_name]);
	  				} elseif ($unencoded_params[$param_name] == $value) {
  						$encoded_params[$param_name]=$unencoded_params[$param_name];
  						unset($unencoded_params[$param_name]);
	  				}
  				} else {
  					if ($value && $unencoded_params[$param_name] == $value ){
  						$encoded_params[$param_name]=$unencoded_params[$param_name];
	  					unset($unencoded_params[$param_name]);
	  					$parts[$param_name] = $key;
  					} else if (!$value) {
  						$parts[$param_name] = str_replace('###', $unencoded_params[$param_name], $key);
  						$encoded_params[$param_name]=$unencoded_params[$param_name];
						unset($unencoded_params[$param_name]);  						
  					}
  				}
  			}
  		}
  		return $parts;
	}
	
	/**
	 * Encode tha Valuemaps 
	 * 
	 * @param array $original_params  : original Parameters
	 * @param array $unencoded_params : unencoded Parameters
	 * @param array $encoded_params   : encoded Parameters
	 * @return array : Path Elements for final URI 
	 */
	public function params2uri_valuemaps (&$original_params, &$unencoded_params, &$encoded_params ){
		$parts = array();
		foreach ($this->conf->valuemaps->valuemap as $valuemap) {
			$param_name = (string)$valuemap->parameter;
  			if ( $param_name && isset($unencoded_params[$param_name]) ) {
				foreach($valuemap->value as $value){
					if ( (string)$value == $unencoded_params[$param_name]){
						$key = (string)$value->attributes()->key;
						$remove = (string)$value->attributes()->remove;
						if (!$remove){
							if ($key) {
								$parts[$param_name] = $key;
							}
							$encoded_params[$param_name]=$unencoded_params[$param_name];
							unset($unencoded_params[$param_name]);  
						} else {
							unset($unencoded_params[$param_name]);
						}	
					}  	
				}			
  			}
		}
		return $parts;
	}
	
	/**
	 * Encode the Uriparts
	 * 
	 * @param array $original_params  : original Parameters
	 * @param array $unencoded_params : unencoded Parameters
	 * @param array $encoded_params   : encoded Parameters
	 * @return array : Path Elements for final URI 
	 */
	public function params2uri_uriparts (&$original_params, &$unencoded_params, &$encoded_params){
		$parts = array();
		foreach ($this->conf->uriparts->part as $uripart) {
			
			$param_name = (string)$uripart->parameter;
  			if ( $param_name && isset($unencoded_params[$param_name]) ) {
  				$table  = (string)$uripart->table;
  				$field  = (string)$uripart->field;
  				$where  = str_replace('###',$unencoded_params[$param_name], (string)$uripart->where);
  				$dbres = $GLOBALS['TYPO3_DB']->exec_SELECTquery($field, $table, $where, '', '' ,1 );
  				if ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($dbres) ){
  					foreach ($row as $key=>$value){
  						if ($value){
  							$parts[$param_name] = $value;
							$encoded_params[$param_name]=$unencoded_params[$param_name];
							unset($unencoded_params[$param_name]); 
							break;	
  						}
  					}
  				}
  			}
		}

		return $parts;
	}
	
	/**
	 * Encode the Pagepath
	 *
	 * @param array $original_params  : original Parameters
	 * @param array $unencoded_params : unencoded Parameters
	 * @param array $encoded_params   : encoded Parameters
	 * @return array : Path Elements for final URI 
	 */
	public function params2uri_pagepath (&$original_params, &$unencoded_params, &$encoded_params) {
		$parts = array();
		if ($this->conf->pagepath && $unencoded_params['id']){
			 
				// cast id to int and resolve aliases
			if ($unencoded_params['id']){
				if (is_numeric($unencoded_params['id']) ){
					$unencoded_params['id'] = (int)$unencoded_params['id'];
				} else {
					$str = $GLOBALS['TYPO3_DB']->fullQuoteStr($unencoded_params['id'], 'pages');
					$dbres = $GLOBALS['TYPO3_DB']->exec_SELECTquery( 'uid' , 'pages', 'alias='.$str.' AND deleted=0', '', '' ,1 );
					if ( $row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($dbres) ){
						$unencoded_params['id'] = $row['uid'];
					} else {
						return array();
					}
				}
			}
			
			$id = $unencoded_params['id'];
		
				// get setup
			$limit  = (int)(string)$this->conf->pagepath->limit;
			if (!$limit) $limit=10;
			$fields  = explode(',', 'tx_naworkuri_pathsegment,'.(string)$this->conf->pagepath->field );
			
				// determine language (system or link)
			$lang = 0;
			if ( $GLOBALS['TSFE'] && $GLOBALS['TSFE']->config['config']['sys_language_uid']){
				$lang = (int)$GLOBALS['TSFE']->config['config']['sys_language_uid'];
			}
			if (isset($original_params['L'])) {
				$lang = (int)$original_params['L'];
			}
				
				// walk the pagepath
			while ($limit && $id > 0){

  				$dbres = $GLOBALS['TYPO3_DB']->exec_SELECTquery( implode(',',$fields).',uid,pid,hidden,tx_naworkuri_exclude' , 'pages', 'uid='.$id.' AND deleted=0', '', '' ,1 );
				$row   = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($dbres);
				if (!$row) break; // no page found
				
					// translate pagepath if needed
					// @TODO some languages have to be excluded here somehow
				if ( $lang > 0 ){
					$dbres = $GLOBALS['TYPO3_DB']->exec_SELECTquery( '*' , 'pages_language_overlay', 'pid='.$id.' AND deleted=0 AND sys_language_uid='.$lang, '', '' ,1 );
					$translated_row   = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($dbres);
					foreach ($fields as $field){
						if ($translated_row[$field] ) {
							$row[$field] = $translated_row[$field];
						}
					}
				}
					// extract part
				if ($row['tx_naworkuri_exclude'] == 0 ){
					if ($row['pid']>0){
						foreach ($fields as $field){
							if ( $row[$field] ) {
								$segment = trim($row[$field]);
								array_unshift($parts,$segment);
								break; // field found
							}
						}
					} elseif ( $row['pid']==0 && $row['tx_naworkuri_pathsegment'] ){
						$segment = trim($row['tx_naworkuri_pathsegment']);
						array_unshift($parts,$segment);
					}  
				}
					// continue fetching the path
				$id = $row['pid'];
				$limit--;
			} 
			
			
			$encoded_params['id']=$unencoded_params['id'];
			unset($unencoded_params['id']);  
		}
		
		return array('id'=>implode('/',$parts));
	}
	
	
	
}
?>