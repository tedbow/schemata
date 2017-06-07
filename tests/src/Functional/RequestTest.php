<?php

namespace Drupal\Tests\schemata\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests requests schemata routes.
 *
 * @group Schemata
 */
class RequestTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'serialization',
    'hal',
    'schemata',
    'schemata_json_schema',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    if (!NodeType::load('camelids')) {
      // Create a "Camelids" node type.
      NodeType::create([
        'name' => 'Camelids',
        'type' => 'camelids',
      ])->save();
    }

    // Create a "Camelids" vocabulary.
    $vocabulary = Vocabulary::create([
      'name' => 'Camelids',
      'vid' => 'camelids',
    ]);
    $vocabulary->save();

    $entity_types = ['node', 'taxonomy_term'];
    foreach ($entity_types as $entity_type) {
      // Add access-protected field.
      FieldStorageConfig::create([
        'entity_type' => $entity_type,
        'field_name' => 'field_test_' . $entity_type,
        'type' => 'text',
      ])
        ->setCardinality(1)
        ->save();
      FieldConfig::create([
        'entity_type' => $entity_type,
        'field_name' => 'field_test_' . $entity_type,
        'bundle' => 'camelids',
      ])
        ->setLabel('Test field')
        ->setTranslatable(FALSE)
        ->save();
    }
    $this->container->get('router.builder')->rebuild();
    $this->drupalLogin($this->drupalCreateUser(['access schemata data models']));
  }

  /**
   * Tests schemata requests.
   */
  public function testRequests() {
    /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
    $entity_type_manager = $this->container->get('entity_type.manager');

    foreach (['json', 'hal_json'] as $format) {
      $options = [
        'query' => [
          '_format' => 'schema_json',
          '_describes' => $format,
        ],
      ];
      foreach ($entity_type_manager->getDefinitions() as $entity_type_id => $entity_type) {
        // @todo Check for all entity types https://www.drupal.org/node/2870904.
        if (!$entity_type->getBundleEntityType()) {
          // $response = $this->request('GET', Url::fromRoute("schemata.$entity_type_id", [], $options), []);
          // $this->checkExpectedResponse($response, $format, $entity_type_id);
        }
        if ($bundle_type = $entity_type->getBundleEntityType()) {
          $bundles = $entity_type_manager->getStorage($bundle_type)->loadMultiple();
          foreach ($bundles as $bundle) {
            $response = $this->request('GET', Url::fromRoute("schemata.$entity_type_id:{$bundle->id()}", [], $options), []);
            $this->checkExpectedResponse($response, $format, $entity_type_id, $bundle->id());
          }
        }
      }
    }

  }

  /**
   * Performs a HTTP request. Wraps the Guzzle HTTP client.
   *
   * Why wrap the Guzzle HTTP client? Because we want to keep the actual test
   * code as simple as possible, and hence not require them to specify the
   * 'http_errors = FALSE' request option, nor do we want them to have to
   * convert Drupal Url objects to strings.
   *
   * We also don't want to follow redirects automatically, to ensure these tests
   * are able to detect when redirects are added or removed.
   *
   * @param string $method
   *   HTTP method.
   * @param \Drupal\Core\Url $url
   *   URL to request.
   * @param array $request_options
   *   Request options to apply.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   *
   * @see \GuzzleHttp\ClientInterface::request()
   */
  protected function request($method, Url $url, array $request_options) {
    $request_options[RequestOptions::HTTP_ERRORS] = FALSE;
    $request_options[RequestOptions::ALLOW_REDIRECTS] = FALSE;
    $request_options = $this->decorateWithCookies($request_options);
    $client = $this->getSession()->getDriver()->getClient()->getClient();
    return $client->request($method, $url->setAbsolute(TRUE)->toString(), $request_options);
  }

  /**
   * Adds the cookies from the client to the request options.
   *
   * @param array $request_options
   *   The request options.
   *
   * @return array
   *   Request options updated with the client cookies if present.
   */
  protected function decorateWithCookies(array $request_options) {
    $session = $this->getSession();
    $driver = $session->getDriver();
    if ($driver instanceof BrowserKitDriver) {
      $client = $driver->getClient();
      foreach ($client->getCookieJar()->all() as $cookie) {
        if (isset($request_options[RequestOptions::HEADERS]['Cookie'])) {
          $request_options[RequestOptions::HEADERS]['Cookie'] .= '; ' . $cookie->getName() . '=' . $cookie->getValue();
        }
        else {
          $request_options[RequestOptions::HEADERS]['Cookie'] = $cookie->getName() . '=' . $cookie->getValue();
        }
      }
    }
    else {
      $this->assertTrue(FALSE, "NOT BrowserKitDriver");
    }
    return $request_options;
  }

  /**
   * Check the expected response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response from the test request.
   * @param string $format
   *   The described format.
   * @param string $entity_type_id
   *   Then entity type.
   * @param string|null $bundle_name
   *   The bundle name or NULL.
   */
  protected function checkExpectedResponse(ResponseInterface $response, $format, $entity_type_id, $bundle_name = NULL) {
    $this->assertEquals('200', $response->getStatusCode());
    if (in_array($entity_type_id, ['node', 'taxonomy_term'])) {
      $contents = $response->getBody()->getContents();
      $this->assertFalse(empty($contents), "Content not empty for $format, $entity_type_id, $bundle_name");
      $file_name = __DIR__ . "/../../expectations/";
      if ($bundle_name) {
        $file_name .= "$entity_type_id.$bundle_name.$format.json";
      }
      else {
        $file_name .= "$entity_type_id.$format.json";
      }
      // Compare decoded json to so that failure will indicate which element is
      // incorrect.
      $expected = json_decode(file_get_contents($file_name), TRUE);
      $expected['id'] = str_replace('{base_url}', $this->baseUrl, $expected['id']);
      $decoded_response = json_decode($contents, TRUE);

      $this->assertEquals($expected, $decoded_response, "The response did not match expected file: $file_name");
    }
  }

}
