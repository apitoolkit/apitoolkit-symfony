# APIToolKit Symfony SDK

A PHP/Symfony SDK Wrapper for APIToolkit. It monitors incoming traffic, gathers the requests and sends the request to
the apitoolkit. The SDK simply registers and event listener and consumes this information via the events.

## Installation

Run the following command to install the package:

composer require apitoolkit/apitoolkit-symfony

add the listener to your service.yaml

```yaml
services:

  APIToolkit\EventSubscriber\APIToolkitService:
    arguments:
      - '%env(APITOOLKIT_KEY)%'
      - 'https://app.apitoolkit.io' 
```

Set the APITOOLKIT_KEY environment variable to your API key in you .env file, should look like this:

```
APITOOLKIT_KEY=xxxxxx-xxxxx-xxxxxx-xxxxxx-xxxxxx
```
