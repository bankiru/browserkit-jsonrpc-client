<?php

namespace Bankiru\Api\BrowserKit\Tests;

use Bankiru\Api\BrowserKit\JsonRpcClient;
use Bankiru\Api\BrowserKit\JsonRpcResponseCollection;
use Prophecy\Argument;
use ScayTrase\Api\JsonRpc\JsonRpcError;
use ScayTrase\Api\JsonRpc\JsonRpcNotification;
use ScayTrase\Api\JsonRpc\JsonRpcRequest;
use ScayTrase\Api\JsonRpc\RequestTransformation;
use Symfony\Component\BrowserKit\Client;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;

class ClientTest extends \PHPUnit_Framework_TestCase
{
    private $client;

    public function getResponses()
    {
        return [
            'combo' => [
                [
                    [
                        'jsonrpc' => '2.0',
                        'id'      => 1,
                        'result'  => null,
                    ],
                    //noop for 2
                    [
                        'jsonrpc' => '2.0',
                        'id'      => 3,
                        'error'   => [
                            'code'    => JsonRpcError::INVALID_PARAMS,
                            'message' => 'Invalid data received',
                            'data'    => [
                                'asdasd' => 'test',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider getResponses
     *
     * @param $responses
     */
    public function testJsonRpcClient($responses)
    {
        $client = $this->createClient();
        $this->push(new Response(json_encode($responses, JSON_PRETTY_PRINT)));

        $jsonRpcClient = new JsonRpcClient($client, '/');

        $request1 = new JsonRpcRequest('/test', [], 1);
        $request2 = new JsonRpcNotification('/test', null);
        $request3 = new JsonRpcRequest('/test', ['asdasd' => 'test'], 3);

        $collection = $jsonRpcClient->invoke([$request1, $request2, $request3]);

        $response1  = $collection->getResponse($request1);
        $response2  = $collection->getResponse($request2);
        $response3  = $collection->getResponse($request3);
        self::assertTrue($response1->isSuccessful());
        self::assertTrue($response2->isSuccessful());
        self::assertFalse($response3->isSuccessful());

        self::assertCount(2, $collection); // notifications are not iterated
        foreach ($collection as $response) {
            self::assertContains($response, [$response1, $response3]);
        }
    }

    /**
     * @dataProvider getResponses
     *
     * @param $responses
     */
    public function testResponseFetching($responses)
    {
        $httpResponse      = new Response(json_encode($responses, JSON_PRETTY_PRINT));
        $transformations   = [];
        $request1          = new JsonRpcRequest('/test', [], 1);
        $request2          = new JsonRpcNotification('/test', null);
        $request3          = new JsonRpcRequest('/test', ['asdasd' => 'test'], 3);
        $transformations[] = new RequestTransformation($request1, $request1);
        $transformations[] = new RequestTransformation($request2, $request2);
        $transformations[] = new RequestTransformation($request3, $request3);

        $collection = new JsonRpcResponseCollection($httpResponse, $transformations);
        $response1  = $collection->getResponse($request1);
        $response2  = $collection->getResponse($request2);
        $response3  = $collection->getResponse($request3);
        self::assertTrue($response1->isSuccessful());
        self::assertTrue($response2->isSuccessful());
        self::assertFalse($response3->isSuccessful());

        self::assertCount(2, $collection); // notifications are not iterated
        foreach ($collection as $response) {
            self::assertContains($response, [$response1, $response3]);
        }
    }

    private function createClient()
    {
        if (!$this->client) {
            $this->client = $mock = $this->prophesize(Client::class);
            $this->client->restart()->willReturn(null);
        }

        return $this->client->reveal();
    }

    private function push(Response $response)
    {
        $this->client->request(
            Argument::type('string'),
            Argument::type('string'),
            Argument::any(),
            Argument::any(),
            Argument::type('array'),
            Argument::type('string')
        )->will(

            function (array $arguments) use ($response) {
                list($method, $uri, $parameters, $files, $server, $content) = $arguments;

                $this->getRequest()->willReturn(
                    new Request($uri, $method, $parameters, $files, [], $server, $content)
                );
                $this->getResponse()->willReturn($response);
            }
        );
    }
}
