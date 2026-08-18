<?php

namespace Drupal\kdb_cludo\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\kdb_cludo\CludoProfile;
use Drupal\kdb_cludo\Form\CludoSettingsForm;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use function Safe\json_decode;
use function Safe\json_encode;

/**
 * Service for calling the Cludo API.
 *
 * Requires setting up the API key in GeneralSettings.
 */
class CludoApiService {

  /**
   * The config, saved through CludoSettingsForm.
   */
  private ImmutableConfig $config;

  /**
   * The selected Cludo profile, set through setProfile, defaults to main.
   */
  protected CludoProfile $cludoProfile;

  /**
   * Tells if we have the secrets available to actually call the API.
   */
  protected bool $isAvailable = FALSE;

  /**
   * The authentication key, used when calling the API.
   */
  protected ?string $authKey;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected LoggerInterface $logger,
    protected CludoProfileService $profileService,
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $client,
  ) {
    $this->config = $this->configFactory->get(CludoSettingsForm::CONFIG_SETTINGS_KEY);

    $profile = $this->profileService->getProfile('main');
    if ($profile) {
      $this->cludoProfile = $profile;
    }

    $customerId = $this->config->get('customer_id');
    $apiKey = $this->config->get('api_key');

    if ($customerId && $apiKey) {
      $this->isAvailable = TRUE;
      $this->authKey = base64_encode($this->config->get('customer_id') . ':' . $this->config->get('api_key'));
    }
  }

  /**
   * Pre-checks if we have what we need to call the API.
   */
  public function isAvailable(): bool {
    return $this->isAvailable;
  }

  /**
   * Getting the field definitions for content. We need this as part of install.
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition[]
   *   The field definitions.
   */
  public static function getFieldDefinitions(): array {
    $fields = [];

    $fields['kdb_cludo_english'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('English content', [], ['context' => 'kdb_cludo']))
      ->setDescription(t('Mark if this content is english language. This makes sure it gets indexed correctly in searches.', [], ['context' => 'BNF']))
      ->setDisplayOptions('form', [
        'type' => 'checkbox',
        'weight' => -99,
      ]);

    return $fields;
  }

  /**
   * Setting the context of a profile.
   */
  public function setProfile(CludoProfile|string $cludoProfile): void {
    if (is_string($cludoProfile)) {
      $cludoProfile = $this->profileService->getProfile($cludoProfile);
    }

    if ($cludoProfile instanceof CludoProfile) {
      $this->cludoProfile = $cludoProfile;
    }
    else {
      throw new \InvalidArgumentException('Supplied cludoProfile is not valid.');
    }
  }

  /**
   * Calling the API.
   *
   * @param string $url
   *   The (absolute) URL you want to call.
   * @param array<mixed> $body
   *   The JSON request body.
   */
  private function callApi(string $url, array $body): ResponseInterface {
    if (!$this->isAvailable) {
      $this->logger->error('Cludo API not available - please make sure customerId and apiKey has been set.');
      throw new \RuntimeException('Cludo API not available');
    }

    try {
      return $this->client->request(
        method: 'POST',
        uri: $url,
        options: [
          'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => "Basic {$this->authKey}",
          ],
          'json' => $body,
        ]
      );
    }
    catch (\Exception $e) {
      $this->logger->error('Could not call Cludo API (@url): @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);

      throw $e;
    }
  }

  /**
   * Querying a search with Cludo, with the select CludoProfile.
   *
   * @param array<mixed> $body
   *   The JSON request body.
   *
   * @return array<mixed>
   *   The decoded response body.
   */
  public function callSearch(array $body): array {
    $customerId = $this->config->get('customer_id');
    $engineId = $this->cludoProfile->cludoEngineId;
    $url = "https://api.cludo.com/api/v3/$customerId/$engineId/search";

    $response = $this->callApi($url, $body);

    return json_decode($response->getBody()->getContents(), TRUE);
  }

  /**
   * Finding the total results a Cludo search query returns.
   *
   * @param array<mixed> $body
   *   The JSON request body.
   */
  public function getTotalResults(array $body): ?int {
    $results = $this->callSearch($body);
    return $results['TotalDocument'] ?? NULL;
  }

  /**
   * Tells if the editor has turned on pushing of URLs to Cludo at all.
   */
  public function isUrlPushingEnabled(): bool {
    return !empty($this->config->get('enable_url_pushing'));
  }

  /**
   * Finding the Cludo crawler that an entity belongs to.
   *
   * English content lives in a crawler of its own, so it gets indexed with
   * the right language analysis.
   *
   * @return string|null
   *   The crawler ID, or NULL if it has not been configured.
   */
  public function getCrawlerId(FieldableEntityInterface $entity): ?string {
    $english = (
      $entity->hasField('kdb_cludo_english') &&
      !empty($entity->get('kdb_cludo_english')->getString())
    );

    $crawlerId = ($english) ?
      $this->config->get('crawler_id_english') :
      $this->config->get('crawler_id');

    if (!$crawlerId) {
      $this->logger->warning('Could not push: Crawler ID not set.');
      return NULL;
    }

    return (string) $crawlerId;
  }

  /**
   * Telling Cludo to index the entity, if URL pushing is enabled.
   */
  public function addEntityData(FieldableEntityInterface $entity): bool {
    return $this->pushEntityData($entity, FALSE);
  }

  /**
   * Telling Cludo to un-index the entity, if URL pushing is enabled.
   */
  public function removeEntityData(FieldableEntityInterface $entity): bool {
    return $this->pushEntityData($entity, TRUE);
  }

  /**
   * Pushing a single entity to Cludo, right away.
   *
   * Notice that this blocks the current request until Cludo has answered.
   * Anything that may touch more than a handful of entities - update hooks,
   * bulk operations, migrations - should go through CludoPushQueue instead.
   *
   * @see \Drupal\kdb_cludo\Services\CludoPushQueue
   */
  public function pushEntityData(FieldableEntityInterface $entity, bool $delete = FALSE): bool {
    if (!$this->isUrlPushingEnabled()) {
      return FALSE;
    }

    $crawlerId = $this->getCrawlerId($entity);

    if (!$crawlerId) {
      return FALSE;
    }

    $entityUrl = $entity->toUrl()->setAbsolute()->toString();

    return $this->pushUrls([$entityUrl], $crawlerId, $delete);
  }

  /**
   * Calling Cludo API, when we want to add/delete indexed content.
   *
   * Cludo accepts - and prefers - several URLs in the same request. Pushing
   * them one by one is what makes them answer 429 Too Many Requests.
   *
   * @param string[] $urls
   *   The absolute URLs to index (or un-index).
   * @param string $crawlerId
   *   The Cludo crawler the URLs belong to.
   * @param bool $delete
   *   Whether to un-index the URLs, rather than index them.
   */
  public function pushUrls(array $urls, string $crawlerId, bool $delete = FALSE): bool {
    $urls = array_values(array_unique($urls));

    if (empty($urls)) {
      return FALSE;
    }

    if ($delete) {
      $endpoint = 'delete';

      // Cludo expects an array of objects, each mapping a URL to its type.
      $payload = array_map(
        fn (string $url): array => [$url => 'PageContent'],
        $urls
      );
    }
    else {
      $endpoint = 'pushurls';

      $payload = $urls;
    }

    $customerId = $this->config->get('customer_id');
    $url = "https://api.cludo.com/api/v3/$customerId/content/$crawlerId/$endpoint";

    $response = $this->callApi($url, $payload);

    // Cludo normally answers 200, but any 2xx means the request was
    // accepted. Guzzle throws on 4xx/5xx before we get here.
    $statusCode = $response->getStatusCode();
    $responseOK = ($statusCode >= 200 && $statusCode < 300);
    $message = $response->getBody()->getContents();

    if (!$responseOK) {
      $this->logger->error('Cludo URL pushing failed. Response: @message', [
        '@message' => $message,
      ]);

      return FALSE;
    }

    $this->logger->info('Successfully requested Cludo to @endpoint @count URL(s) on crawler @crawlerId. Payload: @payload | Response: @response', [
      '@endpoint' => $endpoint,
      '@count' => count($urls),
      '@crawlerId' => $crawlerId,
      '@payload' => json_encode($payload),
      '@response' => $message,
    ]);

    return TRUE;
  }

}
