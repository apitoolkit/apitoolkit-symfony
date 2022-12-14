<?php

namespace App\EventSubscriber;

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
    $credentials = $this->cachePool->get("apitoolkitInstance", function (ItemInterface $item) {
      $item->expiresAfter(2000);
      $apiKey = $this->paramBag->get("apitoolkit.apiKey");
      $rootURL = $this->paramBag->get("apitoolkit.rootURL");
      return Self::credentials($rootURL, $apiKey);
    });

    $payload = Self::payload($event->getRequest(), $event->getResponse(), $event->getRequest()->get("APIToolkitStartTime"), "thisprojectId");
    $this->publishMessage($payload, $credentials);
  }

  public static function payload(Request $request, $response, $startTime, $projectId)
  {
    $since = hrtime(true) - $startTime;
    $query_params = [];
    foreach ($request->query->all() as $k => $v) {
      $query_params[$k] = [$v];
    }

    $request_headers = $request->headers->all(); //  request->header();
    $response_headers = $response->headers;

    $paramsList = $request->query->all();
    $routepath = $request->attributes->get('_route');
    $path_params = $request->attributes->get('_route_params');

    $timestamp = new DateTime();
    $timestamp = $timestamp->format("c");
    $host = $request->getHttpHost();
    $referer = $request->headers->get("referer");

    $payload = (object)[
      "duration" => round($since),
      "host" => $host,
      "method" => $request->getMethod(),
      "project_id" => $projectId,
      "proto_major" => 1,
      "proto_minor" => 1,
      "query_params" => $query_params,
      "path_params" => $path_params,
      "raw_url" => $request->getRequestUri(),
      "referer" => $referer ?? "",
      "request_body" => base64_encode($request->getContent()),
      "request_headers" => $request_headers,
      "response_body" => base64_encode($response->getContent()),
      "response_headers" => $response_headers->all(),
      "sdk_type" => "PhpSymfony",
      "status_code" => $response->getStatusCode(),
      "timestamp" => $timestamp,
      "url_path" => $routepath,
    ];
    return $payload;
  }

  public function publishMessage($payload, $credentials)
  {
    if (!$credentials) return;

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

    return $response;
  }
}
