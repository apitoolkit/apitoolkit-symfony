<?php

namespace APIToolkit\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;
use DateTime;
use Google\Cloud\PubSub\PubSubClient;
use Symfony\Component\HttpFoundation\Request;

class APIToolkitService implements EventSubscriberInterface
{
  private $cachePool;
  public function __construct(private ParameterBagInterface $paramBag)
  {
    $this->cachePool = new FilesystemAdapter();
  }

  public static function getSubscribedEvents()
  {
    return [
      KernelEvents::REQUEST => [
        ['onKernelRequestPre', 10],
      ],
      KernelEvents::RESPONSE => [
        ['onKernelResponsePre', 10],
      ],
    ];
  }

  public function onKernelRequestPre(RequestEvent $event)
  {
    $event->getRequest()->request->add(["APIToolkitStartTime" => hrtime(true)]);
  }

  public function onKernelResponsePre(ResponseEvent $event)
  {
    $this->cachePool->delete("apitoolkit.credentials");
    $credentials = $this->cachePool->get("apitoolkit.credentials", function (ItemInterface $item) {
      $apiKey = $this->paramBag->get("apitoolkit.apiKey");
      $rootURL = $this->paramBag->get("apitoolkit.rootURL");
      $credentials = Self::credentials($rootURL, $apiKey);
      if (!$credentials) {
        error_log("unable to load APIToolkit credentials");
        $item->expiresAfter(0);
        return;
      }
      $item->expiresAfter(2000);
      return $credentials;
    });
    // No credentials available, so there's no need continuing with apitoolkit logging.
    if (!$credentials) return;

    $payload = Self::payload($event->getRequest(), $event->getResponse(), $event->getRequest()->get("APIToolkitStartTime"), $credentials["projectId"]);
    $this->publishMessage($payload, $credentials);
  }

  // payload static method deterministically converts a request, response object, a start time and a projectId 
  // into a pauload json object which APIToolkit server is able to interprete.
  public static function payload(Request $request, $response, $startTime, $projectId)
  {
    $query_params = [];
    foreach ($request->query->all() as $k => $v) {
      $query_params[$k] = [$v];
    }

    $payload = (object)[
      "duration" => round(hrtime(true) - $startTime),
      "host" => $request->getHttpHost(),
      "method" => $request->getMethod(),
      "project_id" => $projectId,
      "proto_major" => 1,
      "proto_minor" => 1,
      "query_params" => $query_params,
      "path_params" => $request->attributes->get('_route_params'),
      "raw_url" => $request->getRequestUri(),
      "referer" => $request->headers->get("referer") ?? "",
      "request_body" => base64_encode($request->getContent()),
      "request_headers" => $request->headers->all(),
      "response_body" => base64_encode($response->getContent()),
      "response_headers" => $response->headers->all(),
      "sdk_type" => "PhpSymfony",
      "status_code" => $response->getStatusCode(),
      "timestamp" => (new DateTime())->format("c"),
      "url_path" => $request->attributes->get('_route'),
    ];
    return $payload;
  }

  // publishMessage leverages the credentials object to build a google pubsub topic 
  // and then use that pubsub topic to publish a message into the APIToolkit pubsub queue.
  public function publishMessage($payload, $credentials)
  {
    $pubsubClient = new PubSubClient([
      "keyFile" => $credentials["pubsubKeyFile"]
    ]);
    $topic = $pubsubClient->topic($credentials["topic"]);
    if (!$topic) return;

    $topic->publish([
      "data" => json_encode($payload, JSON_UNESCAPED_SLASHES)
    ]);
  }

  public static function credentials($url, $api_key)
  {
    $url = $url . "/api/client_metadata";

    $curlInit = curl_init($url);
    curl_setopt($curlInit, CURLOPT_URL, $url);
    curl_setopt($curlInit, CURLOPT_RETURNTRANSFER, true);

    $headers = array(
      "Authorization: Bearer $api_key",
    );

    curl_setopt($curlInit, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curlInit, CURLOPT_SSL_VERIFYPEER, false);

    $curlResponse = curl_exec($curlInit);

    $response = json_decode($curlResponse, 1);
    if ($curlResponse == false) {
      curl_error($curlInit);
    }

    curl_close($curlInit);

    if (!$response) {
      return $response;
    }

    return [
      "projectId" => $response["project_id"],
      "pubsubKeyFile" => $response["pubsub_push_service_account"],
      "topic" => $response["topic_id"]
    ];
  }
}
