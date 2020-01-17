<?php

/**
 * Tests the cookie class
 *
 * @group ko7
 * @group ko7.core
 * @group ko7.core.cookie
 *
 * @package    KO7
 * @category   Tests
 *
 * @author     Jeremy Bush <contractfrombelow@gmail.com>
 * @author     Andrew Coulton <andrew@ingenerator.com>
 * @copyright  (c) 2007-2016  Kohana Team
 * @copyright  (c) since 2016 Koseven Team
 * @license    https://koseven.ga/LICENSE
 */
class KO7_CookieTest extends Unittest_TestCase
{
	const UNIX_TIMESTAMP      = 1411040141;
	const COOKIE_EXPIRATION   = 60;

	/**
	 * Sets up the environment
	 */
	// @codingStandardsIgnoreStart
	public function setUp(): void
	// @codingStandardsIgnoreEnd
	{
		parent::setUp();
		KO7_CookieTest_TestableCookie::$_mock_cookies_set = [];

		$this->setEnvironment([
			'Cookie::$salt'   => 'some-random-salt',
			'HTTP_USER_AGENT' => 'cli'
		]);
	}

	/**
	 * Tests that cookies are set with the global path, domain, etc options.
	 *
	 * @covers Cookie::set
	 */
	public function test_set_creates_cookie_with_configured_cookie_options()
	{
		$this->setEnvironment([
			'Cookie::$path'     => '/path',
			'Cookie::$domain'   => 'my.domain',
			'Cookie::$secure'   => TRUE,
			'Cookie::$httponly' => FALSE,
		]);

		KO7_CookieTest_TestableCookie::set('cookie', 'value');

		$this->assertSetCookieWith([
			'path'       => '/path',
			'domain'     => 'my.domain',
			'secure'     => TRUE,
			'httponly'   => FALSE
		]);
	}

	/**
	 * Provider for test_set_calculates_expiry_from_lifetime
	 *
	 * @return array of $lifetime, $expect_expiry
	 */
	public function provider_set_calculates_expiry_from_lifetime()
	{
		return [
			[NULL, self::COOKIE_EXPIRATION + self::UNIX_TIMESTAMP],
			[0,    0],
			[10,   10 + self::UNIX_TIMESTAMP],
		];
	}

	/**
	 * @param int $expiration
	 * @param int $expect_expiry
	 *
	 * @dataProvider provider_set_calculates_expiry_from_lifetime
	 * @covers Cookie::set
	 */
	public function test_set_calculates_expiry_from_lifetime($expiration, $expect_expiry)
	{
		$this->setEnvironment(['Cookie::$expiration' => self::COOKIE_EXPIRATION]);
		KO7_CookieTest_TestableCookie::set('foo', 'bar', $expiration);
		$this->assertSetCookieWith(['expire' => $expect_expiry]);
	}

	/**
	 * @covers Cookie::get
	 */
	public function test_get_returns_default_if_cookie_missing()
	{
		unset($_COOKIE['missing_cookie']);
		$this->assertEquals('default', Cookie::get('missing_cookie', 'default'));
	}

	/**
	 * @covers Cookie::get
	 */
	public function test_get_returns_value_if_cookie_present_and_signed()
	{
		KO7_CookieTest_TestableCookie::set('cookie', 'value');
		$cookie = KO7_CookieTest_TestableCookie::$_mock_cookies_set[0];
		$_COOKIE[$cookie['name']] = $cookie['value'];
		$this->assertEquals('value', Cookie::get('cookie', 'default'));
	}

	/**
	 * Provider for test_get_returns_default_without_deleting_if_cookie_unsigned
	 *
	 * @return array
	 */
	public function provider_get_returns_default_without_deleting_if_cookie_unsigned()
	{
		return [
			['unsalted'],
			['un~salted'],
		];
	}

	/**
	 * Verifies that unsigned cookies are not available to the ko7 application, but are not affected for other
	 * consumers.
	 *
	 * @param string $unsigned_value
	 *
	 * @dataProvider provider_get_returns_default_without_deleting_if_cookie_unsigned
	 * @covers Cookie::get
	 */
	public function test_get_returns_default_without_deleting_if_cookie_unsigned($unsigned_value)
	{
		$_COOKIE['cookie'] = $unsigned_value;
		$this->assertEquals('default', KO7_CookieTest_TestableCookie::get('cookie', 'default'));
		$this->assertEquals($unsigned_value, $_COOKIE['cookie'], '$_COOKIE not affected');
		$this->assertEmpty(KO7_CookieTest_TestableCookie::$_mock_cookies_set, 'No cookies set or changed');
	}

	/**
	 * If a cookie looks like a signed cookie but the signature no longer matches, it should be deleted.
	 *
	 * @covers Cookie::get
	 */
	public function test_get_returns_default_and_deletes_tampered_signed_cookie()
	{
		$_COOKIE['cookie'] = Cookie::salt('cookie', 'value').'~tampered';
		$this->assertEquals('default', KO7_CookieTest_TestableCookie::get('cookie', 'default'));
		$this->assertDeletedCookie('cookie');
	}

