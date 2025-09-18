<?php

namespace Drupal\kdb_cludo\EventSubscriber;

use Drupal\Core\Url;
use Drupal\kdb_cludo\Services\CludoProfileService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Handles redirecting to relevant Cludo search pages, when necessary.
 *
 * The module allows editors to enable/disable Cludo search pages. When they
 * are enabled, we want the core search pages to redirect.
 *
 * @package Drupal\kdb_cludo\EventSubscriber
 */
class SearchPageRedirects implements EventSubscriberInterface {

  public function __construct(private CludoProfileService $cludoProfileService) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['detectRedirectType', 20]];
  }

  /**
   * Detecting which type of redirect should happen, if any.
   */
  public function detectRedirectType(RequestEvent $event): void {
    $request = $event->getRequest();
    $routeName = $request->attributes->get('_route');
    $profiles = $this->cludoProfileService->getProfiles();
    $redirectUrl = NULL;

    foreach ($profiles as $profile) {
      if (!in_array($routeName, [$profile->cludoRouteName, $profile->viewRouteName])) {
        continue;
      }

      $enabled = $profile->getEnabled();

      if ($routeName === $profile->cludoRouteName && !$enabled) {
        $redirectUrl = $profile->getViewUrl();

        // If the Cludo page is disabled, and we have no redirect path,
        // we need to deny access.
        if (!$redirectUrl) {
          throw new AccessDeniedHttpException();
        }

        break;
      }

      if ($routeName === $profile->viewRouteName && $enabled) {
        $redirectUrl = $profile->getCludoUrl();
      }
    }

    if ($redirectUrl instanceof Url) {
      $event->setResponse(new RedirectResponse($redirectUrl->toString()));
    }
  }

}
