<?php

namespace Drupal\quant_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\quant_api\Controller\OAuthController;
use Drupal\quant_api\Credentials;
use Drupal\quant_api\EndpointValidator;
use Drupal\quant_api\OAuth\AuthService;
use Drupal\quant_api\OAuth\OAuthTokenStore;
use Drupal\quant_api\OAuth\Platform;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Quant API settings: the platform and the connection.
 *
 * Two ways to connect:
 * - "Log in to Quant": the editor approves the site in the Quant dashboard
 *   and picks a project. The module stores and refreshes the tokens.
 * - "Connect with a project token": the organisation, project and token
 *   from the dashboard, typed in or set in settings.php (CI, multi-domain).
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Session flag: the connection was just reported, so no second message.
   */
  public const REPORTED = 'quant_api.connection_reported';

  const SETTINGS = 'quant_api.settings';

  /**
   * The Quant API client.
   *
   * @var \Drupal\quant_api\Client\QuantClientInterface
   */
  protected $client;

  /**
   * The credentials resolver.
   *
   * @var \Drupal\quant_api\Credentials
   */
  protected $credentials;

  /**
   * The OAuth service.
   *
   * @var \Drupal\quant_api\OAuth\AuthService
   */
  protected $auth;

  /**
   * The OAuth token store.
   *
   * @var \Drupal\quant_api\OAuth\OAuthTokenStore
   */
  protected $tokenStore;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->client = $container->get('quant_api.client');
    $instance->credentials = $container->get('quant_api.credentials');
    $instance->auth = $container->get('quant_api.oauth');
    $instance->tokenStore = $container->get('quant_api.oauth_store');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_api_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames() {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::SETTINGS);
    // Only on a page view: a build during a submit runs before the submit
    // handler, so its message would show stale after the redirect.
    if ($this->getRequest()->isMethod('GET')) {
      $this->showStatus();
    }

    $form['platform'] = [
      '#type' => 'radios',
      '#title' => $this->t('Platform'),
      '#options' => [
        Platform::QUANTCDN => $this->t('QuantCDN'),
        Platform::QUANTGOV => $this->t('QuantGov (Australian government)'),
      ],
      '#default_value' => $this->defaultPlatform($config->get('platform'), (string) $config->get('api_endpoint')),
      '#description' => $this->t('Your Quant account is on one platform. QuantGov accounts sign in at dash.quantgov.cloud.'),
    ];

    $form['login'] = $this->buildLogin($config);
    $form['token'] = $this->buildTokenConnect($config);
    $form['advanced'] = $this->buildAdvanced($config);
    $this->showOverrides($form);

    return parent::buildForm($form, $form_state);
  }

  /**
   * The "Log in to Quant" section.
   */
  protected function buildLogin($config): array {
    $section = [
      '#type' => 'fieldset',
      '#title' => $this->t('Log in to Quant'),
    ];
    if ($this->credentials->usesOauth($config) && $this->tokenStore->isConnected()) {
      return $section + $this->buildConnected($config);
    }
    $section['help'] = [
      '#markup' => '<p>' . $this->t('Approve this site in the Quant dashboard and choose its project. You do not copy a token.') . '</p>',
    ];
    $multi_domain = $this->credentials->hasDomainOverrides();
    $section['login'] = [
      '#type' => 'submit',
      '#value' => $this->t('Log in to Quant'),
      '#submit' => ['::submitLogin'],
      '#limit_validation_errors' => [['platform'], ['api_endpoint']],
      '#disabled' => $multi_domain,
      '#button_type' => 'primary',
    ];
    if ($multi_domain) {
      $section['multi_domain'] = [
        '#markup' => '<p><strong>' . $this->t('This site publishes several domains to several projects. Log in to Quant supports one project per site for now, so connect each domain with a project token.') . '</strong></p>',
      ];
    }
    return $section;
  }

  /**
   * The status and the actions of a working OAuth connection.
   */
  protected function buildConnected($config): array {
    $user = (array) $this->tokenStore->get(OAuthTokenStore::USER);
    $items['status'] = [
      '#markup' => '<p>' . $this->t('Logged in as %user. This site publishes to the project %project in the organisation %org, and it can reach only that project.', [
        '%user' => ($user['email'] ?? '') ?: ($user['name'] ?? '-'),
        '%project' => $config->get('api_project'),
        '%org' => $config->get('api_account'),
      ]) . '</p>',
    ];
    // A connection that needs a new login is shown as the page message.
    $reason = $this->auth->getReconnectReason();
    if ($reason === AuthService::RECONNECT_OTHER_SITE) {
      $items['same_site'] = [
        '#type' => 'submit',
        '#value' => $this->t('This is the same site'),
        '#submit' => ['::submitSameSite'],
        '#limit_validation_errors' => [],
      ];
    }
    $items['change'] = [
      '#type' => 'link',
      '#title' => $reason === NULL ? $this->t('Change project') : $this->t('Log in to Quant again'),
      '#url' => Url::fromRoute('quant_api.oauth_connect'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];
    $items['logout'] = [
      '#type' => 'link',
      '#title' => $this->t('Log out of Quant'),
      '#url' => Url::fromRoute('quant_api.oauth_disconnect'),
      '#attributes' => ['class' => ['button', 'button--danger']],
    ];
    $items['change_help'] = [
      '#markup' => '<p>' . $this->t('Change project starts a new login. The current project stays connected until you choose the new one.') . '</p>',
    ];
    return $items;
  }

  /**
   * The message for a connection that needs a new login.
   */
  protected function reconnectMessage(string $reason) {
    return match ($reason) {
      AuthService::RECONNECT_OTHER_SITE => $this->t('This site looks like a copy of the connected site (for example a database copied from production), so it does not publish or refresh with the stored login. Log in to Quant on this copy. If it is the same site, confirm it below.'),
      AuthService::RECONNECT_REFUSED => $this->t('Quant refused to refresh the login. Content still publishes. Log in to Quant again to keep the connection current.'),
      default => $this->t('The login expired. Content still publishes. Log in to Quant again to keep the connection current.'),
    };
  }

  /**
   * The "Connect with a project token" section.
   */
  protected function buildTokenConnect($config): array {
    $oauth = $this->credentials->usesOauth($config);
    $section = [
      '#type' => 'details',
      '#title' => $this->t('Connect with a project token'),
      '#open' => !$oauth,
      '#description' => $oauth
        ? $this->t('Saving a token here logs this site out of Quant and uses the token instead.')
        : $this->t('For CI, multi-domain sites and existing sites. Copy the values from the Integrations page in the Quant dashboard.'),
    ];
    // @todo Switch from 'api_account' to 'api_organization'.
    $section['api_account'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Organization'),
      '#description' => $this->t('The API organization. This is shown on the <code>Integrations</code> page and with the account information.'),
      '#default_value' => $config->get('api_account'),
      '#parents' => ['api_account'],
    ];
    $section['api_project'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Project'),
      '#description' => $this->t('The API project. This is the <code>"API name"</code> shown on the <code>Projects</code> page and the <code>"Project"</code> shown on the <code>Integrations</code> page. Note, this value may be different than the human-readable project name.'),
      '#default_value' => $config->get('api_project'),
      '#parents' => ['api_project'],
    ];
    $section['api_token'] = [
      '#type' => 'password',
      '#title' => $this->t('API Token'),
      '#description' => $this->t('The API token. Use the clipboard icon in the dashboard to copy the token from the <code>Projects</code> or <code>Integrations</code> page. Treat it like a password. Leave it empty to keep the saved token.'),
      '#parents' => ['api_token'],
    ];
    return $section;
  }

  /**
   * The advanced section: a custom endpoint and TLS.
   */
  protected function buildAdvanced($config): array {
    $oauth = $this->credentials->usesOauth($config);
    $override = Credentials::endpointOverride() !== '';
    return [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
      'api_endpoint' => [
        '#type' => 'textfield',
        '#title' => $this->t('API Endpoint'),
        '#description' => $this->t('Leave the platform default unless Quant support gives you another endpoint. Use https and do not add <code>/v1</code>; the module adds it.'),
        '#default_value' => Credentials::redact($this->credentials->getEndpoint($config)) ?: Platform::apiEndpoint(Platform::QUANTCDN),
        '#parents' => ['api_endpoint'],
        // A login publishes only to the platform's own host, unless
        // settings.php names another endpoint.
        '#disabled' => $oauth || $override,
        '#field_suffix' => $override
          ? $this->t("Set in settings.php (\$settings['quant_api_endpoint_url']).")
          : ($oauth ? $this->t('Fixed to the platform while this site is logged in to Quant.') : NULL),
      ],
      'api_tls_disabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Disable TLS verification'),
        '#description' => $this->t('You can optionally disable TLS verification for all Quant API requests. This is <strong>not recommended</strong>, but may be necessary in some configurations. For example, old web servers may have issues validating modern TSL/SSL certificates.'),
        '#default_value' => $config->get('api_tls_disabled') ?? FALSE,
        '#parents' => ['api_tls_disabled'],
      ],
    ];
  }

  /**
   * Shows the connection status as a message.
   */
  protected function showStatus(): void {
    if ($this->credentials->usesOauth() && $this->credentials->isCopiedSite()) {
      $this->messenger()->addError($this->t('This site looks like a copy of the connected site (different: @components), so nothing is published. Log in to Quant again, or, if this is the same site (for example after a database move), click "This is the same site".', [
        '@components' => implode(', ', $this->credentials->copyDifferences()),
      ]));
      return;
    }
    if (!$this->credentials->hasToken()) {
      return;
    }
    $reason = $this->credentials->usesOauth() ? $this->auth->getReconnectReason() : NULL;
    if ($reason !== NULL) {
      $this->messenger()->addWarning($this->reconnectMessage($reason));
      return;
    }
    if ($this->client->ping()) {
      // After a project pick or a confirm, that message already said so.
      if ($this->getRequest()->getSession()->remove(self::REPORTED)) {
        return;
      }
      $this->messenger()->addStatus($this->t('Connected to the Quant project %project.', ['%project' => $this->client->getProject()]));
      return;
    }
    // The client has already said why when Quant could not be reached.
    if (!$this->client->lastCallUnreachable()) {
      $this->messenger()->addError($this->t('The Quant API did not accept the connection. Check the platform and the project, or log in to Quant again.'));
    }
  }

  /**
   * Marks the values that settings.php overrides.
   */
  protected function showOverrides(array &$form): void {
    $overrides = $this->client->getOverrides();
    foreach ($overrides as $key => $value) {
      if ($key === 'api_endpoint') {
        $value = Credentials::redact((string) $value);
      }
      $message = $key === 'api_token'
        ? $this->t('QuantAPI override: <code>api_token</code> has been overridden in the settings file.')
        : $this->t('QuantAPI override: <em>@key</em> has been overridden in the settings file with <em>@value</em>.', [
          '@key' => $key,
          '@value' => $value,
        ]);
      $this->messenger()->addWarning($message);
    }
    if (!empty($overrides['api_tls_disabled']) || $this->config(self::SETTINGS)->get('api_tls_disabled')) {
      $this->messenger()->addError($this->t('<strong>DANGER ZONE:</strong> TLS verification is disabled for Quant API connections. It is <strong>highly recommended</strong> that you update your server configuration to handle TLS rather than disabling TLS verification.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $this->validateEndpoint($form_state);
    if (in_array('::submitLogin', $form_state->getTriggeringElement()['#submit'] ?? [], TRUE)) {
      // The login button validates only the platform and the endpoint.
      return;
    }
    $this->validateTokenConnect($form_state);
    parent::validateForm($form, $form_state);
  }

  /**
   * Validates the endpoint (empty means the platform default).
   */
  protected function validateEndpoint(FormStateInterface $form_state): void {
    $endpoint = $this->resolveEndpoint($form_state);
    $messages = [
      'not_https' => $this->t('The API endpoint must be an https URL, for example https://api.quantcdn.io.'),
      'not_origin' => $this->t('The API endpoint must contain only the scheme and host, for example https://api.quantcdn.io.'),
      'has_v1' => $this->t('Remove /v1 from the API endpoint. The module adds it.'),
    ];
    $error = EndpointValidator::validate($endpoint);
    if ($error !== NULL) {
      $form_state->setErrorByName('api_endpoint', $messages[$error]);
    }
  }

  /**
   * Requires the organisation, project and token for token connect.
   */
  protected function validateTokenConnect(FormStateInterface $form_state): void {
    $config = $this->config(self::SETTINGS);
    $new_token = (string) $form_state->getValue('api_token') !== '';
    $token_connect = !$this->credentials->usesOauth($config) || $new_token;
    if (!$token_connect) {
      return;
    }
    foreach (['api_account' => $this->t('API Organization'), 'api_project' => $this->t('API Project')] as $key => $label) {
      if (trim((string) $form_state->getValue($key)) === '') {
        $form_state->setErrorByName($key, $this->t('@field is required to connect with a project token.', ['@field' => $label]));
      }
    }
    $has_saved = (string) $config->get('api_token') !== '' && !$this->credentials->usesOauth($config);
    if (!$new_token && !$has_saved && !$config->hasOverrides('api_token')) {
      $form_state->setErrorByName('api_token', $this->t('API Token is required to connect with a project token.'));
    }
  }

  /**
   * The endpoint to save: the platform default unless a custom one is set.
   *
   * A value that equals the default of any platform follows the chosen
   * platform, so switching the platform switches the endpoint.
   */
  protected function resolveEndpoint(FormStateInterface $form_state): string {
    if (Credentials::endpointOverride() !== '') {
      // settings.php decides; the stored value stays as it is.
      return (string) ($this->config(self::SETTINGS)->get('api_endpoint') ?: Platform::apiEndpoint(Platform::QUANTCDN));
    }
    $platform = (string) $form_state->getValue('platform');
    $platform = Platform::isKnown($platform) ? $platform : Platform::QUANTCDN;
    $endpoint = EndpointValidator::normalise($form_state->getValue('api_endpoint'));
    $defaults = array_map([Platform::class, 'apiEndpoint'], Platform::ids());
    return ($endpoint === '' || in_array($endpoint, $defaults, TRUE)) ? Platform::apiEndpoint($platform) : $endpoint;
  }

  /**
   * Confirms that this is the connected site, so it publishes again.
   */
  public function submitSameSite(array &$form, FormStateInterface $form_state) {
    $differences = $this->credentials->copyDifferences();
    $this->credentials->confirmSameSite();
    $this->logger('quant_api')->notice('Quant: @user confirmed that this is the connected site (was different: @components).', [
      '@user' => $this->currentUser()->getAccountName(),
      '@components' => implode(', ', $differences),
    ]);
    $this->messenger()->addStatus($this->t('Confirmed. This site publishes with its Quant login again.'));
    $this->getRequest()->getSession()->set(self::REPORTED, TRUE);
  }

  /**
   * Saves the platform and starts the login.
   */
  public function submitLogin(array &$form, FormStateInterface $form_state) {
    // Saved only when the login completes (ProjectSelectForm), so a cancelled
    // login never re-points a working connection.
    $platform = (string) $form_state->getValue('platform');
    $this->getRequest()->getSession()->set(OAuthController::PLATFORM_KEY, Platform::isKnown($platform) ? $platform : Platform::QUANTCDN);
    // A redirect is not rendered, so the CSRF placeholder of a generated URL
    // is never replaced. Add the real token for the route's path.
    $url = Url::fromRoute('quant_api.oauth_connect');
    $url->setOption('query', ['token' => \Drupal::csrfToken()->get($url->getInternalPath())]);
    $form_state->setRedirectUrl($url);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->logOutOnPlatformChange($form_state);
    $settings = $this->savePlatform($form_state)
      ->set('api_tls_disabled', (bool) $form_state->getValue('api_tls_disabled'));

    $new_token = (string) $form_state->getValue('api_token');
    if ($new_token !== '' || !$this->credentials->usesOauth($settings)) {
      $settings->set('api_project', trim((string) $form_state->getValue('api_project')))
        ->set('api_account', trim((string) $form_state->getValue('api_account')));
    }
    if ($new_token !== '') {
      // A typed token replaces a Quant login.
      $this->auth->disconnect();
      $settings->set('auth_method', Credentials::METHOD_TOKEN)->set('api_token', $new_token);
    }
    $settings->save();

    // Clear router cache in case search has been enabled or disabled.
    // @todo Need to clear any search autocomplete blocks in case of config change.
    \Drupal::service('router.builder')->rebuild();

    parent::submitForm($form, $form_state);
  }

  /**
   * Logs out when the platform changes: the login belongs to the old one.
   */
  protected function logOutOnPlatformChange(FormStateInterface $form_state): void {
    $config = $this->config(self::SETTINGS);
    if (!$this->credentials->usesOauth($config) || $config->get('platform') === $form_state->getValue('platform')) {
      return;
    }
    $this->auth->disconnect();
    $this->configFactory()->getEditable(self::SETTINGS)->set('auth_method', Credentials::METHOD_TOKEN)->save();
    $this->messenger()->addWarning($this->t('The platform changed, so this site is logged out of Quant. Log in to Quant on the new platform.'));
  }

  /**
   * The editable settings with the platform and endpoint set.
   *
   * @return \Drupal\Core\Config\Config
   *   The settings, not yet saved.
   */
  protected function savePlatform(FormStateInterface $form_state) {
    $platform = (string) $form_state->getValue('platform');
    $platform = Platform::isKnown($platform) ? $platform : Platform::QUANTCDN;
    $oauth = $this->credentials->usesOauth($this->config(self::SETTINGS));
    return $this->configFactory()->getEditable(self::SETTINGS)
      ->set('platform', $platform)
      ->set('api_endpoint', $oauth ? Platform::apiEndpoint($platform) : $this->resolveEndpoint($form_state));
  }

  /**
   * The platform the form starts on.
   *
   * A dashboard fixed in settings.php decides it, because logins go there
   * whatever the editor picks. Otherwise the saved platform, then the endpoint.
   */
  protected function defaultPlatform(?string $saved, string $endpoint): string {
    $dashboard = (string) Settings::get('quant_api_dashboard_url', '');
    if ($dashboard !== '') {
      return Platform::fromEndpoint($dashboard);
    }
    return Platform::isKnown($saved) ? $saved : Platform::fromEndpoint($endpoint);
  }

}
