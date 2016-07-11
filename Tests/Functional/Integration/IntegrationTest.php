<?php

namespace Lexik\Bundle\JWTAuthenticationBundle\Tests\Functional\Integration;

use Lexik\Bundle\JWTAuthenticationBundle\Tests\Functional\TestCase;

class IntegrationTest extends TestCase
{
    private static $authorizationHeader;
    private $client;

    public function setUp()
    {
        $this->client = static::createClient();

        static::$kernel->getContainer()->get('session')->invalidate();
    }

    public function testLogin()
    {
        $this->client->request('POST', '/login_check', ['_username' => 'lexik', '_password' => 'dummy']);

        $response = $this->client->getResponse();
        $body     = json_decode($response->getContent(), true);

        $this->assertTrue($response->isSuccessful());
        $this->assertArrayHasKey('token', $body);

        return $body['token'];
    }

    /**
     * @depends testLogin
     */
    public function testSecured($token)
    {
        self::$authorizationHeader = sprintf('Bearer %s', $token);

        $this->client->request('GET', '/api/secured', [], [], ['HTTP_AUTHORIZATION' => self::$authorizationHeader]);

        $this->assertTrue($this->client->getResponse()->isSuccessful());

        $response = $this->client->getResponse();
        $body     = json_decode($response->getContent(), true);
        
        $this->assertTrue($body['success']);
    }
}
