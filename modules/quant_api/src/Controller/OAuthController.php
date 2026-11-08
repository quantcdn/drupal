<?php

namespace Drupal\quant_api\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\quant_api\Credentials;
use Drupal\quant_api\OAuth\AuthService;
use Drupal\quant_api\OAuth\OAuthTokenStore;
use Drupal\quant_api\OAuth\PortalClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Starts the "Log in to Quant" login, takes the callback, logs out.
 */
class OAuthController extends ControllerBase {

  /**
   * The session key of the login in progress.
   */
  public const SESSION_KEY = 'quant_api_oauth';

  /**
   * How long the dashboard approval may take, in seconds.
   */
  protected const STATE_TTL = 600;

  public function __construct(
    protected AuthService $auth,
    protected PortalClient $portal,
    protected OAuthTokenStore $tokenStore,
    protected Credentials $credentials,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('quant_api.oauth'),
      $container->get('quant_api.portal'),
      $container->get('quant_api.oauth_store'),
      $container->get('quant_api.credentials'),
      $container->get('config.factory'),
    );
  }

  /**
   * Sends the editor to the dashboard to approve this site.
   */
  public function connect(Request $request) {
    if ($this->credentials->hasDomainOverrides()) {
      $this->messenger()->addError($this->t('This site publishes several domains to several projects. Log in to Quant supports one project per site, so connect each domain with a project token.'));
      return $this->redirectToSettings();
    }
    $state = bin2hex(random_bytes(16));
    $verifier = $this->auth->generatePkceVerifier();
    $redirect_uri = self::callbackUrl();
    $request->getSession()->set(self::SESSION_KEY, [
      'state' => $state,
      'verifier' => $verifier,
      'uid' => (int) $this->currentUser()->id(),
      'created' => time(),
    ]);
    $url = $this->auth->getAuthorizationUrl($state, $redirect_uri, $this->auth->computePkceChallenge($verifier));
    $response = new TrustedRedirectResponse($url);
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    return $response;
  }

  /**
   * Takes the dashboard's answer and asks for the project.
   */
  public function callback(Request $request) {
    $session = $request->getSession();
    $login = $session->get(self::SESSION_KEY);
    // One use only, whatever the outcome.
    $session->remove(self::SESSION_KEY);

    $error = $this->validateCallback($request, $login);
    if ($error !== NULL) {
      $this->messenger()->addError($error);
      return $this->redirectToSettings();
    }

    $tokens = $this->auth->exchangeCode((string) $request->query->get('code'), self::callbackUrl(), $login['verifier']);
    if ($tokens === NULL) {
      $this->messenger()->addError($this->t('Quant did not accept the login. Try again. If it fails again, connect with a project token.'));
      return $this->redirectToSettings();
    }

    $user = is_array($tokens['user'] ?? NULL) ? $tokens['user'] : $this->portal->getUser($tokens['access_token']);
    unset($tokens['user']);
    $this->tokenStore->savePendingLogin((int) $this->currentUser()->id(), [
      'tokens' => $tokens,
      'user' => ['name' => (string) ($user['name'] ?? ''), 'email' => (string) ($user['email'] ?? '')],
      'organizations' => self::organizations($user['organizations'] ?? []),
    ]);
    return new RedirectResponse(Url::fromRoute('quant_api.oauth_project')->toString());
  }

  /**
   * Deletes the local connection and goes back to token connect.
   */
  public function disconnect() {
    $this->auth->disconnect();
    $this->configFactory->getEditable('quant_api.settings')
      ->set('auth_method', Credentials::METHOD_TOKEN)
      ->save();
    $this->messenger()->addStatus($this->t('This site is logged out of Quant. Content is not published until you log in again or connect with a project token.'));
    return $this->redirectToSettings();
  }

  /**
   * The error message of a bad callback, or NULL when it is valid.
   */
  protected function validateCallback(Request $request, mixed $login): ?string {
    if ($request->query->has('error')) {
      return (string) $this->t('The login was cancelled in the Quant dashboard. Nothing changed.');
    }
    if (!is_array($login) || empty($login['state']) || !is_string($request->query->get('state'))) {
      return (string) $this->t('The login expired or was started in another browser. Start it again.');
    }
    $valid = hash_equals($login['state'], $request->query->get('state'))
      && (int) $login['uid'] === (int) $this->currentUser()->id()
      && (int) $login['created'] >= time() - self::STATE_TTL
      && is_string($request->query->get('code')) && $request->query->get('code') !== '';
    return $valid ? NULL : (string) $this->t('The login expired or was started in another browser. Start it again.');
  }

  /**
   * The granted organisations, machine name => name, safe names only.
   */
  public static function organizations(mixed $organizations): array {
    $result = [];
    foreach (is_array($organizations) ? $organizations : [] as $org) {
      $machine = is_array($org) ? (string) ($org['machine_name'] ?? '') : '';
      if (PortalClient::isMachineName($machine)) {
        $result[$machine] = (string) ($org['name'] ?? $machine);
      }
    }
    return $result;
  }

  /**
   * The absolute callback URL that the dashboard redirects to.
   */
  public static function callbackUrl(): string {
    return Url::fromRoute('quant_api.oauth_callback', [], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
  }

  /**
   * A redirect to the Quant API settings page.
   */
  protected function redirectToSettings(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('quant_api.settings_form')->toString());
  }

}
