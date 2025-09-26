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
   * Calling Cludo API, when we want to add/delete indexed content.
   */
  protected function pushEntityData(FieldableEntityInterface $entity, bool $delete = FALSE): bool {
    $enabled = $this->config->get('enable_url_pushing');

    if (empty($enabled)) {
      $this->logger->info('Cludo URL pushing is disabled - skipping pushing node.');
      return FALSE;
    }

    $english = (
      $entity->hasField('kdb_cludo_english') &&
      !empty($entity->get('kdb_cludo_english')->getString())
    );

    $crawlerId = $this->config->get('crawler_id');
    $crawlerIdEnglish = $this->config->get('crawler_id_english');

    $crawlerId = ($english) ? $crawlerIdEnglish : $crawlerId;

    if (!$crawlerId) {
      $this->logger->warning('Could not push: Crawler ID not set.');
      return FALSE;
    }

    $entityUrl = $entity->toUrl()->setAbsolute()->toString();

    if ($delete) {
      $endpoint = 'delete';

      $payload = [[
        $entityUrl => 'PageContent',
      ],
      ];
    }
    else {
      $endpoint = 'pushurls';

      $payload = [$entityUrl];
    }

    $customerId = $this->config->get('customer_id');
    $url = "https://api.cludo.com/api/v3/$customerId/content/$crawlerId/$endpoint";

    $response = $this->callApi($url, $payload);

    $responseOK = ($response->getStatusCode() === 200);

    if (!$responseOK) {
      $this->logger->error('Cludo URL pushing failed. Response: @message', [
        '@message' => $response->getBody()->getContents(),
      ]);
    }

    return ($responseOK);
  }

}
