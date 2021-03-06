<?php

namespace Nawork\NaworkUri\Tests\Unit\Utility;

/**
 * @testdox
 */
class TransformationUtilityTest extends \Nawork\NaworkUri\Tests\TestBase {

	/**
	 * dataprovider for params2uri_pagepathReturnsCorrectPath
	 */
	public function params2uriReturnCorrectPathProvider() {
		return array(
			'page id 1' => array(
				array(),
				'id=1',
				'',
			),
			'page id 6' => array(
				array(),
				'id=6',
				'sub-1/no-news/',
			),
			'page id 10' => array(
				array(),
				'id=10',
				'sub-2/sub-2-4/',
			),
			'page id 11' => array(
				array(),
				'id=11',
				'sub-3/sub-3-1/',
			),
			'pid = 8, news id = 1, foo=bar' => array(
				array(),
				'id=8&tx_ttnews[tt_news]=1&foo=bar',
				'sub-2/sub-2-2/news-1/?foo=bar',
			),
			'? at the end of the page title' => array(
				array(),
				'id=12',
				'sub-3/sub-3-1/sub-3-1-1/',
			),
			'? at the beginning of the page title' => array(
				array(),
				'id=13',
				'sub-3/sub-3-1/sub-3-1-2/',
			),
			'? as page title' => array(
				array(),
				'id=14',
				'sub-3/sub-3-1/',
			),
			'? as page title and existing same uri' => array(
				array(
					'id=11',
				),
				'id=14',
				'sub-3/sub-3-1-1/',
			),
			'? as page title and two existing same uris' => array(
				array(
					'id=11',
					'id=11&no_cache=1',
				),
				'id=14',
				'sub-3/sub-3-1-2/',
			),
			'Sub ?#? 3 1 4 as page title' => array(
				array(),
				'id=15',
				'sub-3/sub-3-1/sub-3-1-4/',
			),
			'Sub !# 3 1 5 as page title' => array(
				array(),
				'id=16',
				'sub-3/sub-3-1/sub-3-1-5/',
			),
			'page id 10, no_cache 1, first existing' => array(
				array(
					'id=10',
				),
				'id=10&no_cache=1',
				'sub-2/sub-2-4-1/',
			),
			'page id 10, no_cache 1, both existing' => array(
				array(
					'id=10',
					'id=10&L=0&no_cache=1',
				),
				'id=10&L=0&no_cache=1',
				'sub-2/sub-2-4-1/',
			),
			'page id 10, no_cache 2, two equal urls existing' => array(
				array(
					'id=10',
					'id=10&no_cache=1',
				),
				'id=10&no_cache=2',
				'sub-2/sub-2-4-2/',
			),
			'page title with comma' => array(
				array(),
				'id=17',
				'sub-3/sub-3-1/test-foo-bar-und-blafasel/'
			)
		);
	}

	/**
	 * @test
	 * @dataProvider params2uriReturnCorrectPathProvider
	 */
	public function params2uriReturnsCorrectPath($preparedUris, $params, $expectedResult) {
		$uris = array();
		foreach ($preparedUris as $uri) {
			$uris[$uri] = $this->transformer->params2uri($uri);
		}
		$result = $this->transformer->params2uri($params);
		$this->assertEquals($expectedResult, $result);
	}

	public function uri2paramsReturnsCorrectResultProvider() {
		return array(
			array(
				'id=8&tx_ttnews[tt_news]=1',
				'sub-2/sub-2-2/news-1/',
				array(
					'id' => 8,
					'L' => 0,
					'tx_ttnews' => array('tt_news' => 1),
				),
			),
			array(
				'id=8',
				'sub-2/sub-2-2',
				array(
					'id' => 8,
					'L' => 0,
				),
			),
			array(
				'id=8',
				'sub-2/sub-2-2/',
				array(
					'id' => 8,
					'L' => 0,
				),
			),
			'three encoded(id,L,cHash) and two unencoded(foo,blub) parameters: remove cHash on unencoded parameters' => array(
				'id=8&foo=bar&cHash=123&blub=bla&L=0',
				'sub-2/sub-2-2/',
				array(
					'id' => 8,
					'L' => 0,
				),
			),
			'three encoded(id,L,no_cache) and no unencoded parameters' => array(
				'id=8&no_cache=1&L=0',
				'sub-2/sub-2-2/',
				array(
					'id' => 8,
					'L' => 0,
					'no_cache' => 1,
				),
			),
		);
	}

	/**
	 * General Path encoding tests
	 *
	 * @test
	 * @dataProvider uri2paramsReturnsCorrectResultProvider
	 * @param array $params Parameters to encode
	 * @param string $uri Expected URI
	 */
	public function uri2paramsReturnsCorrectResult($preparedUri, $uri, $params) {
		$this->transformer->params2uri($preparedUri);
		$this->assertEquals($params, $this->transformer->uri2params($uri));
	}

	/**
	 * @test
	 */
	public function uri2paramsReturnsCorrectResultOnURIWithNonDefaultAppend() {
		$this->transformer->params2uri('id=10');
		$this->db->exec_UPDATEquery('test_tx_naworkuri_uri', 'uid=1', array(
			'path' => 'home.html',
			'path_hash' => md5('home.html'),
		));
		$this->assertEquals(array(
			'id' => '10',
			'L' => '0',
			), $this->transformer->uri2params('home.html'));
	}

	/**
	 * @test
	 * the saved domain should be test.test but the transformer is initialized with test.local
	 */
	public function params2uriSavesCorrectDomainForUris() {
		$this->transformer->params2uri('id=5');
		$this->transformer->params2uri('id=6');
		$this->transformer->params2uri('id=7');
		$this->transformer->params2uri('id=8');
		$dbRes = $this->db->exec_SELECTcountRows('uid', 'test_tx_naworkuri_uri', 'domain NOT LIKE \'test.local\'');
		$this->assertEquals(0, $dbRes);
	}

	/**
	 * @test
	 */
	public function params2uriReturnsCorrectResultAfterAConflicingURIHasBeenDeleted() {
		$this->transformer->params2uri('id=4');
		$this->transformer->params2uri('id=4&no_cache=1');
		$this->db->exec_DELETEquery('test_tx_naworkuri_uri', 'uid=1');
		$this->assertEquals('sub-3-1/', $this->transformer->params2uri('id=4&no_cache=1'));
	}
	
//	public function transformParameterToPathReturnCorrectResult($parameterString)

}

?>