<?php

namespace APIToolkit\EventSubscriber;

use PHPUnit\Framework\TestCase;
use APIToolkit\EventSubscriber;

class APIToolkitServiceTest extends TestCase
{
  /** @var EventDispatcher **/
  private $dispatcher;

  private $testJSON = '
{ "store": {
    "book": [
      { "category": "reference",
        "author": "Nigel Rees",
        "title": "Sayings of the Century",
        "price": 8.95,
        "available": true
      },
      { "category": "fiction",
        "author": "Evelyn Waugh",
        "title": "Sword of Honour",
        "price": 12.99,
        "available": false
      }
    ],
    "bicycle": {
      "color": "red",
      "price": 19.95,
      "available": true
    }
  },
  "authors": [
    "Nigel Rees",
    "Herman Melville",
    "J. R. R. Tolkien"
  ]
}
';

  public function setUp(): void
  {
    // $this->dispatcher = new EventDispatcher();
  }
  public function test_empty_redected_json_same_as_input(): void
  {
    $svc = new APIToolkitService("");
    $redactedJSON = $svc->redactJSONFields([], $this->testJSON);
    $this->assertJsonStringEqualsJsonString($this->testJSON, $redactedJSON);
  }

  public function test_redacted_field(): void
  {
    $testJSON = '
    { "store": {
        "book": [
          { "category": "reference",
            "author": "Nigel Rees",
            "title": "Sayings of the Century",
            "price": 8.95,
            "available": true
          },
          { "category": "fiction",
            "author": "Evelyn Waugh",
            "title": "Sword of Honour",
            "price": 12.99,
            "available": false
          }
        ]
      }
    }
    ';
    $expectedJSON = '{ "store": {"book": "[CLIENT_REDACTED]"}}';
    $svc = new APIToolkitService("");
    $redactedJSON = $svc->redactJSONFields(['$.store.book'], $testJSON);
    $this->assertJsonStringEqualsJsonString($expectedJSON, $redactedJSON);
  }

  public function test_redacted_array_subfield(): void
  {
    $testJSON = '
    { "store": {
        "book": [
          { "category": "reference",
            "author": "Nigel Rees"
          },
          { "category": "fiction",
            "author": "Evelyn Waugh"
          }
        ]
      }
    }
    ';
    $expectedJSON = '
    { "store": {
        "book": [
          { "category": "[CLIENT_REDACTED]",
            "author": "Nigel Rees"
          },
          { "category": "[CLIENT_REDACTED]",
            "author": "Evelyn Waugh"
          }
        ]
      }
    }
    ';
    $svc = new APIToolkitService("");
    $redactedJSON = $svc->redactJSONFields(['$.store.book[*].category'], $testJSON);
    $this->assertJsonStringEqualsJsonString($expectedJSON, $redactedJSON);
  }

  public function test_return_invalid_json_as_is(): void
  {
    $testJSON = 'invalid_json';
    $expectedJSON = 'invalid_json';
    $svc = new APIToolkitService("");
    $redactedJSON = $svc->redactJSONFields(['$.store.book[*].category'], $testJSON);
    $this->assertEquals($expectedJSON, $redactedJSON);
  }
}
