<?php

namespace Drupal\kdb_cludo\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
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
   * {@inheritdoc}
   */
  public function __construct(
    protected LoggerInterface $logger,
    protected CludoProfileService $profileService,
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $client,
  ) {
    $this->config = $this->configFactory->get(CludoSettingsForm::CONFIG_SETTINGS_KEY);
    $cludoProfile = $this->profileService->getProfileByValue('main');

    if ($cludoProfile instanceof CludoProfile) {
      $this->cludoProfile = $cludoProfile;
    }
    else {
      $this->logger->error('Cludo Profile "main" not found.');
      throw new \InvalidArgumentException('Supplied cludoProfile is not valid.');
    }
  }

  /**
   * Setting the context of a profile.
   */
  public function setProfile(CludoProfile|string $cludoProfile): void {
    if (is_string($cludoProfile)) {
      $cludoProfile = $this->profileService->getProfileByValue($cludoProfile);
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
    $key = base64_encode($this->config->get('customer_id') . ':' . $this->config->get('api_key'));

    return $this->client->request(
      method: 'POST',
      uri: $url,
      options: [
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => "Basic $key",
        ],
        'json' => $body,
      ]
    );

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

}
