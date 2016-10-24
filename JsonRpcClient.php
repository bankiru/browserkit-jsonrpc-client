<?php

namespace Bankiru\Api\BrowserKit;

use ScayTrase\Api\IdGenerator\IdGeneratorInterface;
use ScayTrase\Api\JsonRpc\JsonRpcRequest;
use ScayTrase\Api\JsonRpc\JsonRpcRequestInterface;
use ScayTrase\Api\JsonRpc\RequestTransformation;
use ScayTrase\Api\Rpc\Exception\RemoteCallFailedException;
use ScayTrase\Api\Rpc\RpcClientInterface;
use ScayTrase\Api\Rpc\RpcRequestInterface;
use Symfony\Component\BrowserKit\Client;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;

final class JsonRpcClient implements RpcClientInterface
{
    /** @var Client */
    private $client;
    /**
     * @var string
     */
    private $uri;
    /**
     * @var IdGeneratorInterface
     */
    private $idGenerator;

    /**
     * JsonRpcClient constructor.
     *
     * @param Client               $client
     * @param string               $uri
     * @param IdGeneratorInterface $idGenerator
     */
    public function __construct(Client $client, $uri, IdGeneratorInterface $idGenerator = null)
    {
        $this->client      = $client;
        $this->uri         = $uri;
        $this->idGenerator = $idGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public function invoke($calls)
    {
        try {
            if (!is_array($calls) && ($calls instanceof RpcRequestInterface)) {
                $transformedCall = $this->transformCall($calls);

                $httpRequest = $this->createHttpRequest($transformedCall);
                $this->client->request(
                    $httpRequest->getMethod(),
                    $httpRequest->getUri(),
                    $httpRequest->getParameters(),
                    [],
                    $httpRequest->getServer(),
                    $httpRequest->getContent()
                );

                return new JsonRpcResponseCollection(
                    $this->checkResponse(),
                    [new RequestTransformation($calls, $transformedCall)]
                );
            }

            $requests     = [];
            $batchRequest = [];

            foreach ((array)$calls as $key => $call) {
                $transformedCall                  = $this->transformCall($call);
                $requests[spl_object_hash($call)] = new RequestTransformation($call, $transformedCall);
                $batchRequest[]                   = $transformedCall;
            }

            $this->client->restart();

            $httpRequest = $this->createHttpRequest($batchRequest);

            $this->client->request(
                $httpRequest->getMethod(),
                $httpRequest->getUri(),
                $httpRequest->getParameters(),
                [],
                $httpRequest->getServer(),
                $httpRequest->getContent()
            );

            return new JsonRpcResponseCollection(
                $this->checkResponse(),
                $requests
            );
        } catch (\Exception $exception) {
            throw new RemoteCallFailedException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param $requestBody
     *
     * @return Request
     */
    private function createHttpRequest($requestBody)
    {
        return new Request(
            $this->uri,
            'POST',
            [],
            [],
            [],
            [
                'HTTP_CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'       => 'application/json',
            ],
            json_encode($requestBody, JSON_PRETTY_PRINT)
        );
    }

    /**
     * @param RpcRequestInterface $call
     *
     * @return JsonRpcRequest
     */
    private function transformCall(RpcRequestInterface $call)
    {
        $transformedCall = $call;
        if ($call instanceof RpcRequestInterface && !($call instanceof JsonRpcRequestInterface)) {
            $transformedCall = JsonRpcRequest::fromRpcRequest($call, $this->idGenerator->getRequestIdentifier($call));
        }

        return $transformedCall;
    }

    /**
     * @return Response
     */
    private function checkResponse()
    {
        return $this->client->getInternalResponse();
    }
}
