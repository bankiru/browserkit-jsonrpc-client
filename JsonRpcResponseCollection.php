<?php

namespace Bankiru\Api\BrowserKit;

use ScayTrase\Api\JsonRpc\Exception\JsonRpcProtocolException;
use ScayTrase\Api\JsonRpc\Exception\ResponseParseException;
use ScayTrase\Api\JsonRpc\JsonRpcNotificationResponse;
use ScayTrase\Api\JsonRpc\JsonRpcResponseInterface;
use ScayTrase\Api\JsonRpc\RequestTransformation;
use ScayTrase\Api\JsonRpc\SyncResponse;
use ScayTrase\Api\Rpc\Exception\RemoteCallFailedException;
use ScayTrase\Api\Rpc\ResponseCollectionInterface;
use ScayTrase\Api\Rpc\RpcRequestInterface;
use Symfony\Component\BrowserKit\Response;

final class JsonRpcResponseCollection implements \IteratorAggregate, ResponseCollectionInterface
{
    /** @var JsonRpcResponseInterface[] */
    protected $hashedResponses = [];
    /** @var  Response */
    private $response;
    /** @var  RequestTransformation[] */
    private $transformations;
    /** @var  JsonRpcResponseInterface[] */
    private $responses = [];

    /**
     * JsonRpcResponseCollection constructor.
     *
     * @param Response                $response
     * @param RequestTransformation[] $transformations
     */
    public function __construct(Response $response, array $transformations)
    {
        $this->response        = $response;
        $this->transformations = $transformations;
        $this->sync();
    }

    /** {@inheritdoc} */
    public function getIterator()
    {
        return new \ArrayIterator($this->responses);
    }

    /** {@inheritdoc} */
    public function getResponse(RpcRequestInterface $request)
    {
        if (array_key_exists(spl_object_hash($request), $this->hashedResponses)) {
            return $this->hashedResponses[spl_object_hash($request)];
        }

        $storedRequest = null;
        foreach ($this->transformations as $transformation) {
            if ($transformation->getOriginalCall() === $request) {
                $storedRequest = $transformation->getTransformedCall();
                break;
            }
        }

        if (null === $storedRequest) {
            throw new \OutOfBoundsException('Given request was not invoked for this collection');
        }

        if ($storedRequest->isNotification()) {
            $this->hashedResponses[spl_object_hash($request)] = new JsonRpcNotificationResponse();

            return $this->hashedResponses[spl_object_hash($request)];
        }

        if (!array_key_exists($storedRequest->getId(), $this->responses)) {
            throw JsonRpcProtocolException::requestSendButNotResponded($storedRequest);
        }

        $this->hashedResponses[spl_object_hash($request)] = $this->responses[$storedRequest->getId()];

        return $this->hashedResponses[spl_object_hash($request)];
    }

    private function sync()
    {
        if (200 !== $this->response->getStatus()) {
            throw new RemoteCallFailedException();
        }

        $data = (string)$this->response->getContent();

        // Null (empty response) is expected if only notifications were sent
        $rawResponses = [];

        if ('' !== $data) {
            $rawResponses = json_decode($data, false);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ResponseParseException::notAJsonResponse();
            }
        }

        if (!is_array($rawResponses) && $rawResponses instanceof \stdClass) {
            $rawResponses = [$rawResponses];
        }

        $this->responses = [];
        foreach ($rawResponses as $rawResponse) {
            try {
                $response = new SyncResponse($rawResponse);
            } catch (ResponseParseException $exception) {
                //todo: logging??? (@scaytrase)
                continue;
            }

            $this->responses[$response->getId()] = $response;
        }
    }
}