	/**
	 * @covers Cookie::delete
	 */
	public function test_delete_removes_cookie_from_globals_and_expires_cookie()
	{
		$_COOKIE['cookie'] = Cookie::salt('cookie', 'value').'~tampered';
		$this->assertTrue(KO7_CookieTest_TestableCookie::delete('cookie'));
		$this->assertDeletedCookie('cookie');
	}

	/**
	 * @covers Cookie::delete
	 * @link    http://koseven.ga/issues/3501
	 * @link    http://koseven.ga/issues/3020
	 */
	public function test_delete_does_not_require_configured_salt()
	{
		Cookie::$salt = NULL;
		$this->assertTrue(KO7_CookieTest_TestableCookie::delete('cookie'));
		$this->assertDeletedCookie('cookie');
	}

	/**
	 * @covers Cookie::salt
	 */
	public function test_salt_throws_with_no_configured_salt()
	{
		$this->expectException(KO7_Exception::class);
		Cookie::$salt = NULL;
		Cookie::salt('key', 'value');
	}

	/**
	 * @covers Cookie::salt
	 */
	public function test_salt_creates_same_hash_for_same_values_and_state()
	{
		$name  = 'cookie';
		$value = 'value';
		$this->assertEquals(Cookie::salt($name, $value), Cookie::salt($name, $value));
	}

	/**
	 * Provider for test_salt_creates_different_hash_for_different_data
	 *
	 * @return array
	 */
	public function provider_salt_creates_different_hash_for_different_data()
	{
		return [
			[['name' => 'foo', 'value' => 'bar', 'salt' => 'our-salt', 'user-agent' => 'Chrome'], ['name' => 'changed']],
			[['name' => 'foo', 'value' => 'bar', 'salt' => 'our-salt', 'user-agent' => 'Chrome'], ['value' => 'changed']],
			[['name' => 'foo', 'value' => 'bar', 'salt' => 'our-salt', 'user-agent' => 'Chrome'], ['salt' => 'changed-salt']]
		];
	}

	/**
	 * @param array $first_args
	 * @param array $changed_args
	 *
	 * @dataProvider provider_salt_creates_different_hash_for_different_data
	 * @covers Cookie::salt
	 */
	public function test_salt_creates_different_hash_for_different_data($first_args, $changed_args)
	{
		$second_args = array_merge($first_args, $changed_args);
		$hashes = [];
		foreach ([$first_args, $second_args] as $args)
		{
			Cookie::$salt = $args['salt'];
			$this->set_or_remove_http_user_agent($args['user-agent']);

			$hashes[] = Cookie::salt($args['name'], $args['value']);
		}

		$this->assertNotEquals($hashes[0], $hashes[1]);
	}

	/**
	 * Verify that a cookie was deleted from the global $_COOKIE array, and that a setcookie call was made to remove it
	 * from the client.
	 *
	 * @param string $name
	 */
	// @codingStandardsIgnoreStart
	protected function assertDeletedCookie($name)
	// @codingStandardsIgnoreEnd
	{
		$this->assertArrayNotHasKey($name, $_COOKIE);
		// To delete the client-side cookie, Cookie::delete should send a new cookie with value NULL and expiry in the past
		$this->assertSetCookieWith([
			'name'     => $name,
			'value'    => NULL,
			'expire'   => -86400,
			'path'     => Cookie::$path,
			'domain'   => Cookie::$domain,
			'secure'   => Cookie::$secure,
			'httponly' => Cookie::$httponly
		]);
	}

	/**
	 * Verify that there was a single call to setcookie including the provided named arguments
	 *
	 * @param array $expected
	 */
	// @codingStandardsIgnoreStart
	protected function assertSetCookieWith($expected)
	// @codingStandardsIgnoreEnd
	{
		$this->assertCount(1, KO7_CookieTest_TestableCookie::$_mock_cookies_set);
		$relevant_values = array_intersect_key(KO7_CookieTest_TestableCookie::$_mock_cookies_set[0], $expected);
		$this->assertEquals($expected, $relevant_values);
	}

	/**
	 * Configure the $_SERVER[HTTP_USER_AGENT] environment variable for the test
	 *
	 * @param string $user_agent
	 */
	protected function set_or_remove_http_user_agent($user_agent)
	{
		if ($user_agent === NULL)
		{
			unset($_SERVER['HTTP_USER_AGENT']);
		}
		else
		{
			$_SERVER['HTTP_USER_AGENT'] = $user_agent;
		}
	}
}

/**
 * Class KO7_CookieTest_TestableCookie wraps the cookie class to mock out the actual setcookie and time calls for
 * unit testing.
 */
class KO7_CookieTest_TestableCookie extends Cookie {

	/**
	 * @var array setcookie calls that were made
	 */
	public static $_mock_cookies_set = [];

	/**
	 * {@inheritdoc}
	 */
	protected static function _setcookie($name, $value, $expire, $path, $domain, $secure, $httponly)
	{
		self::$_mock_cookies_set[] = [
			'name'     => $name,
			'value'    => $value,
			'expire'   => $expire,
			'path'     => $path,
			'domain'   => $domain,
			'secure'   => $secure,
			'httponly' => $httponly
		];

		return TRUE;
	}

	/**
	 * @return int
	 */
	protected static function _time()
	{
		return KO7_CookieTest::UNIX_TIMESTAMP;
	}

}
